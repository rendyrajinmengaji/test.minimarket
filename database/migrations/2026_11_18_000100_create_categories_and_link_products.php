<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $categoryNames = DB::table('products')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        foreach ($categoryNames as $name) {
            if (trim($name) === '') {
                continue;
            }

            if (! DB::table('categories')->where('name', $name)->exists()) {
                DB::table('categories')->insert([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
        });

        foreach (DB::table('categories')->get(['id', 'name']) as $category) {
            DB::table('products')
                ->where('category', $category->name)
                ->update(['category_id' => $category->id]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category')->nullable();
        });

        foreach (DB::table('categories')->get(['id', 'name']) as $category) {
            DB::table('products')
                ->where('category_id', $category->id)
                ->update(['category' => $category->name]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });

        Schema::dropIfExists('categories');
    }
};
