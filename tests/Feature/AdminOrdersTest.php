<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_orders_created_by_cashiers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create(['name' => 'Kasir Utama', 'role' => 'kasir']);
        $product = Product::factory()->create(['name' => 'Beras']);
        $transaction = Transaction::create([
            'transaction_number' => 'TRX-ADMIN-001',
            'user_id' => $cashier->id,
            'subtotal' => 25000,
            'discount' => 0,
            'tax' => 0,
            'other_fee' => 0,
            'grand_total' => 25000,
            'paid_amount' => 25000,
            'change_amount' => 0,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 25000,
            'qty' => 1,
            'discount' => 0,
            'subtotal' => 25000,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Pesanan dari Kasir')
            ->assertSee('TRX-ADMIN-001')
            ->assertSee('Kasir Utama')
            ->assertSee('Beras')
            ->assertSee('Rp 25.000');
    }

    public function test_cashier_cannot_view_admin_orders(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir']);

        $this->actingAs($cashier)
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    }
}
