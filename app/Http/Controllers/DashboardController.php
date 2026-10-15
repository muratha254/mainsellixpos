<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $dashboard)
    {
        $user = auth()->user()->loadMissing(['role', 'company', 'branch']);

        if ($request->filled('branch_id') && $user->canSwitchBranches()) {
            $branch = Branch::query()->find((int) $request->input('branch_id'));
            if ($branch && (int) $branch->company_id === (int) $user->company_id) {
                session(['current_branch_id' => $branch->id]);
                app()->instance('currentBranchId', (int) $branch->id);
            }
        }

        $branchId = session('current_branch_id', $user->branch_id);
        $data = $dashboard->data($user, $branchId ? (int) $branchId : null);

        return view('dashboard.index', array_merge(fleet_shared_view_data(), $data, [
            'activeMenu' => 'dashboard',
            'user' => $user,
        ]));
    }

    public function switchBranch(Request $request)
    {
        abort_unless(auth()->user()->canSwitchBranches(), 403);

        $data = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ]);

        $branch = Branch::query()->findOrFail($data['branch_id']);
        abort_unless((int) $branch->company_id === (int) auth()->user()->company_id, 403);
        if (! $branch->is_active) {
            return back()->with('error', 'That branch is deactivated.');
        }

        session(['current_branch_id' => $branch->id]);
        app()->instance('currentBranchId', (int) $branch->id);

        return redirect()->to($this->previousUrlForBranch((int) $branch->id));
    }

    private function previousUrlForBranch(int $branchId): string
    {
        $target = url()->previous();
        $parts = parse_url($target);
        if (! is_array($parts)) {
            return $target;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        if (! array_key_exists('branch_id', $query)) {
            return $target;
        }

        $query['branch_id'] = $branchId;
        $path = $parts['path'] ?? '/';
        $rebuilt = '';
        if (! empty($parts['scheme']) && ! empty($parts['host'])) {
            $rebuilt = $parts['scheme'] . '://' . $parts['host'];
            if (! empty($parts['port'])) {
                $rebuilt .= ':' . $parts['port'];
            }
        }
        $rebuilt .= $path . '?' . http_build_query($query);
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }
}
