<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProductBaseWholesalePrice extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('products', 'base_wholesale_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('base_wholesale_price', 12, 4)->nullable()->after('wholesale_price');
            });
        }
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('base_wholesale_price');
        });
    }
}
