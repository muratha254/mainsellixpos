<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExpandLoyaltyProgram extends Migration
{
    public function up()
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropUnique('loyalty_source_unique');
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->string('status', 32)->default('posted');
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('points_remaining', 15, 2)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unique(
                ['company_id', 'source_type', 'source_id', 'type', 'reference'],
                'loyalty_source_unique'
            );
        });

        DB::table('loyalty_transactions')
            ->where('type', 'earn')
            ->whereNull('points_remaining')
            ->update(['points_remaining' => DB::raw('points')]);

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'loyalty_points_redeemed')) {
                $table->decimal('loyalty_points_redeemed', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('sales', 'loyalty_discount')) {
                $table->decimal('loyalty_discount', 15, 2)->default(0);
            }
        });

        Schema::create('loyalty_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'product_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('loyalty_products');

        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'loyalty_discount')) {
                $table->dropColumn('loyalty_discount');
            }
            if (Schema::hasColumn('sales', 'loyalty_points_redeemed')) {
                $table->dropColumn('loyalty_points_redeemed');
            }
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropUnique('loyalty_source_unique');
            $table->dropColumn(['status', 'sale_id', 'amount', 'points_remaining', 'expires_at']);
            $table->unique(['company_id', 'source_type', 'source_id', 'type'], 'loyalty_source_unique');
        });
    }
}
