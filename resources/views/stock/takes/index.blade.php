@extends('layouts.fleet')
@section('title', 'Stock Take')
@include('accounting.partials.datatable-css')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Stock Take',
    'subtitle' => 'Count physical stock, then reconcile it with the recorded quantities',
    'backUrl' => route('stock.manager'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Stock Take'],
    ],
])

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <div></div>
        @if($canCreate)
            <div class="sx-toolbar-actions">
                <a href="{{ route('stock.takes.create') }}" class="btn btn-primary"><i class="fa fa-plus"></i> New Stock Take</a>
            </div>
        @endif
    </div>
    <div class="sx-box-body sx-items-body">
        @include('accounting.partials.dt-toolbar', ['tableId' => 'stock-take-table'])
        <div class="table-responsive">
            <table id="stock-take-table" class="table table-bordered sx-gold-table" width="100%">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Branch</th>
                        <th>Created By</th>
                        <th>Date</th>
                        <th>Counted</th>
                        <th>Shortages</th>
                        <th>Surpluses</th>
                        <th>Status</th>
                        <th>Approved By</th>
                        <th>Reconciled By</th>
                        <th>Reconciled</th>
                        <th class="sx-no-export">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($takes as $row)
                        <tr>
                            <td>{{ $row->number }}</td>
                            <td>{{ optional($row->branch)->name }}</td>
                            <td>{{ optional($row->creator)->name }}</td>
                            <td data-order="{{ optional($row->created_at)->format('Y-m-d H:i') }}">{{ optional($row->created_at)->format('Y-m-d H:i') }}</td>
                            <td>{{ $row->counted_count }} / {{ $row->lines_count }}</td>
                            <td>{{ $row->shortage_count }}</td>
                            <td>{{ $row->surplus_count }}</td>
                            <td>
                                @if($row->status === \App\Models\StockTake::STATUS_RECONCILED)
                                    <span class="sx-status-active">{{ $row->statusLabel() }}</span>
                                @elseif($row->status === \App\Models\StockTake::STATUS_CANCELLED)
                                    <span class="sx-status-inactive">{{ $row->statusLabel() }}</span>
                                @else
                                    <span class="label label-warning">{{ $row->statusLabel() }}</span>
                                @endif
                            </td>
                            <td>{{ optional($row->approver)->name ?: '—' }}</td>
                            <td>{{ optional($row->reconciler)->name ?: '—' }}</td>
                            <td>{{ optional($row->reconciled_at)->format('Y-m-d H:i') ?: '—' }}</td>
                            <td><a href="{{ route('stock.takes.show', $row) }}" class="btn btn-primary btn-xs">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@include('accounting.partials.datatable-js', [
    'tableId' => 'stock-take-table', 'lengthId' => 'stock-take-table-length', 'searchId' => 'stock-take-table-search',
    'exportId' => 'stock-take-table-export', 'colvisId' => 'stock-take-table-colvis',
    'title' => 'Stock Take', 'filename' => 'stock-take', 'noSort' => [11], 'order' => [[3, 'desc']],
])
