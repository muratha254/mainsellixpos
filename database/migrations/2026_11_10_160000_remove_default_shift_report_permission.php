<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemoveDefaultShiftReportPermission extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'pos.shifts.view')->value('id');
        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('name', [
            PermissionCatalog::BRANCH_MANAGER,
            PermissionCatalog::CASHIER,
            PermissionCatalog::SALESPERSON,
        ])->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        DB::table('permission_role')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $roleIds)
            ->delete();
    }

    public function down()
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'pos.shifts.view')->value('id');
        if (! $permissionId) {
            return;
        }

        $now = now();
        $roleIds = DB::table('roles')->whereIn('name', [
            PermissionCatalog::BRANCH_MANAGER,
            PermissionCatalog::CASHIER,
            PermissionCatalog::SALESPERSON,
        ])->pluck('id');

        foreach ($roleIds as $roleId) {
            $exists = DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();
            if ($exists) {
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
