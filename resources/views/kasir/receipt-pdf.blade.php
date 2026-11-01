<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk {{ $transaction->transaction_number }}</title>
    <style>
        body { color: #263238; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        .receipt { border: 1px solid #d5dfda; border-radius: 8px; padding: 22px; }
        .header { border-bottom: 1px dashed #b9c9c1; padding-bottom: 14px; text-align: center; }
        .mark { background: #147d66; border-radius: 12px; color: white; display: inline-block; font-size: 20px; font-weight: bold; line-height: 42px; margin-bottom: 8px; width: 42px; }
        .header strong, .header small { display: block; }
        .header strong { font-size: 16px; }
        .header small { color: #718078; letter-spacing: 2px; margin-top: 5px; }
        .meta { border-bottom: 1px dashed #b9c9c1; line-height: 1.8; padding: 12px 0; }
        .line { border-bottom: 1px solid #edf1ef; padding: 9px 0; }
        .line table, .totals { width: 100%; }
        .line td:last-child, .totals td:last-child { text-align: right; white-space: nowrap; }
        .muted { color: #718078; display: block; font-size: 10px; margin-top: 4px; }
        .totals { border-top: 1px dashed #b9c9c1; border-spacing: 0; margin-top: 12px; }
        .totals td { padding: 5px 0; }
        .thanks { color: #718078; margin-top: 18px; text-align: center; }
    </style>
</head>
<body>
    <section class="receipt">
        <header class="header">
            <span class="mark">M</span>
            <strong>MINIMARKET</strong>
            <small>STRUK PEMBELIAN</small>
        </header>
        <div class="meta">
            <div>{{ $transaction->transaction_number }}</div>
            <div>{{ $transaction->created_at->format('d/m/Y H:i') }}</div>
            <div>Kasir: {{ $transaction->user->name }}</div>
        </div>
        @foreach ($transaction->details as $detail)
            <div class="line">
                <table>
                    <tr>
                        <td>
                            {{ $detail->product_name }}
                            <span class="muted">{{ $detail->qty }} x Rp {{ number_format((float) $detail->price, 0, ',', '.') }}</span>
                        </td>
                        <td>Rp {{ number_format((float) $detail->subtotal, 0, ',', '.') }}</td>
                    </tr>
                </table>
            </div>
        @endforeach
        <table class="totals">
            <tr><td>Subtotal</td><td>Rp {{ number_format((float) $transaction->subtotal, 0, ',', '.') }}</td></tr>
            <tr><td>Diskon</td><td>- Rp {{ number_format((float) $transaction->discount, 0, ',', '.') }}</td></tr>
            <tr><td>Pajak / PPN</td><td>Rp {{ number_format((float) $transaction->tax, 0, ',', '.') }}</td></tr>
            <tr><td>Biaya lain</td><td>Rp {{ number_format((float) $transaction->other_fee, 0, ',', '.') }}</td></tr>
            <tr><td><strong>Grand total</strong></td><td><strong>Rp {{ number_format((float) $transaction->grand_total, 0, ',', '.') }}</strong></td></tr>
            <tr><td>Metode bayar</td><td>{{ strtoupper(str_replace('_', ' ', $transaction->payment_method)) }}</td></tr>
            <tr><td>Dibayar</td><td>Rp {{ number_format((float) $transaction->paid_amount, 0, ',', '.') }}</td></tr>
            <tr><td>Kembalian</td><td>Rp {{ number_format((float) $transaction->change_amount, 0, ',', '.') }}</td></tr>
        </table>
        <p class="thanks">Terima kasih telah berbelanja.</p>
    </section>
</body>
</html>
