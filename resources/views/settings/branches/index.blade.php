@extends('layouts.fleet')

@section('title', 'Branch List')

@push('css')
@include('accounting.partials.datatable-css')
@endpush

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Branch List',
    'subtitle' => 'View/Search Branch',
    'backUrl' => route('dashboard'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Branch List'],
    ],
])

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <h3 class="sx-box-title" style="flex:none;margin:0;">Branch List</h3>
        @if($canManage && empty($atBranchLimit))
            <a href="{{ route('settings.branches.create') }}" class="btn btn-primary"><i class="fa fa-plus"></i> Add Branch</a>
        @endif
    </div>
    @if(!empty($atBranchLimit) && !empty($subscriptionUsage['max_branches']))
        <div class="alert alert-warning" style="margin:12px 16px 0;">
            Your current subscription allows a maximum of {{ (int) $subscriptionUsage['max_branches'] }} shop{{ (int) $subscriptionUsage['max_branches'] === 1 ? '' : 's' }}/branches. Please upgrade your subscription or contact the System Owner.
        </div>
    @endif

    <div class="sx-box-body sx-items-body">
        <div class="sx-dt-row">
            <div id="br-length"></div>
            <div class="sx-export-btns" id="br-export">
                <button type="button" class="btn" data-export="copy">Copy</button>
                <button type="button" class="btn" data-export="excel">Excel</button>
                <button type="button" class="btn" data-export="pdf">PDF</button>
                <button type="button" class="btn" data-export="print">Print</button>
                <button type="button" class="btn" data-export="csv">CSV</button>
                <div class="btn-group">
                    <button type="button" class="btn dropdown-toggle" data-toggle="dropdown">Columns <span class="caret"></span></button>
                    <ul class="dropdown-menu dropdown-menu-right sx-colvis" id="br-colvis"></ul>
                </div>
            </div>
            <div id="br-search"></div>
        </div>

        <div class="table-responsive">
            <table id="br-table" class="table table-bordered sx-gold-table" width="100%">
                <thead>
                    <tr>
                        <th class="sx-no-export sx-check-col"><input type="checkbox" id="br-check-all"></th>
                        <th>S/N</th>
                        <th>Branch Name</th>
                        <th>Short Name</th>
                        <th>Phone</th>
                        <th>Location</th>
                        <th>City</th>
                        <th>Status</th>
                        <th>List On Login Interface</th>
                        <th class="sx-no-export">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($branches as $i => $branch)
                        <tr>
                            <td class="sx-check-col"><input type="checkbox" class="br-row-check" value="{{ $branch->id }}"></td>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $branch->name }}</td>
                            <td>{{ $branch->shortName() }}</td>
                            <td>{{ $branch->phone }}</td>
                            <td>{{ $branch->address }}</td>
                            <td>{{ $branch->city }}</td>
                            <td>
                                @if($branch->is_active)
                                    <span class="label label-success">Active</span>
                                @else
                                    <span class="label label-default">Inactive</span>
                                @endif
                            </td>
                            <td>
                                @if($branch->list_on_login)
                                    <span class="label label-success">Yes</span>
                                @else
                                    <span class="label label-default">No</span>
                                @endif
                            </td>
                            <td>
                                @if($canManage)
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-primary btn-xs dropdown-toggle" data-toggle="dropdown">
                                            Action <span class="caret"></span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-right sx-action-menu">
                                            <li><a href="{{ route('settings.branches.edit', $branch) }}"><i class="fa fa-pencil"></i> Edit</a></li>
                                            @if($branch->is_active && ! $branch->is_default)
                                                <li>
                                                    <a href="#" class="sx-branch-status"
                                                       data-url="{{ route('settings.branches.status', $branch) }}"
                                                       data-active="0"
                                                       data-name="{{ $branch->name }}">
                                                        <i class="fa fa-pause"></i> Deactivate
                                                    </a>
                                                </li>
                                            @elseif(! $branch->is_active)
                                                <li>
                                                    <a href="#" class="sx-branch-status"
                                                       data-url="{{ route('settings.branches.status', $branch) }}"
                                                       data-active="1"
                                                       data-name="{{ $branch->name }}">
                                                        <i class="fa fa-check"></i> Activate
                                                    </a>
                                                </li>
                                            @endif
                                            @if(! $branch->is_default)
                                                <li>
                                                    <a href="#" class="sx-delete-branch"
                                                       data-url="{{ route('settings.branches.destroy', $branch) }}"
                                                       data-name="{{ $branch->name }}"
                                                       data-staff="{{ (int) $branch->users_count }}"
                                                       data-fallback="{{ optional($branches->firstWhere('is_default', true))->name ?: 'the default branch' }}">
                                                        <i class="fa fa-trash"></i> Delete
                                                    </a>
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
                                @else
                                    --
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<form id="sx-branch-status-form" method="post" class="hidden">
    @csrf
    <input type="hidden" name="active" value="1">
