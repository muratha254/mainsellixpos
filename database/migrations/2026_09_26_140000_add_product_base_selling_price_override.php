<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProductBaseSellingPriceOverride extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('products', 'base_selling_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('base_selling_price', 12, 4)->nullable()->after('selling_price');
            });
        }
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('base_selling_price');
        });
    }
}
