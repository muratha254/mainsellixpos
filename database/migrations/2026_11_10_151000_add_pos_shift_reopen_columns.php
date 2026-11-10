<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPosShiftReopenColumns extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('pos_shifts') || Schema::hasColumn('pos_shifts', 'reopen_requested_at')) {
            return;
        }

        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dateTime('reopen_requested_at')->nullable();
            $table->unsignedBigInteger('reopen_requested_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
        });
    }

    public function down()
    {
        if (! Schema::hasTable('pos_shifts') || ! Schema::hasColumn('pos_shifts', 'reopen_requested_at')) {
            return;
        }

        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'reopen_requested_at',
                'reopen_requested_by',
                'reopened_at',
                'reopened_by',
            ]);
        });
    }
}
