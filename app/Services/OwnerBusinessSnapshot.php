<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

class OwnerBusinessSnapshot
{
    public function attach(Collection $companies): Collection
    {
        $ids = $companies->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return $companies;
        }

        $logins = User::query()
            ->whereIn('company_id', $ids)
            ->whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->orderByDesc('id')
            ->get()
            ->unique('company_id')
            ->keyBy('company_id');

        $branches = Branch::query()
            ->withoutGlobalScope('company')
            ->whereIn('company_id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('company_id');

        foreach ($companies as $company) {
            $company->setRelation('latestLoginUser', $logins->get($company->id));
            $company->setRelation('activeBranches', $branches->get($company->id, collect()));
        }

        return $companies;
    }

    public function recentSignIns(int $limit = 8): Collection
    {
        return User::query()
            ->with(['company', 'branch'])
            ->whereNotNull('company_id')
            ->whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
