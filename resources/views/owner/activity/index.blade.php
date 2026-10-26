@extends('layouts.fleet')

@section('title', 'Business activity')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Business activity',
    'subtitle' => 'Last sign-in, active branches, and what each business has done',
    'backUrl' => route('owner.dashboard'),
    'breadcrumbs' => [
        ['label' => 'Owner', 'url' => route('owner.dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Activity'],
    ],
])

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <form method="get" class="form-inline" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
            <div class="form-group">
                <label>Search</label>
                <input type="text" name="q" class="form-control" value="{{ $filters['q'] }}" placeholder="Business, owner, email">
            </div>
            <div class="form-group">
                <label>Business</label>
                <select name="company_id" class="form-control">
                    <option value="">All</option>
                    @foreach($companyOptions as $option)
                        <option value="{{ $option->id }}" @if((string) $filters['company_id'] === (string) $option->id) selected @endif>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn sx-btn-aqua"><i class="fa fa-search"></i> Filter</button>
        </form>
    </div>
    <div class="sx-box-body">
        <h4>Businesses</h4>
        <div class="table-responsive">
            <table class="table table-bordered sx-gold-table">
                <thead>
                    <tr>
                        <th>Business</th>
                        <th>Last login</th>
                        <th>Signed in as</th>
                        <th>Active branches</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($companies as $company)
                        <tr>
                            <td><a href="{{ route('owner.businesses.show', $company) }}">{{ $company->name }}</a></td>
                            <td>{{ $company->latestLoginUser ? $company->latestLoginUser->last_login_at->format('d M Y H:i') : 'Never' }}</td>
                            <td>{{ optional($company->latestLoginUser)->name ?: '-' }}</td>
                            <td>
                                @if($company->activeBranches->isEmpty())
                                    None
                                @else
                                    {{ $company->activeBranches->pluck('name')->implode(', ') }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No businesses match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <h4>Activity</h4>
        @include('owner.partials.activity-table', ['logs' => $logs, 'showBusiness' => true])
        {{ $logs->links() }}
    </div>
</div>
@endsection
