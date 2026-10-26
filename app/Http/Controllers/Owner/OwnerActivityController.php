<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\OwnerBusinessSnapshot;
use Illuminate\Http\Request;

class OwnerActivityController extends Controller
{
    public function index(Request $request, OwnerBusinessSnapshot $snapshot)
    {
        $companyId = (int) $request->query('company_id', 0);
        $q = trim((string) $request->query('q', ''));

        $companies = Company::query()
            ->when($companyId > 0, fn ($query) => $query->whereKey($companyId))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', '%' . $q . '%')
                        ->orWhere('email', 'like', '%' . $q . '%')
                        ->orWhere('owner_name', 'like', '%' . $q . '%');
                });
            })
            ->orderBy('name')
            ->get();

        $snapshot->attach($companies);

        $companyIds = $companies->pluck('id');
        $logs = AuditLog::query()
            ->withoutGlobalScopes()
            ->with(['user', 'branch', 'company'])
            ->whereIn('company_id', $companyIds->isEmpty() ? [0] : $companyIds->all())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('owner.activity.index', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'owner.activity',
            'ownerConsole' => true,
            'companies' => $companies,
            'companyOptions' => Company::query()->orderBy('name')->get(['id', 'name']),
            'logs' => $logs,
            'filters' => ['q' => $q, 'company_id' => $companyId > 0 ? $companyId : ''],
        ]));
    }
}
