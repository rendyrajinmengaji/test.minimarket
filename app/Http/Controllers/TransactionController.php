<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\MidtransApiException;
use App\Services\MidtransService;
use App\Services\MidtransTransactionUpdater;
use App\Services\ReceiptPrinter;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransactionController extends Controller
{
    public function downloadReceipt(Transaction $transaction)
    {
        abort_unless($transaction->user_id === auth()->id(), 403);

        $transaction->load(['details', 'user']);

        return Pdf::loadView('kasir.receipt-pdf', compact('transaction'))
            ->setPaper('a5', 'portrait')
            ->download('struk-'.$transaction->transaction_number.'.pdf');
    }

    public function index(Request $request): View
    {
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'product_code', 'barcode', 'price', 'stock']);

        $returnedMidtrans = null;
        if ($request->session()->has('midtransReturnTransactionId')) {
            $returnedMidtrans = Transaction::query()
                ->whereKey($request->session()->pull('midtransReturnTransactionId'))
                ->where('user_id', $request->user()->id)
                ->where('payment_method', 'midtrans')
                ->whereIn('status', ['pending', 'failed'])
                ->with('details')
                ->first();
        }

        $initialItems = $returnedMidtrans?->status === 'failed'
            ? $returnedMidtrans->details->map(fn ($detail) => [
                'product_id' => $detail->product_id,
                'qty' => $detail->qty,
            ])->all()
            : [];
        $taxableAmount = $returnedMidtrans
            ? (float) $returnedMidtrans->subtotal - (float) $returnedMidtrans->discount
            : 0;
        $initialTotals = $returnedMidtrans?->status === 'failed'
            ? [
                'discountPercent' => 0,
                'discountAmount' => (float) $returnedMidtrans->discount,
                'taxPercent' => $taxableAmount > 0
                    ? (float) $returnedMidtrans->tax / $taxableAmount * 100
                    : 0,
                'otherFee' => (float) $returnedMidtrans->other_fee,
            ]
            : null;

        $nextTransactionId = (int) Transaction::max('id') + 1;
        $transactionNumber = 'TRX-'.now()->format('Ymd').'-'.str_pad((string) $nextTransactionId, 3, '0', STR_PAD_LEFT);

        return view('kasir.index', compact(
            'products',
            'transactionNumber',
            'returnedMidtrans',
            'initialItems',
            'initialTotals'
        ));
    }

    public function midtransReturn(Transaction $transaction): RedirectResponse
    {
        abort_unless(
            $transaction->user_id === auth()->id()
                && $transaction->payment_method === 'midtrans',
            403
        );

        if ($transaction->status === 'pending') {
            try {
                $this->refreshMidtransStatus($transaction);
                $transaction->refresh();
            } catch (MidtransApiException $exception) {
                if ($exception->getCode() === 404) {
                    app(MidtransTransactionUpdater::class)->expireMissing($transaction);
                    $transaction->refresh();
                } else {
                    Log::warning('Tidak dapat memverifikasi status pembayaran Midtrans saat kembali ke kasir.', [
                        'transaction_id' => $transaction->id,
                        'http_status' => $exception->getCode(),
                    ]);

                    return redirect()
                        ->route('kasir.index')
                        ->withErrors(['payment_method' => 'Status pembayaran belum dapat diverifikasi. Keranjang tetap disimpan; coba lagi sebentar.']);
                }
            }
        }

        if ($transaction->status === 'completed') {
            return redirect()
                ->route('kasir.index')
                ->with('completedTransaction', $transaction->load('details'))
                ->with('status', 'Pembayaran berhasil. Struk transaksi siap.');
        }

        return redirect()->route('kasir.index')->with('midtransReturnTransactionId', $transaction->id);
    }

    public function resumeMidtrans(Transaction $transaction): RedirectResponse
    {
        abort_unless(
            $transaction->user_id === auth()->id()
                && $transaction->payment_method === 'midtrans',
            403
        );

        if ($transaction->status === 'pending') {
            try {
                $this->refreshMidtransStatus($transaction);
                $transaction->refresh();
            } catch (MidtransApiException $exception) {
                if ($exception->getCode() === 404) {
                    app(MidtransTransactionUpdater::class)->expireMissing($transaction);
                    $transaction->refresh();
                } else {
                    Log::warning('Tidak dapat memeriksa pembayaran Midtrans sebelum dibuka kembali.', [
                        'transaction_id' => $transaction->id,
                        'http_status' => $exception->getCode(),
                    ]);

                    return redirect()
                        ->route('kasir.index')
                        ->withErrors(['payment_method' => 'Status pembayaran belum dapat diverifikasi. Coba lagi sebentar lagi.']);
                }
            }
        }

        if ($transaction->status === 'completed') {
            return redirect()
                ->route('kasir.index')
                ->with('completedTransaction', $transaction->load('details'))
                ->with('status', 'Pembayaran berhasil. Struk transaksi siap.');
        }

        if ($transaction->status === 'pending' && $transaction->midtrans_redirect_url) {
            return redirect()->away($transaction->midtrans_redirect_url);
        }

        return redirect()
            ->route('kasir.index')
            ->with('midtransReturnTransactionId', $transaction->id)
            ->with('status', 'Pembayaran Midtrans tidak selesai. Keranjang telah dipulihkan.');
    }

    public function store(Request $request, ReceiptPrinter $printer): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'other_fee' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['required_unless:payment_method,midtrans', 'nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:cash,qris,debit,credit,e_wallet,transfer,midtrans'],
        ], [
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Tambahkan minimal satu produk.',
            'paid_amount.required' => 'Masukkan jumlah uang dibayar.',
        ]);

        $isMidtrans = $validated['payment_method'] === 'midtrans';
        if ($isMidtrans && ! config('services.midtrans.server_key')) {
            throw ValidationException::withMessages([
                'payment_method' => 'MIDTRANS_SERVER_KEY belum dikonfigurasi.',
            ]);
        }

        $transaction = DB::transaction(function () use ($validated, $isMidtrans) {
            $lines = [];
            $subtotal = 0;

            foreach ($validated['items'] as $item) {
                $product = Product::query()->whereKey($item['product_id'])->lockForUpdate()->first();

                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => 'Salah satu produk sudah tidak aktif. Muat ulang halaman dan coba lagi.',
                    ]);
                }

                if ($product->stock < $item['qty']) {
                    throw ValidationException::withMessages([
                        'items' => "Stok {$product->name} tidak mencukupi. Tersedia {$product->stock}.",
                    ]);
                }

                $lineSubtotal = (float) $product->price * (int) $item['qty'];
                $subtotal += $lineSubtotal;
                $lines[] = [
                    'product' => $product,
                    'qty' => (int) $item['qty'],
                    'subtotal' => $lineSubtotal,
                ];
            }

            $discountPercent = (float) ($validated['discount_percent'] ?? 0);
            $fixedDiscount = (float) ($validated['discount_amount'] ?? 0);
            $discount = min($subtotal, ($subtotal * $discountPercent / 100) + $fixedDiscount);
            $tax = max(0, $subtotal - $discount) * (float) ($validated['tax_percent'] ?? 0) / 100;
            $otherFee = (float) ($validated['other_fee'] ?? 0);
            $grandTotal = $subtotal - $discount + $tax + $otherFee;
            if ($isMidtrans) {
                $grandTotal = round($grandTotal);
            }
            $paidAmount = $isMidtrans ? 0 : (float) $validated['paid_amount'];

            if (! $isMidtrans && $paidAmount < $grandTotal) {
                throw ValidationException::withMessages([
                    'paid_amount' => 'Uang dibayar masih kurang dari total transaksi.',
                ]);
            }

            $transaction = Transaction::create([
                'transaction_number' => 'TRX-'.now()->format('Ymd').'-'.Str::uuid(),
                'user_id' => auth()->id(),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'other_fee' => $otherFee,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'change_amount' => $isMidtrans ? 0 : $paidAmount - $grandTotal,
                'payment_method' => $validated['payment_method'],
                'status' => $isMidtrans ? 'pending' : 'completed',
            ]);

            $transaction->update([
                'transaction_number' => 'TRX-'.$transaction->created_at->format('Ymd').'-'.str_pad((string) $transaction->id, 3, '0', STR_PAD_LEFT),
            ]);

            foreach ($lines as $line) {
                $product = $line['product'];
                $transaction->details()->create([
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'product_name' => $product->name,
                    'price' => $product->price,
                    'qty' => $line['qty'],
                    'discount' => 0,
                    'subtotal' => $line['subtotal'],
                ]);
                $product->decrement('stock', $line['qty']);
            }

            return $transaction;
        }, 3);

        if ($isMidtrans) {
            return $this->createMidtransCheckout($transaction, $request);
        }

        $receiptPrintError = null;

        try {
            $printer->print($transaction->load('details'));
        } catch (Exception $exception) {
            Log::error('Transaksi tersimpan, tetapi struk gagal dicetak.', [
                'transaction_id' => $transaction->id,
                'exception' => $exception,
            ]);
            $receiptPrintError = 'Transaksi tersimpan, tetapi struk gagal dicetak. Periksa koneksi printer.';
        }

        event(new OrderPlaced($transaction, $request->user()));

        $response = redirect()
            ->route('kasir.index')
            ->with('completedTransaction', $transaction->load('details'));

        return $receiptPrintError
            ? $response->with('receiptPrintError', $receiptPrintError)
            : $response->with('status', 'Transaksi tersimpan dan struk berhasil dicetak.');
    }

    private function refreshMidtransStatus(Transaction $transaction): void
    {
        $status = app(MidtransService::class)->getTransactionStatus($transaction->transaction_number);
        app(MidtransTransactionUpdater::class)->apply(
            $transaction,
            $status['transaction_status'],
            $status['fraud_status'] ?? null,
            (string) $status['gross_amount']
        );
    }

    private function createMidtransCheckout(Transaction $transaction, Request $request): RedirectResponse
    {
        try {
            $snap = app(MidtransService::class)->createSnapTransaction($transaction);
        } catch (MidtransApiException $exception) {
            $transaction->load('details');
            DB::transaction(function () use ($transaction) {
                foreach ($transaction->details as $detail) {
                    Product::query()->whereKey($detail->product_id)
                        ->lockForUpdate()
                        ->increment('stock', $detail->qty);
                }
                $transaction->update(['status' => 'failed']);
            });

            Log::warning('Midtrans menolak pembuatan pembayaran.', [
                'transaction_id' => $transaction->id,
                'http_status' => $exception->getCode(),
            ]);

            return redirect()
                ->route('kasir.index')
                ->withInput($request->except('_token'))
                ->withErrors(['payment_method' => 'Midtrans menolak pembayaran. Periksa key dan mode Sandbox.']);
        }

        $transaction->update([
            'midtrans_token' => $snap['token'],
            'midtrans_redirect_url' => $snap['redirect_url'],
        ]);

        return redirect()->away($snap['redirect_url']);
    }
}
