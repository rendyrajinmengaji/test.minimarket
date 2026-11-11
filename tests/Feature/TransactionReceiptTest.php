<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceiptPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_completed_transaction_is_sent_to_the_thermal_printer(): void
    {
        Event::fake();

        $cashier = User::factory()->create(['role' => 'kasir']);
        $product = Product::factory()->create([
            'price' => 10000,
            'stock' => 5,
            'is_active' => true,
        ]);
        $printer = \Mockery::mock(ReceiptPrinter::class);
        $printer->shouldReceive('print')->once();
        $this->app->instance(ReceiptPrinter::class, $printer);

        $this->actingAs($cashier)
            ->post(route('kasir.transactions.store'), [
                'items' => [['product_id' => $product->id, 'qty' => 2]],
                'discount_percent' => 10,
                'paid_amount' => 20000,
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('kasir.index'))
            ->assertSessionHas('status', 'Transaksi tersimpan dan struk berhasil dicetak.');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $cashier->id,
            'subtotal' => 20000,
            'discount' => 2000,
            'grand_total' => 18000,
            'paid_amount' => 20000,
            'change_amount' => 2000,
        ]);
        $this->assertDatabaseHas('transaction_details', [
            'product_id' => $product->id,
            'qty' => 2,
            'subtotal' => 20000,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
        ]);
    }

    public function test_printer_failure_does_not_undo_a_saved_transaction(): void
    {
        Event::fake();

        $cashier = User::factory()->create(['role' => 'kasir']);
        $product = Product::factory()->create([
            'price' => 10000,
            'stock' => 5,
            'is_active' => true,
        ]);
        $printer = \Mockery::mock(ReceiptPrinter::class);
        $printer->shouldReceive('print')->once()->andThrow(new \RuntimeException('Printer unavailable'));
        $this->app->instance(ReceiptPrinter::class, $printer);

        $this->actingAs($cashier)
            ->post(route('kasir.transactions.store'), [
                'items' => [['product_id' => $product->id, 'qty' => 1]],
                'paid_amount' => 10000,
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('kasir.index'))
            ->assertSessionHas('receiptPrintError');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $cashier->id,
            'grand_total' => 10000,
        ]);
    }

    public function test_cashier_can_reprint_their_transaction_on_the_thermal_printer(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir']);
        $transaction = $this->createTransactionFor($cashier);
        $printer = \Mockery::mock(ReceiptPrinter::class);
        $printer->shouldReceive('print')->once();
        $this->app->instance(ReceiptPrinter::class, $printer);

        $this->actingAs($cashier)
            ->post(route('kasir.transactions.print', $transaction))
            ->assertRedirect()
            ->assertSessionHas('status', 'Struk berhasil dicetak.');
    }

    public function test_cashier_cannot_reprint_another_cashiers_transaction(): void
    {
        $owner = User::factory()->create(['role' => 'kasir']);
        $otherCashier = User::factory()->create(['role' => 'kasir']);
        $transaction = $this->createTransactionFor($owner);

        $this->actingAs($otherCashier)
            ->post(route('kasir.transactions.print', $transaction))
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
