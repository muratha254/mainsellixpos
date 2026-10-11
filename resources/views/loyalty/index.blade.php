@extends('layouts.fleet')

@section('title', 'Loyalty Points')

@push('css')
<link rel="stylesheet" href="{{ asset('AdminLTE-2/bower_components/datatables.net-bs/css/dataTables.bootstrap.css') }}">
@endpush

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Loyalty Points',
    'backUrl' => route('customers.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Customers', 'url' => route('customers.index')],
        ['label' => 'Loyalty Points'],
    ],
])

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <form method="get" action="{{ route('customers.loyalty.index') }}" class="form-inline">
            <input type="text" name="q" class="form-control" value="{{ $search }}" placeholder="Name, email, phone, or ID" style="min-width:240px;">
            <button type="submit" class="btn btn-primary">Search</button>
            <a href="{{ route('customers.loyalty.transactions') }}" class="btn btn-default">Transactions</a>
            @if(auth()->user()->hasPermission('settings.view') || auth()->user()->hasPermission('settings.company'))
                <a href="{{ route('settings.loyalty') }}" class="btn btn-default">Settings</a>
            @endif
        </form>
    </div>
    <div class="sx-box-body sx-items-body">
        <div class="sx-dt-row">
            <div id="ly-length"></div>
            <div class="sx-export-btns" id="ly-export">
                <button type="button" class="btn" data-export="copy">Copy</button>
                <button type="button" class="btn" data-export="excel">Excel</button>
                <button type="button" class="btn" data-export="pdf">PDF</button>
                <button type="button" class="btn" data-export="print">Print</button>
                <button type="button" class="btn" data-export="csv">CSV</button>
            </div>
            <div id="ly-search"></div>
        </div>
        <div class="table-responsive">
            <table id="ly-table" class="table table-bordered sx-gold-table" width="100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th class="text-right">Available</th>
                        <th class="text-right">Earned</th>
                        <th class="text-right">Redeemed</th>
                        <th class="sx-no-export">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($customers as $customer)
                        <tr>
                            <td>{{ $customer->id }}</td>
                            <td>{{ $customer->name }}</td>
                            <td>{{ $customer->phone ?: $customer->mobile }}</td>
                            <td>{{ $customer->email }}</td>
                            <td class="text-right" data-order="{{ (float) $customer->loyalty_points }}">{{ number_format((float) $customer->loyalty_points, 2) }}</td>
                            <td class="text-right">{{ number_format((float) $customer->earned_points, 2) }}</td>
                            <td class="text-right">{{ number_format(abs((float) $customer->redeemed_points), 2) }}</td>
                            <td>
                                <a href="{{ route('customers.loyalty.show', $customer) }}" class="btn btn-primary btn-xs">History</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No customers found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@include('accounting.partials.datatable-js', [
    'tableId' => 'ly-table',
    'lengthId' => 'ly-length',
    'searchId' => 'ly-search',
    'exportId' => 'ly-export',
    'colvisId' => 'ly-colvis',
    'title' => 'Loyalty Points',
    'filename' => 'loyalty-points',
    'noSort' => [7],
    'order' => [[1, 'asc']],
])
