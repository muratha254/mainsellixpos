<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDynamicProductVariations extends Migration
{
    public function up()
    {
        Schema::table('product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variants', 'name')) {
                $table->string('name')->nullable()->after('barcode');
            }
            if (! Schema::hasColumn('product_variants', 'options')) {
                $table->json('options')->nullable()->after('name');
            }
            if (! Schema::hasColumn('product_variants', 'signature')) {
                $table->string('signature', 191)->nullable()->after('options');
            }
            if (! Schema::hasColumn('product_variants', 'reorder_level')) {
                $table->decimal('reorder_level', 15, 4)->nullable()->after('selling_price');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['product_id', 'signature'], 'product_variants_signature_unique');
            $table->index(['company_id', 'barcode'], 'product_variants_barcode_index');
            $table->index(['company_id', 'sku'], 'product_variants_sku_index');
        });

        if (! Schema::hasTable('product_variant_attributes')) {
            Schema::create('product_variant_attributes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['product_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('product_variant_attribute_values')) {
            Schema::create('product_variant_attribute_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attribute_id')->constrained('product_variant_attributes')->cascadeOnDelete();
                $table->string('value', 80);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('product_variant_attribute_values');
        Schema::dropIfExists('product_variant_attributes');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('product_variants_signature_unique');
            $table->dropIndex('product_variants_barcode_index');
            $table->dropIndex('product_variants_sku_index');
            $table->dropColumn(['name', 'options', 'signature', 'reorder_level']);
        });
    }
}
