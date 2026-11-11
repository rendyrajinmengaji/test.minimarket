<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceiptPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => 'sandbox-server-key',
            'services.midtrans.snap_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
            'services.midtrans.api_url' => 'https://api.sandbox.midtrans.com',
        ]);
    }

    public function test_cashier_page_uses_a_versioned_cart_script(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir']);

        $this->actingAs($cashier)
            ->get(route('kasir.index'))
            ->assertOk()
            ->assertSee('id="paidBlock"', false)
            ->assertSee('chasier.js?v='.filemtime(public_path('js/chasier.js')), false);
    }

    public function test_cashier_can_start_a_midtrans_snap_payment(): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
            ], 201),
        ]);

        $cashier = User::factory()->create(['role' => 'kasir']);
        $product = Product::factory()->create([
            'price' => 10000,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($cashier)
            ->post(route('kasir.transactions.store'), [
                'items' => [['product_id' => $product->id, 'qty' => 1]],
                'discount_percent' => 0,
                'discount_amount' => 0,
                'tax_percent' => 0,
                'other_fee' => 0,
                'payment_method' => 'midtrans',
            ])
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $cashier->id,
            'payment_method' => 'midtrans',
            'status' => 'pending',
            'grand_total' => 10000,
            'midtrans_token' => 'snap-token',
            'midtrans_redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token',
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 4]);
    }

    public function test_cashier_can_create_a_new_order_without_checking_a_pending_midtrans_payment(): void
    {
        Http::fake();
        Event::fake();

        $cashier = User::factory()->create(['role' => 'kasir']);
        $pendingProduct = Product::factory()->create([
            'price' => 10000,
            'stock' => 4,
            'is_active' => true,
        ]);
        $pendingTransaction = $this->createPendingTransaction($cashier, $pendingProduct);
        $newProduct = Product::factory()->create([
            'price' => 5000,
            'stock' => 3,
            'is_active' => true,
        ]);
        $printer = \Mockery::mock(ReceiptPrinter::class);
        $printer->shouldReceive('print')->once();
        $this->app->instance(ReceiptPrinter::class, $printer);

        $this->actingAs($cashier)
            ->post(route('kasir.transactions.store'), [
                'items' => [['product_id' => $newProduct->id, 'qty' => 1]],
                'paid_amount' => 5000,
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('kasir.index'))
            ->assertSessionHas('status', 'Transaksi tersimpan dan struk berhasil dicetak.');

        Http::assertNothingSent();
        $this->assertDatabaseHas('transactions', [
            'user_id' => $cashier->id,
            'payment_method' => 'cash',
            'status' => 'completed',
            'grand_total' => 5000,
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $pendingTransaction->id,
            'payment_method' => 'midtrans',
            'status' => 'pending',
        ]);
    }

    public function test_product_factory_sets_dummy_product_price_to_one_rupiah(): void
    {
        $product = Product::factory()->make();

        $this->assertSame('1.00', $product->price);
    }

    public function test_returning_from_snap_with_pending_payment_can_open_the_existing_checkout(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'transaction_status' => 'pending',
                'fraud_status' => 'accept',
                'gross_amount' => '10000.00',
            ]),
        ]);

        $cashier = User::factory()->create(['role' => 'kasir']);
        $transaction = $this->createPendingTransaction($cashier);

        $this->actingAs($cashier)
            ->get(route('kasir.midtrans.return', $transaction))
            ->assertRedirect(route('kasir.index'))
            ->assertSessionHas('midtransReturnTransactionId', $transaction->id);

        $this->actingAs($cashier)
            ->get(route('kasir.index'))
            ->assertOk()
            ->assertDontSee('Buka pembayaran Midtrans');

        $this->actingAs($cashier)
            ->get(route('kasir.midtrans.resume', $transaction))
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v2/vtweb/existing-token');
    }

    public function test_cancelled_midtrans_payment_restores_stock_and_cart(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'transaction_status' => 'cancel',
                'fraud_status' => 'accept',
                'gross_amount' => '11100.00',
            ]),
        ]);

        $cashier = User::factory()->create(['role' => 'kasir']);
        $product = Product::factory()->create([
            'price' => 10000,
            'stock' => 4,
            'is_active' => true,
        ]);
        $transaction = $this->createPendingTransaction($cashier, $product, 11100);

        $this->actingAs($cashier)
            ->get(route('kasir.midtrans.return', $transaction))
            ->assertRedirect(route('kasir.index'))
            ->assertSessionHas('midtransReturnTransactionId', $transaction->id);

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'failed']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 5]);

        $this->actingAs($cashier)
            ->get(route('kasir.index'))
            ->assertOk()
            ->assertViewHas('returnedMidtrans', fn ($returned) => $returned->is($transaction))
            ->assertViewHas('initialItems', [
                ['product_id' => $product->id, 'qty' => 1],
            ]);
    }

    public function test_midtrans_not_found_response_with_http_200_restores_the_cart(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/status' => Http::response([
                'status_code' => '404',
                'status_message' => "Transaction doesn't exist.",
                'id' => 'TRX-MIDTRANS-OLD',
            ], 200),
        ]);

        $cashier = User::factory()->create(['role' => 'kasir']);
        $product = Product::factory()->create([
            'price' => 10000,
            'stock' => 4,
            'is_active' => true,
        ]);
        $transaction = $this->createPendingTransaction($cashier, $product);

        $this->actingAs($cashier)
            ->get(route('kasir.midtrans.return', $transaction))
            ->assertRedirect(route('kasir.index'))
            ->assertSessionHas('midtransReturnTransactionId', $transaction->id);

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'failed']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 5]);

        $this->actingAs($cashier)
            ->get(route('kasir.index'))
            ->assertOk()
            ->assertViewHas('returnedMidtrans', fn ($returned) => $returned->is($transaction))
            ->assertViewHas('initialItems', [
                ['product_id' => $product->id, 'qty' => 1],
            ]);
    }

    private function createPendingTransaction(
        User $cashier,
        ?Product $product = null,
        int $total = 10000
    ): Transaction {
        $product ??= Product::factory()->create([
            'price' => $total,
            'stock' => 4,
            'is_active' => true,
        ]);
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-MIDTRANS-'.$cashier->id.'-'.$product->id,
            'user_id' => $cashier->id,
            'subtotal' => $total,
            'discount' => 0,
            'tax' => $total === 11100 ? 1100 : 0,
            'other_fee' => 0,
            'grand_total' => $total,
            'paid_amount' => 0,
            'change_amount' => 0,
            'payment_method' => 'midtrans',
            'status' => 'pending',
            'midtrans_token' => 'existing-token',
            'midtrans_redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/existing-token',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'product_code' => $product->product_code,
            'product_name' => $product->name,
            'price' => $product->price,
            'qty' => 1,
            'discount' => 0,
            'subtotal' => $product->price,
        ]);

        return $transaction;
    }
}
