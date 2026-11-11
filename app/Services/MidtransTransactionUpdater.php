<?php

namespace App\Services;

use App\Events\OrderPlaced;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class MidtransTransactionUpdater
{
    public function apply(Transaction $transaction, string $status, ?string $fraudStatus, string $grossAmount): void
    {
        abort_unless(
            (int) round((float) $transaction->grand_total * 100)
                === (int) round((float) $grossAmount * 100),
            403
        );

        $completed = false;
        DB::transaction(function () use ($transaction, $status, $fraudStatus, &$completed) {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($transaction->status !== 'pending') {
                return;
            }

            if ($status === 'settlement' || ($status === 'capture' && $fraudStatus === 'accept')) {
                $transaction->update([
                    'status' => 'completed',
                    'paid_amount' => $transaction->grand_total,
                    'change_amount' => 0,
                ]);
                $completed = true;

                return;
            }

            if (in_array($status, ['expire', 'cancel', 'deny'], true)) {
                $transaction->load('details');
                foreach ($transaction->details as $detail) {
                    Product::query()->whereKey($detail->product_id)
                        ->lockForUpdate()
                        ->increment('stock', $detail->qty);
                }
                $transaction->update(['status' => 'failed']);
            }
        });

        if ($completed) {
            $transaction->refresh()->load('user');
            event(new OrderPlaced($transaction, $transaction->user));
        }
    }

    public function expireMissing(Transaction $transaction): void
    {
        $this->markExpired($transaction);
    }

    private function markExpired(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($transaction->status !== 'pending') {
                return;
            }

            $transaction->load('details');
            foreach ($transaction->details as $detail) {
                Product::query()->whereKey($detail->product_id)
                    ->lockForUpdate()
                    ->increment('stock', $detail->qty);
            }
            $transaction->update(['status' => 'failed']);
        });
    }
}
