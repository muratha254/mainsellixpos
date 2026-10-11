@extends('layouts.fleet')

@section('title', 'Loyalty Transactions')

@push('css')
<link rel="stylesheet" href="{{ asset('AdminLTE-2/bower_components/datatables.net-bs/css/dataTables.bootstrap.css') }}">
@endpush

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Loyalty Transactions',
    'backUrl' => route('customers.loyalty.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Loyalty', 'url' => route('customers.loyalty.index')],
        ['label' => 'Transactions'],
    ],
])

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <form method="get" action="{{ route('customers.loyalty.transactions') }}" class="form-inline">
            <input type="date" name="from" class="form-control" value="{{ $filters['from'] }}">
            <input type="date" name="to" class="form-control" value="{{ $filters['to'] }}">
            <select name="customer_id" class="form-control">
                <option value="">All customers</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" @if((string) $filters['customer_id'] === (string) $customer->id) selected @endif>{{ $customer->name }}</option>
                @endforeach
            </select>
            <select name="type" class="form-control">
                <option value="">All types</option>
                @foreach(['earn' => 'Earned', 'redeem' => 'Redeemed', 'adjust' => 'Adjusted', 'expire' => 'Expired', 'reverse' => 'Reversed'] as $value => $label)
                    <option value="{{ $value }}" @if($filters['type'] === $value) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
            <select name="status" class="form-control">
                <option value="">All statuses</option>
                <option value="posted" @if($filters['status'] === 'posted') selected @endif>Posted</option>
                <option value="reversed" @if($filters['status'] === 'reversed') selected @endif>Reversed</option>
            </select>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
    </div>
    <div class="sx-box-body sx-items-body">
        <div class="sx-dt-row">
            <div id="lt-length"></div>
            <div class="sx-export-btns" id="lt-export">
                <button type="button" class="btn" data-export="copy">Copy</button>
                <button type="button" class="btn" data-export="excel">Excel</button>
                <button type="button" class="btn" data-export="pdf">PDF</button>
                <button type="button" class="btn" data-export="print">Print</button>
                <button type="button" class="btn" data-export="csv">CSV</button>
            </div>
            <div id="lt-search"></div>
        </div>
        <div class="table-responsive">
            <table id="lt-table" class="table table-bordered sx-gold-table" width="100%">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-right">Points</th>
                        <th class="text-right">Balance</th>
                        <th>Sale</th>
                        <th>Reference</th>
                        <th>Reason</th>
                        <th>Staff</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $row)
                        <tr>
                            <td>{{ optional($row->created_at)->format('d-m-Y H:i') }}</td>
                            <td>{{ optional($row->customer)->name }}</td>
                            <td>{{ ucfirst($row->type) }}</td>
                            <td>{{ ucfirst($row->status ?: 'posted') }}</td>
                            <td class="text-right">{{ number_format((float) $row->points, 2) }}</td>
                            <td class="text-right">{{ number_format((float) $row->balance_after, 2) }}</td>
                            <td>{{ optional($row->sale)->number ?: '-' }}</td>
                            <td>{{ $row->reference }}</td>
                            <td>{{ $row->notes }}</td>
                            <td>{{ optional($row->user)->name ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10">No loyalty transactions for this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@include('accounting.partials.datatable-js', [
    'tableId' => 'lt-table',
    'lengthId' => 'lt-length',
    'searchId' => 'lt-search',
    'exportId' => 'lt-export',
    'colvisId' => 'lt-colvis',
    'title' => 'Loyalty Transactions',
    'filename' => 'loyalty-transactions',
    'noSort' => [],
    'order' => [[0, 'desc']],
])
