<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOfflineSyncKeysTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('offline_sync_keys')) {
            return;
        }

        Schema::create('offline_sync_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_key', 64);
            $table->string('resource', 32);
            $table->unsignedBigInteger('resource_id');
            $table->timestamps();

            $table->unique(['company_id', 'client_key']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('offline_sync_keys');
    }
}
