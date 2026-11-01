<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_download_their_transaction_receipt_as_pdf(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir']);
        $transaction = $this->createTransactionFor($cashier);

        $response = $this->actingAs($cashier)
            ->get(route('kasir.transactions.receipt', $transaction));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_cashier_cannot_download_another_cashiers_receipt(): void
    {
        $owner = User::factory()->create(['role' => 'kasir']);
        $otherCashier = User::factory()->create(['role' => 'kasir']);
        $transaction = $this->createTransactionFor($owner);

        $this->actingAs($otherCashier)
            ->get(route('kasir.transactions.receipt', $transaction))
            ->assertForbidden();
    }

    private function createTransactionFor(User $cashier): Transaction
    {
        $product = Product::factory()->create();
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-TEST-'.$cashier->id,
            'user_id' => $cashier->id,
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 0,
            'other_fee' => 0,
            'grand_total' => 10000,
            'paid_amount' => 10000,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 10000,
            'qty' => 1,
            'discount' => 0,
            'subtotal' => 10000,
        ]);

        return $transaction;
    }
}
