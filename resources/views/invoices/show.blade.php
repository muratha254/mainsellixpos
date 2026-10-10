@extends('layouts.fleet')

@section('title', 'Invoice ' . ($sale->invoice_number ?: $sale->number))

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Invoice',
    'subtitle' => $sale->invoice_number ?: $sale->number,
    'backUrl' => route('invoices.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Invoices', 'url' => route('invoices.index')],
        ['label' => $sale->invoice_number ?: $sale->number],
    ],
])

@php
    $number = $sale->invoice_number ?: $sale->number;
    $payClass = [
        'paid' => 'sx-pay-paid',
        'partial' => 'sx-pay-partial',
        'unpaid' => 'sx-pay-unpaid',
    ][$sale->payment_status] ?? 'sx-pay-unpaid';
@endphp

<div class="sx-invoice">
    <div class="sx-invoice-title">
        <h3><i class="fa fa-file-text-o"></i> Invoice</h3>
        <div class="sx-invoice-date">Date: {{ optional($sale->sale_date)->format('d-m-Y') }}</div>
    </div>

    <div class="row sx-invoice-parties">
        <div class="col-md-4">
            <div class="sx-invoice-label">Customer</div>
            <div class="sx-invoice-name">{{ $sale->customerDisplayName() }}</div>
            <div>Phone: {{ optional($sale->customer)->phone ?: '-' }}</div>
            <div>Email: {{ optional($sale->customer)->email ?: '-' }}</div>
        </div>
        <div class="col-md-4">
            <div class="sx-invoice-label">Invoice Details</div>
            <div>Number: <strong>{{ $number }}</strong></div>
            <div>Status: {{ $sale->statusLabel() }}</div>
            <div>Pay Status: <span class="sx-pay-badge {{ $payClass }}">{{ $sale->paymentStatusLabel() }}</span></div>
            <div>Due Date: {{ optional($sale->due_date)->format('d-m-Y') ?: '-' }}</div>
            <div>Created by: {{ optional($sale->cashier)->name ?: '-' }}</div>
            @if($sale->quotation)
                <div>Quotation: <a href="{{ route('quotations.show', $sale->quotation) }}">{{ $sale->quotation->number }}</a></div>
            @endif
        </div>
        <div class="col-md-4">
            <div class="sx-invoice-label">Actions</div>
            <div class="sx-toolbar-actions" style="margin-top:6px;">
                @if($canPrint)
                    <a href="{{ route('sales.print', $sale) }}" target="_blank" class="btn btn-default btn-sm"><i class="fa fa-print"></i> Print</a>
                @endif
                <a href="{{ route('sales.show', $sale) }}" class="btn btn-primary btn-sm"><i class="fa fa-list"></i> Sale Details</a>
                @if($canPay)
                    <a href="{{ route('sales.show', $sale) }}" class="btn sx-btn-aqua btn-sm"><i class="fa fa-money"></i> Record Payment</a>
                @endif
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered sx-invoice-items">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Discount</th>
                    <th>Tax %</th>
                    <th>Tax Amt</th>
                    <th>Line Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->items as $item)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $item->displayName() }}</td>
                        <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 4), '0'), '.') }}</td>
                        <td>{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td>{{ number_format((float) $item->discount_amount, 2) }}</td>
                        <td>{{ number_format((float) $item->tax_rate, 2) }}</td>
                        <td>{{ number_format((float) $item->tax_amount, 2) }}</td>
                        <td>{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sx-po-totals">
        <div class="sx-po-totals-left">
            @if($sale->notes)
                <div class="form-group">
                    <label>Notes</label>
                    <div>{{ $sale->notes }}</div>
                </div>
            @endif
        </div>
        <div class="sx-po-totals-right">
            <div class="sx-po-total-row"><span>Subtotal</span><strong>Ksh {{ number_format((float) $sale->subtotal, 2) }}</strong></div>
            <div class="sx-po-total-row"><span>Discount @if((float) $sale->discount_percent > 0)({{ number_format((float) $sale->discount_percent, 2) }}%)@endif</span><strong>Ksh {{ number_format((float) $sale->discount_amount, 2) }}</strong></div>
            <div class="sx-po-total-row"><span>Tax</span><strong>Ksh {{ number_format((float) $sale->tax_amount, 2) }}</strong></div>
            <div class="sx-po-total-row"><span>Grand Total</span><strong>Ksh {{ number_format((float) $sale->total, 2) }}</strong></div>
            <div class="sx-po-total-row"><span>Paid</span><strong>Ksh {{ number_format((float) $sale->paid_amount, 2) }}</strong></div>
            <div class="sx-po-total-row"><span>Balance</span><strong>Ksh {{ number_format((float) $sale->balance, 2) }}</strong></div>
        </div>
    </div>
</div>
@endsection
