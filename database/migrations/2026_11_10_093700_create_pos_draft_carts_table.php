<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePosDraftCartsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('pos_draft_carts')) {
            return;
        }

        Schema::create('pos_draft_carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('revision')->default(0);
            $table->longText('payload')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
            $table->index(['company_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_draft_carts');
    }
}