</form>
<form id="sx-del-branch-form" method="post" class="hidden">
    @csrf
    @method('DELETE')
    <input type="hidden" name="staff_action" value="">
</form>
@endsection

@push('scripts')
<script>
$(document).on('click', '.sx-branch-status', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var link = this;
    var form = document.getElementById('sx-branch-status-form');
    if (!form) return;
    var turningOn = link.getAttribute('data-active') === '1';
    var name = link.getAttribute('data-name') || 'this branch';
    Swal.fire({
        icon: 'question',
        title: (turningOn ? 'Activate ' : 'Deactivate ') + name + '?',
        text: turningOn
            ? 'The branch will show in the branch list again. Staff and sales stay as they are.'
            : 'The branch stays in this list and can be activated again. It will be hidden from the till until then.',
        showCancelButton: true,
        confirmButtonText: turningOn ? 'Activate' : 'Deactivate',
        cancelButtonText: 'Cancel'
    }).then(function (result) {
        if (!result.isConfirmed) return;
        form.action = link.getAttribute('data-url');
        var active = form.querySelector('[name="active"]');
        if (active) active.value = turningOn ? '1' : '0';
        HTMLFormElement.prototype.submit.call(form);
    });
});
$(document).on('click', '.sx-delete-branch', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var link = this;
    var form = document.getElementById('sx-del-branch-form');
    if (!form) return;
    var staffCount = parseInt(link.getAttribute('data-staff'), 10) || 0;
    var name = link.getAttribute('data-name') || 'this branch';
    var fallbackName = link.getAttribute('data-fallback') || 'the default branch';
    function submit(choice) {
        form.action = link.getAttribute('data-url');
        var action = form.querySelector('[name="staff_action"]');
        if (action) action.value = choice || '';
        HTMLFormElement.prototype.submit.call(form);
    }
    if (staffCount <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Delete ' + name + '?',
            text: 'The branch will be removed. Sales already recorded stay in the books.',
            showCancelButton: true,
            confirmButtonText: 'Delete branch',
            cancelButtonText: 'Cancel'
        }).then(function (result) {
            if (result.isConfirmed) submit('');
        });
        return;
    }
    var people = staffCount === 1 ? '1 staff account is' : (staffCount + ' staff accounts are');
    Swal.fire({
        icon: 'question',
        title: 'Delete ' + name + '?',
        text: people + ' on this branch. Move them only if you want them to keep working at ' + fallbackName + '.',
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'Move staff to ' + fallbackName,
        denyButtonText: 'Turn off those accounts',
        cancelButtonText: 'Cancel'
    }).then(function (result) {
        if (result.isConfirmed) submit('move');
        else if (result.isDenied) submit('remove');
    });
});
</script>
@endpush

@include('accounting.partials.datatable-js', [
    'tableId' => 'br-table',
    'lengthId' => 'br-length',
    'searchId' => 'br-search',
    'exportId' => 'br-export',
    'colvisId' => 'br-colvis',
    'checkAll' => 'br-check-all',
    'rowCheck' => 'br-row-check',
    'title' => 'Branch List',
    'filename' => 'branch-list',
    'order' => [[1, 'asc']],
    'noSort' => [0, 9],
])
