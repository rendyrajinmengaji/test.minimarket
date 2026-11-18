<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_categories_and_product_counts_in_filament(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Sembako']);
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($admin)
            ->get(route('filament.admin.resources.categories.index'))
            ->assertOk()
            ->assertSee('Sembako')
            ->assertSee('Jumlah produk')
            ->assertSee('1');
    }

    public function test_admin_can_assign_an_existing_category_to_a_product(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['name' => 'Minuman']);

        $this->actingAs($admin)
            ->post(route('products.store'), [
                'name' => 'Air Mineral',
                'category_id' => $category->id,
                'price' => 5000,
                'stock' => 10,
                'is_active' => 1,
            ])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Air Mineral',
            'category_id' => $category->id,
        ]);
    }

    public function test_cashier_cannot_access_the_filament_category_resource(): void
    {
        $cashier = User::factory()->create(['role' => 'kasir']);

        $this->actingAs($cashier)
            ->get(route('filament.admin.resources.categories.index'))
            ->assertForbidden();
    }
}
