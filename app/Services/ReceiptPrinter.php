<?php

namespace App\Services;

use App\Models\Transaction;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;
use RuntimeException;

class ReceiptPrinter
{
    public function print(Transaction $transaction): void
    {
        $connector = match (config('receipt.connection')) {
            'network' => new NetworkPrintConnector(
                config('receipt.host'),
                config('receipt.port')
            ),
            'windows' => new WindowsPrintConnector(config('receipt.windows_printer')),
            default => throw new RuntimeException('Jenis koneksi printer struk tidak didukung.'),
        };

        $printer = new Printer($connector);

        try {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text(config('receipt.store_name')."\n");
            $printer->text("STRUK PEMBELIAN\n");
            $printer->text($transaction->transaction_number."\n");
            $printer->text($transaction->created_at->format('d/m/Y H:i:s')."\n");
            $printer->text(str_repeat('-', 32)."\n");

            $printer->setJustification(Printer::JUSTIFY_LEFT);

            foreach ($transaction->details as $detail) {
                $printer->text($detail->product_name."\n");
                $printer->text(sprintf(
                    '%d x Rp %s    Rp %s'."\n",
                    $detail->qty,
                    $this->formatAmount((float) $detail->price),
                    $this->formatAmount((float) $detail->subtotal)
                ));
            }

            $printer->text(str_repeat('-', 32)."\n");
            $printer->text($this->formatRow('Subtotal', (float) $transaction->subtotal));
            $printer->text($this->formatRow('Diskon', (float) $transaction->discount));
            $printer->text($this->formatRow('Pajak', (float) $transaction->tax));
            $printer->text($this->formatRow('Biaya lain', (float) $transaction->other_fee));
            $printer->text($this->formatRow('Total', (float) $transaction->grand_total));
            $printer->text($this->formatRow('Dibayar', (float) $transaction->paid_amount));
            $printer->text($this->formatRow('Kembalian', (float) $transaction->change_amount));
            $printer->feed(1);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Terima kasih telah berbelanja\n");
            $printer->feed(2);
            $printer->cut();
        } finally {
            $printer->close();
        }
    }

    private function formatRow(string $label, float $amount): string
    {
        return sprintf("%s: Rp %s\n", $label, $this->formatAmount($amount));
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }
}
