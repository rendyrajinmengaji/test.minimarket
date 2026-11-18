@extends('layouts.admin')

@section('title', 'Pesanan | Minimarket')

@section('content')
    <div class="page-header">
        <div class="page-block">
            <div class="row align-items-center">
                <div class="col-md-12">
                    <ul class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Beranda</a></li>
                        <li class="breadcrumb-item" aria-current="page">Pesanan</li>
                    </ul>
                </div>
                <div class="col-md-12">
                    <div class="page-header-title"><h2 class="mb-0">Pesanan dari Kasir</h2></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>No. Transaksi</th>
                            <th>Waktu</th>
                            <th>Kasir</th>
                            <th>Produk</th>
                            <th>Pembayaran</th>
                            <th>Status</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions as $transaction)
                            <tr>
                                <td class="fw-semibold">{{ $transaction->transaction_number }}</td>
                                <td>{{ $transaction->created_at->format('d/m/Y H:i') }}</td>
                                <td>{{ $transaction->user?->name ?? '-' }}</td>
                                <td>
                                    @foreach ($transaction->details as $detail)
                                        <div>{{ $detail->product_name }} <span class="text-muted">× {{ $detail->qty }}</span></div>
                                    @endforeach
                                </td>
                                <td>{{ str($transaction->payment_method)->replace('_', ' ')->title() }}</td>
                                <td>
                                    @php
                                        $statusClass = match ($transaction->status) {
                                            'completed' => 'bg-light-success text-success',
                                            'pending' => 'bg-light-warning text-warning',
                                            'failed' => 'bg-light-danger text-danger',
                                            default => 'bg-light-secondary text-secondary',
                                        };
                                    @endphp
                                    <span class="badge {{ $statusClass }}">{{ ucfirst($transaction->status) }}</span>
                                </td>
                                <td class="text-end">Rp {{ number_format((float) $transaction->grand_total, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-4 text-center text-muted">Belum ada pesanan dari kasir.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($transactions->hasPages())
                <div class="mt-3">{{ $transactions->links() }}</div>
            @endif
        </div>
    </div>
@endsection
