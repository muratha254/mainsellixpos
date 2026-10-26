<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function record(
        string $action,
        string $module,
        ?Model $record = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
        $actor = null
    ): AuditLog {
        $request = $request ?: request();
        if ($actor) {
            $companyId = $actor->company_id ?? null;
            $branchId = $actor->branch_id ?? null;
            $userId = $actor->id ?? null;
        } else {
            $user = $request ? $request->user() : null;
            $companyId = ($user ? $user->company_id : null) ?? (app()->bound('currentCompanyId') ? app('currentCompanyId') : null);
            $branchId = ($user ? $user->branch_id : null) ?? (app()->bound('currentBranchId') ? app('currentBranchId') : null);
            $userId = $user ? $user->id : null;
        }

        return AuditLog::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'auditable_type' => $record ? get_class($record) : null,
            'auditable_id' => $record->id ?? null,
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $request ? $request->ip() : null,
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
            'computer_json' => $this->computerSnapshot($request),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{hostname: string, os: string, ip: ?string}
     */
    private function computerSnapshot(?Request $request): array
    {
        return [
            'hostname' => gethostname() ?: php_uname('n'),
            'os' => php_uname(),
            'ip' => $request ? $request->ip() : null,
        ];
    }

    public static function sourceLabel(?string $auditableType, ?string $module = null): string
    {
        if ($auditableType) {
            $short = Str::afterLast(str_replace('\\', '/', $auditableType), '/');
            $table = Str::snake(Str::pluralStudly($short));

            return 'db_' . $table;
        }

        if ($module) {
            return 'db_' . Str::snake($module);
        }

        return '-';
    }

    public static function actionLabel(string $action, ?string $module = null): string
    {
        $action = str_replace([' ', '-'], '_', strtolower($action));
        if ($module && ! str_contains($action, strtolower($module))) {
            return Str::snake($module) . '_' . $action;
        }

        return $action;
    }

    public static function humanAction(string $action, ?string $module = null): string
    {
        $action = str_replace([' ', '-'], '_', strtolower($action));
        if ($module === 'auth') {
            return ucwords(str_replace('_', ' ', $action));
        }

        return ucwords(str_replace('_', ' ', self::actionLabel($action, $module)));
    }

    public static function detailLine(AuditLog $log): string
    {
        $payload = is_array($log->after_json) && $log->after_json !== []
            ? $log->after_json
            : (is_array($log->before_json) ? $log->before_json : []);

        $bits = [];
        foreach (['name', 'username', 'sku', 'reference', 'invoice_number', 'status', 'branch'] as $key) {
            if (! isset($payload[$key]) || ! is_scalar($payload[$key]) || $payload[$key] === '') {
                continue;
            }
            $bits[] = (string) $payload[$key];
        }

        return implode(' · ', array_slice(array_values(array_unique($bits)), 0, 3));
    }
}
