<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MidtransService
{
    public function createSnapTransaction(Transaction $transaction): array
    {
        $serverKey = config('services.midtrans.server_key');
        if (! $serverKey) {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->withBasicAuth($serverKey, '')
            ->connectTimeout(5)
            ->timeout(15)
            ->post(config('services.midtrans.snap_url'), [
                'transaction_details' => [
                    'order_id' => $transaction->transaction_number,
                    'gross_amount' => (int) $transaction->grand_total,
                ],
                'customer_details' => [
                    'first_name' => $transaction->user->name,
                    'email' => $transaction->user->email,
                ],
                'callbacks' => [
                    'finish' => route('kasir.midtrans.return', $transaction),
                    'unfinish' => route('kasir.midtrans.return', $transaction),
                    'error' => route('kasir.midtrans.return', $transaction),
                ],
            ]);

        if (! $response->successful()) {
            throw new MidtransApiException('Midtrans rejected the Snap request.', $response->status());
        }

        $token = $response->json('token');
        $redirectUrl = $response->json('redirect_url');
        if (! is_string($token) || ! is_string($redirectUrl)) {
            throw new MidtransApiException('Midtrans returned an invalid Snap response.', $response->status());
        }

        return [
            'token' => $token,
            'redirect_url' => $redirectUrl,
        ];
    }

    public function getTransactionStatus(string $orderId): array
    {
        $serverKey = config('services.midtrans.server_key');
        if (! $serverKey) {
            throw new RuntimeException('MIDTRANS_SERVER_KEY belum dikonfigurasi.');
        }

        $response = Http::acceptJson()
            ->withBasicAuth($serverKey, '')
            ->connectTimeout(5)
            ->timeout(15)
            ->get(rtrim(config('services.midtrans.api_url'), '/').'/v2/'.rawurlencode($orderId).'/status');

        $status = $response->json();
        $apiStatusCode = is_array($status) && isset($status['status_code'])
            ? filter_var($status['status_code'], FILTER_VALIDATE_INT)
            : false;

        if (! $response->successful()) {
            throw new MidtransApiException(
                'Midtrans status lookup failed.',
                $apiStatusCode ?: $response->status()
            );
        }

        if (! is_array($status) || ! isset($status['transaction_status'], $status['gross_amount'])) {
            throw new MidtransApiException(
                'Midtrans returned an invalid status response.',
                $apiStatusCode && $apiStatusCode !== 200 ? $apiStatusCode : $response->status()
            );
        }

        return $status;
    }
}
