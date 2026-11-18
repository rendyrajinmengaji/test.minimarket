<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard()
    {
        return view('admin.dashboard');
    }

    public function orders(): View
    {
        $transactions = Transaction::query()
            ->with([
                'user:id,name',
                'details:id,transaction_id,product_name,qty',
            ])
            ->latest()
            ->paginate(15);

        return view('admin.orders', compact('transactions'));
    }
}
