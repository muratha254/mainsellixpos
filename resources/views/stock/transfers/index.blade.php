@extends('layouts.fleet')
@section('title', 'Stock Transfers')
@include('accounting.partials.datatable-css')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Stock Transfers',
    'subtitle' => 'Send stock to another branch, then that branch receives or rejects it',
    'backUrl' => route('stock.manager'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Stock Transfers'],
    ],
])

@if($incomingCount > 0)
    <div class="alert alert-warning">
        {{ $incomingCount }} transfer{{ $incomingCount === 1 ? '' : 's' }} {{ $incomingCount === 1 ? 'is' : 'are' }} waiting for this branch to receive or reject.
    </div>
@endif

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <div></div>
        @if($canTransfer)
            <div class="sx-toolbar-actions">
                <a href="{{ route('stock.transfers.create') }}" class="btn btn-primary"><i class="fa fa-plus"></i> New Transfer</a>
            </div>
        @endif
    </div>
    <div class="sx-box-body sx-items-body">
        @include('accounting.partials.dt-toolbar', ['tableId' => 'transfer-table'])
        <div class="table-responsive">
            <table id="transfer-table" class="table table-bordered sx-gold-table" width="100%">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Date</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Direction</th>
                        <th>Items</th>
                        <th>Status</th>
                        <th>Posted By</th>
                        <th class="sx-no-export">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transfers as $row)
                        @php
                            $incoming = (int) $row->to_branch_id === (int) $currentBranchId;
                            $canDecide = $canTransfer && $incoming && $row->status === \App\Models\StockTransfer::STATUS_PENDING;
                        @endphp
                        <tr>
                            <td>{{ $row->number }}</td>
                            <td data-order="{{ optional($row->transfer_date)->format('Y-m-d') }}">{{ optional($row->transfer_date)->format('Y-m-d') }}</td>
                            <td>{{ optional($row->fromBranch)->name }}</td>
                            <td>{{ optional($row->toBranch)->name }}</td>
                            <td>{{ $incoming ? 'Incoming' : 'Outgoing' }}</td>
                            <td>{{ $row->items->count() }}</td>
                            <td>
                                @if($row->status === 'completed')
                                    <span class="sx-status-active">{{ $row->statusLabel() }}</span>
                                @elseif(in_array($row->status, ['cancelled', 'rejected'], true))
                                    <span class="sx-status-inactive">{{ $row->statusLabel() }}</span>
                                @else
                                    <span class="label label-warning">{{ $row->statusLabel() }}</span>
                                @endif
                            </td>
                            <td>{{ optional($row->user)->name }}</td>
                            <td>
                                <a href="{{ route('stock.transfers.show', $row) }}" class="btn btn-primary btn-xs">View</a>
                                @if($canDecide)
                                    <form method="post" action="{{ route('stock.transfers.receive', $row) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-xs">Receive</button>
                                    </form>
                                    <form method="post" action="{{ route('stock.transfers.reject', $row) }}" style="display:inline;" onsubmit="return confirm('Reject this transfer and return the items?');">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-xs">Reject</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@include('accounting.partials.datatable-js', [
    'tableId' => 'transfer-table', 'lengthId' => 'transfer-table-length', 'searchId' => 'transfer-table-search',
    'exportId' => 'transfer-table-export', 'colvisId' => 'transfer-table-colvis',
    'title' => 'Stock Transfers', 'filename' => 'stock-transfers', 'noSort' => [8], 'order' => [[1, 'desc']],
])
