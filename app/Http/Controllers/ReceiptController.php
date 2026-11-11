<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\ReceiptPrinter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ReceiptController extends Controller
{
    public function print(Transaction $transaction, ReceiptPrinter $printer): RedirectResponse
    {
        abort_unless($transaction->user_id === auth()->id(), 403);

        try {
            $printer->print($transaction->load('details'));
        } catch (Exception $exception) {
            Log::error('Gagal mencetak ulang struk transaksi.', [
                'transaction_id' => $transaction->id,
                'exception' => $exception,
            ]);

            return back()->with('receiptPrintError', 'Struk gagal dicetak. Periksa koneksi printer dan coba lagi.');
        }

        return back()->with('status', 'Struk berhasil dicetak.');
    }
}
