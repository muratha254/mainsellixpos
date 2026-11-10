<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePosShiftsTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('pos_shifts')) {
            Schema::create('pos_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('user_id');
                $table->string('number', 32);
                $table->string('status', 32)->default('open');
                $table->unsignedTinyInteger('is_open')->nullable();
                $table->dateTime('opened_at');
                $table->dateTime('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->decimal('opening_cash', 15, 2)->default(0);
                $table->decimal('opening_mpesa', 15, 2)->default(0);
                $table->text('opening_remarks')->nullable();
                $table->decimal('actual_cash', 15, 2)->nullable();
                $table->decimal('actual_mpesa', 15, 2)->nullable();
                $table->text('closing_remarks')->nullable();
                $table->decimal('cash_sales', 15, 2)->nullable();
                $table->decimal('mpesa_sales', 15, 2)->nullable();
                $table->decimal('card_sales', 15, 2)->nullable();
                $table->decimal('bank_transfer_sales', 15, 2)->nullable();
                $table->decimal('other_sales', 15, 2)->nullable();
                $table->decimal('cash_refunds', 15, 2)->nullable();
                $table->decimal('mpesa_refunds', 15, 2)->nullable();
                $table->decimal('cash_expenses', 15, 2)->nullable();
                $table->decimal('mpesa_expenses', 15, 2)->nullable();
                $table->decimal('expected_cash', 15, 2)->nullable();
                $table->decimal('expected_mpesa', 15, 2)->nullable();
                $table->decimal('cash_variance', 15, 2)->nullable();
                $table->decimal('mpesa_variance', 15, 2)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTime('reviewed_at')->nullable();
                $table->text('review_remarks')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'number']);
                $table->unique(['user_id', 'is_open']);
                $table->index(['company_id', 'opened_at']);
                $table->index(['company_id', 'status']);
                $table->index(['branch_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('pos_shift_adjustments')) {
            Schema::create('pos_shift_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('pos_shift_id');
                $table->unsignedBigInteger('user_id');
                $table->decimal('cash_amount', 15, 2)->default(0);
                $table->decimal('mpesa_amount', 15, 2)->default(0);
                $table->string('reason', 1000);
                $table->timestamps();

                $table->index(['pos_shift_id', 'created_at']);
            });
        }

        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'pos_shift_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->unsignedBigInteger('pos_shift_id')->nullable()->after('user_id');
                $table->index('pos_shift_id');
            });
        }

        $this->grantShiftPermissions();
    }

    public function down()
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'pos_shift_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['pos_shift_id']);
                $table->dropColumn('pos_shift_id');
            });
        }

        Schema::dropIfExists('pos_shift_adjustments');
        Schema::dropIfExists('pos_shifts');

        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->whereIn('name', [
            'pos.shift',
            'pos.shifts.view',
            'pos.shifts.approve',
        ])->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function grantShiftPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $now = now();
        $names = [];
        foreach (PermissionCatalog::permissions() as $name => $meta) {
            if (! in_array($name, ['pos.shift', 'pos.shifts.view', 'pos.shifts.approve'], true)) {
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
            $wanted = array_values(array_intersect((array) $meta['permissions'], $names));
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
}
