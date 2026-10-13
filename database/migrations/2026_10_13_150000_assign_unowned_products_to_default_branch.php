<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AssignUnownedProductsToDefaultBranch extends Migration
{
    public function up()
    {
        $products = DB::table('products')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product_branch_stock')
                    ->whereColumn('product_branch_stock.product_id', 'products.id');
            })
            ->get(['id', 'company_id', 'purchase_price']);

        $now = now();

        foreach ($products as $product) {
            $branchId = DB::table('branches')
                ->where('company_id', $product->company_id)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if (! $branchId) {
                continue;
            }

            DB::table('product_branch_stock')->insert([
                'company_id' => $product->company_id,
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_variant_id' => 0,
                'quantity' => 0,
                'average_cost' => $product->purchase_price ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        // Ownership rows are stock records. Leave them in place.
    }
}
