<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPriceFormattingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_prices_use_thousands_separator_without_decimal_places(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['price' => 12500]);

        $this->actingAs($admin)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Rp 12.500')
            ->assertDontSee('Rp 12.500,00');

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Rp 12.500')
            ->assertDontSee('Rp 12.500,00');
    }

    public function test_existing_product_prices_are_scaled_by_the_database_migration(): void
    {
        $product = Product::factory()->create(['price' => 1]);
        $migration = require database_path('migrations/2026_11_18_000000_scale_existing_product_prices_by_1000.php');

        $migration->up();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'price' => 1000,
        ]);
    }
}
