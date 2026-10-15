<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\SubscriptionLimitGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BranchSettingsController extends Controller
{
    public function index()
    {
        abort_unless($this->canView(), 403);

        $usage = $this->subscriptionUsage();

        return view('settings.branches.index', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'settings.branches',
            'branches' => Branch::query()->withCount('users')->orderByDesc('is_default')->orderBy('name')->get(),
            'canManage' => $this->canManage(),
            'subscriptionUsage' => $usage,
            'atBranchLimit' => $this->atBranchLimit($usage),
        ]));
    }

    public function create()
    {
        abort_unless($this->canManage(), 403);

        $usage = $this->subscriptionUsage();
        if ($this->atBranchLimit($usage)) {
            return redirect()->route('settings.branches')->with('error', $this->branchLimitMessage($usage));
        }

        return view('settings.branches.form', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'settings.branches',
            'subscriptionUsage' => $usage,
            'atBranchLimit' => false,
            'branch' => new Branch([
                'is_active' => true,
                'list_on_login' => true,
                'system_mode' => 'Touch Mode(Normal)',
                'include_catering_levy' => false,
                'auto_receipt_amt_pos' => false,
            ]),
        ]));
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);

        $company = auth()->user()->company;
        try {
            if ($company) {
                app(\App\Services\SubscriptionLimitGuard::class)->assertCanCreateBranch($company);
            }
        } catch (\App\Exceptions\SubscriptionLimitException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $data = $this->validated($request);
        $data['company_id'] = auth()->user()->company_id;
        $data['logo_path'] = $this->storeLogo($request);

        $branch = Branch::query()->create($data);
        if ($branch->is_default) {
            $this->clearOtherDefaults($branch->id);
        }

        return redirect()->route('settings.branches')->with('success', 'Branch saved.');
    }

    public function edit(Branch $branch)
    {
        abort_unless($this->canManage(), 403);

        $usage = $this->subscriptionUsage();

        return view('settings.branches.form', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'settings.branches',
            'subscriptionUsage' => $usage,
            'atBranchLimit' => false,
            'branch' => $branch,
        ]));
    }

    public function update(Request $request, Branch $branch)
    {
        abort_unless($this->canManage(), 403);

        $data = $this->validated($request, $branch->id);

        if ($request->boolean('remove_logo') && $branch->logo_path) {
            Storage::disk('public')->delete($branch->logo_path);
            $data['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($branch->logo_path) {
                Storage::disk('public')->delete($branch->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('branch-logos', 'public');
        }

        $branch->update($data);
        if ($branch->is_default) {
            $this->clearOtherDefaults($branch->id);
        }

        return redirect()->route('settings.branches')->with('success', 'Branch updated.');
    }

    public function updateStatus(Request $request, Branch $branch)
    {
        abort_unless($this->canManage(), 403);

        $active = $request->boolean('active');
        if (! $active && $branch->is_default) {
            return redirect()
                ->route('settings.branches')
                ->with('error', 'The default branch cannot be deactivated. Set another branch as the default first.');
        }

        $branch->update(['is_active' => $active]);

        if (! $active && (int) session('current_branch_id') === (int) $branch->id) {
            $fallback = Branch::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');
            if ($fallback) {
                session(['current_branch_id' => (int) $fallback]);
            }
        }

        return redirect()
            ->route('settings.branches')
            ->with('success', $active
                ? $branch->name.' is active again.'
                : $branch->name.' is deactivated. Activate it from this list when you want it back.');
    }

    public function destroy(Request $request, Branch $branch)
    {
        abort_unless($this->canManage(), 403);

        if ($branch->is_default) {
            return redirect()
                ->route('settings.branches')
                ->with('error', 'The default branch cannot be deleted. Set another branch as the default first.');
        }

        $fallback = Branch::query()
            ->where('id', '!=', $branch->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (! $fallback) {
            return redirect()
                ->route('settings.branches')
                ->with('error', 'This is the only branch and cannot be deleted.');
        }

        $staffCount = (int) $branch->users()->count();
        $action = (string) $request->input('staff_action', '');
        if ($staffCount > 0 && ! in_array($action, ['move', 'remove'], true)) {
            return redirect()
                ->route('settings.branches')
                ->with('error', 'Choose whether to move the staff on this branch or turn those accounts off.');
        }

        $actorId = (int) auth()->id();
        $actorWasHere = $branch->users()->whereKey($actorId)->exists();

        DB::transaction(function () use ($branch, $fallback, $action, $actorId) {
            if ($action === 'move') {
                $branch->users()->update(['branch_id' => $fallback->id]);
            } elseif ($action === 'remove') {
                $branch->users()->where('id', '!=', $actorId)->update([
                    'branch_id' => null,
                    'is_active' => false,
                ]);
                $branch->users()->whereKey($actorId)->update(['branch_id' => $fallback->id]);
            }

            if ($branch->logo_path) {
                Storage::disk('public')->delete($branch->logo_path);
            }

            $branch->delete();
        });

        if ($actorWasHere && $action === 'move') {
            auth()->user()->forceFill(['branch_id' => $fallback->id])->save();
        } elseif ($actorWasHere && $action === 'remove') {
            auth()->user()->forceFill(['branch_id' => $fallback->id])->save();
        }
        if ((int) session('current_branch_id') === (int) $branch->id) {
            session(['current_branch_id' => $fallback->id]);
        }

        $message = 'Branch deleted.';
        if ($action === 'move' && $staffCount > 0) {
            $message .= ' '.$staffCount.' staff account'.($staffCount === 1 ? '' : 's').' now use '.$fallback->name.'.';
        } elseif ($action === 'remove' && $staffCount > 0) {
            $turnedOff = $actorWasHere ? $staffCount - 1 : $staffCount;
            if ($turnedOff > 0) {
                $message .= ' '.$turnedOff.' staff account'.($turnedOff === 1 ? ' was' : 's were').' turned off.';
            }
            if ($actorWasHere) {
                $message .= ' Your account now uses '.$fallback->name.'.';
            }
        }

        return redirect()->route('settings.branches')->with('success', $message);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $companyId = auth()->user()->company_id;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('branches', 'name')->ignore($ignoreId)->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('branches', 'code')->ignore($ignoreId)->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'system_mode' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:40'],
            'phone_alt' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:40'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'till1' => ['nullable', 'string', 'max:64'],
            'account1' => ['nullable', 'string', 'max:64'],
            'till2' => ['nullable', 'string', 'max:64'],
            'account2' => ['nullable', 'string', 'max:64'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_no' => ['nullable', 'string', 'max:64'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'bank_code' => ['nullable', 'string', 'max:40'],
            'bank_swift' => ['nullable', 'string', 'max:40'],
            'include_catering_levy' => ['required', 'in:Yes,No'],
            'auto_receipt_amt_pos' => ['required', 'in:Yes,No'],
            'list_on_login' => ['nullable', 'in:Yes,No'],
            'is_active' => ['nullable', 'in:Yes,No'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        unset($data['logo']);

        $data['include_catering_levy'] = ($data['include_catering_levy'] ?? 'No') === 'Yes';
        $data['auto_receipt_amt_pos'] = ($data['auto_receipt_amt_pos'] ?? 'No') === 'Yes';
        $data['list_on_login'] = ($request->input('list_on_login', 'Yes') === 'Yes');
        $data['is_active'] = ($request->input('is_active', 'Yes') === 'Yes');
        $data['is_default'] = $request->boolean('is_default');

        return $data;
    }

    private function storeLogo(Request $request): ?string
    {
        if (! $request->hasFile('logo')) {
            return null;
        }

        return $request->file('logo')->store('branch-logos', 'public');
    }

    private function clearOtherDefaults(int $keepId): void
    {
        Branch::query()->where('id', '!=', $keepId)->update(['is_default' => false]);
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return $user && (
            $user->hasPermission('settings.branches')
            || $user->hasPermission('branches.view')
            || $user->hasPermission('settings.view')
        );
    }

    private function canManage(): bool
    {
        $user = auth()->user();

        return $user && (
            $user->hasPermission('settings.branches')
            || $user->hasPermission('branches.manage')
        );
    }

    private function subscriptionUsage(): array
    {
        $company = auth()->user()->company;

        return $company
            ? app(SubscriptionLimitGuard::class)->usage($company)
            : ['users' => 0, 'max_users' => null, 'branches' => 0, 'max_branches' => null];
    }

    private function atBranchLimit(array $usage): bool
    {
        return ! empty($usage['max_branches']) && (int) $usage['branches'] >= (int) $usage['max_branches'];
    }

    private function branchLimitMessage(array $usage): string
    {
        $max = (int) ($usage['max_branches'] ?? 0);

        return 'Your current subscription allows a maximum of '.$max.' shop'.($max === 1 ? '' : 's').'/branches. Please upgrade your subscription or contact the System Owner.';
    }
}
