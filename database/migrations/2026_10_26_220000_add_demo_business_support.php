<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDemoBusinessSupport extends Migration
{
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'is_demo')) {
                $table->boolean('is_demo')->default(false);
                $table->index('is_demo');
            }
        });

        if (! Schema::hasTable('demo_tours')) {
            Schema::create('demo_tours', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('mode', 16)->nullable();
                $table->string('step', 64)->nullable();
                $table->json('completed')->nullable();
                $table->json('skipped')->nullable();
                $table->json('baselines')->nullable();
                $table->json('visited')->nullable();
                $table->boolean('paused')->default(false);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'user_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('demo_tours');

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'is_demo')) {
                $table->dropIndex(['is_demo']);
                $table->dropColumn('is_demo');
            }
        });
    }
}
