<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\KasirController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\LaporanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return match (auth()->user()->role) {
            'admin' => redirect()->route('admin.dashboard'),
            'kasir' => redirect()->route('kasir.index'),
            default => abort(403, 'Anda tidak memiliki akses.'),
        };
    })->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    });

    Route::middleware('role:admin')->group(function () {
        Route::resource('products', ProductController::class);
    });

    Route::middleware('role:kasir')->prefix('kasir')->name('kasir.')->group(function () {
        Route::get('/dashboard', [KasirController::class, 'dashboard'])->name('dashboard');
        Route::get('/', [TransactionController::class, 'index'])->name('index');
        Route::get('/midtrans/{transaction}/return', [TransactionController::class, 'midtransReturn'])->name('midtrans.return');
        Route::get('/midtrans/{transaction}/resume', [TransactionController::class, 'resumeMidtrans'])->name('midtrans.resume');
        Route::post('/transaksi', [TransactionController::class, 'store'])->name('transactions.store');
        Route::get('/transaksi/{transaction}/struk', [TransactionController::class, 'downloadReceipt'])->name('transactions.receipt');
        Route::post('/transaksi/{transaction}/cetak', [ReceiptController::class, 'print'])->name('transactions.print');
    });

    Route::get('/laporan-pegawai', [LaporanController::class, 'generatePDF'])->name('laporan.pegawai');
});

require __DIR__.'/auth.php';
