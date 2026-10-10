@extends('layouts.fleet')

@section('title', 'Invoices')

@push('css')
<link rel="stylesheet" href="{{ asset('AdminLTE-2/bower_components/datatables.net-bs/css/dataTables.bootstrap.css') }}">
@endpush

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Invoices',
    'subtitle' => 'View/Search Invoices',
    'backUrl' => route('dashboard'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Invoices'],
    ],
])

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <h3 class="sx-box-title" style="flex:none;margin:0;">Invoice List</h3>
        <div class="sx-toolbar-actions">
            @if($canCreate)
                <a href="{{ route('invoices.create') }}" class="btn sx-btn-aqua"><i class="fa fa-plus"></i> New Invoice</a>
            @endif
        </div>
    </div>

    <div class="sx-box-body sx-items-body">
        <div class="sx-dt-row">
            <div id="inv-length"></div>
            <div class="sx-export-btns" id="inv-export">
                <button type="button" class="btn" data-export="copy">Copy</button>
                <button type="button" class="btn" data-export="excel">Excel</button>
                <button type="button" class="btn" data-export="pdf">PDF</button>
                <button type="button" class="btn" data-export="print">Print</button>
                <button type="button" class="btn" data-export="csv">CSV</button>
            </div>
            <div id="inv-search"></div>
        </div>

        <div class="table-responsive">
            <table id="inv-table" class="table table-bordered sx-gold-table" width="100%">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Number</th>
                        <th>Customer</th>
                        <th>Due Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Pay Status</th>
                        <th>Created by</th>
                        <th class="sx-no-export">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $row)
                        @php
                            $payClass = [
                                'paid' => 'sx-pay-paid',
                                'partial' => 'sx-pay-partial',
                                'unpaid' => 'sx-pay-unpaid',
                            ][$row->payment_status] ?? 'sx-pay-unpaid';
                            $number = $row->invoice_number ?: $row->number;
                        @endphp
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td data-order="{{ optional($row->sale_date)->format('Y-m-d H:i:s') }}">{{ optional($row->sale_date)->format('d-m-Y') }}</td>
                            <td>{{ $number }}</td>
                            <td>{{ $row->customerDisplayName() }}</td>
                            <td data-order="{{ optional($row->due_date)->format('Y-m-d') }}">{{ optional($row->due_date)->format('d-m-Y') ?: '-' }}</td>
                            <td data-order="{{ $row->total }}">{{ number_format((float) $row->total, 2) }}</td>
                            <td data-order="{{ $row->paid_amount }}">{{ number_format((float) $row->paid_amount, 2) }}</td>
                            <td data-order="{{ $row->balance }}">{{ number_format((float) $row->balance, 2) }}</td>
                            <td><span class="sx-pay-badge {{ $payClass }}">{{ $row->paymentStatusLabel() }}</span></td>
                            <td>{{ optional($row->cashier)->name ?: '-' }}</td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-primary btn-xs dropdown-toggle" data-toggle="dropdown">
                                        Action <span class="caret"></span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-right sx-action-menu">
                                        <li>
                                            <a href="{{ route('invoices.show', $row) }}"><i class="fa fa-eye"></i> View</a>
                                        </li>
                                        @if($canPrint)
                                            <li>
                                                <a href="{{ route('sales.print', $row) }}" target="_blank"><i class="fa fa-print"></i> Print</a>
                                            </li>
                                        @endif
                                        <li>
                                            <a href="{{ route('sales.show', $row) }}"><i class="fa fa-money"></i> Payments</a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('AdminLTE-2/bower_components/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('AdminLTE-2/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js') }}"></script>
<script>
(function ($) {
    var table = $('#inv-table').DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
        columnDefs: [{ targets: -1, orderable: false }]
    });
    $('#inv-length').append($('#inv-table_length'));
    $('#inv-search').append($('#inv-table_filter'));
    $('#inv-export [data-export]').on('click', function () {
        var type = $(this).data('export');
        if (type === 'print') {
            window.print();
            return;
        }
        alert('Use browser export / copy from the table for ' + type.toUpperCase() + '.');
    });
})(jQuery);
</script>
@endpush
