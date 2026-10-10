<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProductMultiUnitSupport extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('purchase_unit_id')->nullable()->after('unit_id')->constrained('units')->nullOnDelete();
            $table->decimal('purchase_to_base', 18, 6)->default(1)->after('purchase_unit_id');
            $table->string('unit_pricing_mode', 16)->default('manual')->after('purchase_to_base');
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('name');
            $table->decimal('factor_to_base', 18, 6)->default(1);
            $table->decimal('selling_price', 12, 4)->default(0);
            $table->string('barcode', 64)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'product_id']);
            $table->unique(['product_id', 'name']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_variant_id')->constrained('product_units')->nullOnDelete();
            $table->string('unit_name', 64)->nullable()->after('product_unit_id');
            $table->decimal('unit_factor', 18, 6)->nullable()->after('unit_name');
            $table->decimal('quantity_base', 18, 6)->nullable()->after('quantity');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('unit_name', 64)->nullable()->after('product_variant_id');
            $table->decimal('unit_factor', 18, 6)->nullable()->after('unit_name');
            $table->decimal('quantity_base', 18, 6)->nullable()->after('quantity');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('unit_name', 64)->nullable()->after('type');
            $table->decimal('unit_factor', 18, 6)->nullable()->after('unit_name');
            $table->decimal('quantity_entered', 18, 6)->nullable()->after('unit_factor');
        });
    }

    public function down()
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_name', 'unit_factor', 'quantity_entered']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_name', 'unit_factor', 'quantity_base']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn(['unit_name', 'unit_factor', 'quantity_base']);
        });

        Schema::dropIfExists('product_units');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropColumn(['purchase_to_base', 'unit_pricing_mode']);
        });
    }
}
