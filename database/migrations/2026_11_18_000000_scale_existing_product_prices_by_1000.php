<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->update([
            'price' => DB::raw('price * 1000'),
        ]);
    }

    public function down(): void
    {
        DB::table('products')->update([
            'price' => DB::raw('price / 1000'),
        ]);
    }
};
