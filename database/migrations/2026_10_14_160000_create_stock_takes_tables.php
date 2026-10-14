<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateStockTakesTables extends Migration
{
    public function up()
    {
        if (! $this->stockTakeSchemaReady()) {
            Schema::dropIfExists('stock_take_lines');
            Schema::dropIfExists('stock_takes');
        }

        if (! Schema::hasTable('stock_takes')) {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('status', 32)->default('in_progress');
            $table->string('scope', 32)->default('all');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('upload_name')->nullable();
            $table->string('upload_hash', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
        });

        Schema::create('stock_take_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->default(0);
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('item_name');
            $table->string('category_name')->nullable();
            $table->string('unit_name')->nullable();
            $table->decimal('system_quantity', 16, 4)->default(0);
            $table->decimal('counted_quantity', 16, 4)->nullable();
            $table->decimal('reconciled_system_quantity', 16, 4)->nullable();
            $table->decimal('applied_variance', 16, 4)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['stock_take_id', 'product_id', 'product_variant_id'], 'stock_take_lines_item_unique');
        });
        }

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        $names = [];
        foreach (PermissionCatalog::permissions() as $name => $meta) {
            if (strpos($name, 'stocktake.') !== 0) {
                continue;
            }
            $names[] = $name;
            $exists = DB::table('permissions')->where('name', $name)->exists();
            if ($exists) {
                DB::table('permissions')->where('name', $name)->update([
                    'display_name' => $meta['label'],
                    'module' => $meta['module'],
                    'updated_at' => $now,
                ]);
                continue;
            }
            DB::table('permissions')->insert([
                'name' => $name,
                'display_name' => $meta['label'],
                'module' => $meta['module'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('name', $names)->pluck('id', 'name');
        foreach (PermissionCatalog::roles() as $roleName => $meta) {
            $wanted = array_values(array_intersect($meta['permissions'], $names));
            if ($wanted === []) {
                continue;
            }
            $roleIds = DB::table('roles')->where('name', $roleName)->pluck('id');
            foreach ($roleIds as $roleId) {
                foreach ($wanted as $permissionName) {
                    $permissionId = $permissionIds[$permissionName] ?? null;
                    if (! $permissionId) {
                        continue;
                    }
                    $attached = DB::table('permission_role')
                        ->where('permission_id', $permissionId)
                        ->where('role_id', $roleId)
                        ->exists();
                    if ($attached) {
                        continue;
                    }
                    DB::table('permission_role')->insert([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_takes');

        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->where('name', 'like', 'stocktake.%')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function stockTakeSchemaReady(): bool
    {
        if (! Schema::hasTable('stock_takes') || ! Schema::hasTable('stock_take_lines')) {
            return false;
        }

        foreach (DB::select('SHOW INDEX FROM stock_take_lines') as $index) {
            if (($index->Key_name ?? null) === 'stock_take_lines_item_unique') {
                return true;
            }
        }

        return false;
    }
}
