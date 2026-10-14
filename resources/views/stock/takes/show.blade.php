@extends('layouts.fleet')
@section('title', $take->number)
@include('accounting.partials.datatable-css')

@section('content')
@include('layouts.partials.page-header', [
    'title' => $take->number,
    'subtitle' => optional($take->branch)->name . ' · ' . $take->statusLabel(),
    'backUrl' => route('stock.takes.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Stock Take', 'url' => route('stock.takes.index')],
        ['label' => $take->number],
    ],
])

@php
    $cards = [
        ['label' => 'Products included', 'value' => $summary['included']],
        ['label' => 'Counted', 'value' => $summary['counted']],
        ['label' => 'Not counted', 'value' => $summary['uncounted']],
        ['label' => 'Matched', 'value' => $summary['matched']],
        ['label' => 'Shortages', 'value' => $summary['shortages']],
        ['label' => 'Surpluses', 'value' => $summary['surpluses']],
        ['label' => 'Units short', 'value' => rtrim(rtrim(number_format($summary['units_short'], 4, '.', ''), '0'), '.') ?: '0'],
        ['label' => 'Units surplus', 'value' => rtrim(rtrim(number_format($summary['units_surplus'], 4, '.', ''), '0'), '.') ?: '0'],
    ];
    $filters = [
        'all' => 'All',
        'shortage' => 'Shortage',
        'surplus' => 'Surplus',
        'matched' => 'Matched',
        'uncounted' => 'Uncounted',
    ];
@endphp

<div class="row">
    @foreach($cards as $card)
        <div class="col-xs-6 col-sm-3" style="margin-bottom:12px;">
            <div class="sx-box" style="margin-bottom:0;">
                <div class="sx-box-body" style="min-height:auto; padding:12px 14px;">
                    <div style="color:#777; font-size:12px;">{{ $card['label'] }}</div>
                    <div style="font-size:20px; font-weight:600;">{{ $card['value'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="sx-box">
    <div class="sx-box-body" style="min-height:auto;">
        <div class="sx-toolbar-actions" style="margin-bottom:12px;">
            @if($canDownload)
                <a href="{{ route('stock.takes.excel', $take) }}" class="btn btn-default"><i class="fa fa-file-excel-o"></i> Excel</a>
                <a href="{{ route('stock.takes.pdf', $take) }}" class="btn btn-default"><i class="fa fa-file-pdf-o"></i> PDF</a>
            @endif
            @if($take->status === \App\Models\StockTake::STATUS_UPLOADED && $canUpload)
                <form method="post" action="{{ route('stock.takes.submit', $take) }}" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;">
                    @csrf
                    <button type="submit" class="btn btn-primary">Submit for Approval</button>
                </form>
            @endif
            @if($canApprove)
                <form method="post" action="{{ route('stock.takes.approve', $take) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-success">Approve</button>
                </form>
            @endif
            @if($canReconcile)
                <form method="post" action="{{ route('stock.takes.reconcile', $take) }}" style="display:inline;" onsubmit="return confirm('Reconcile stock to the counted quantities? Blank counts will be left unchanged.');">
                    @csrf
                    <button type="submit" class="btn btn-warning">Reconcile Stock</button>
                </form>
            @endif
            @if($canCancel)
                <form method="post" action="{{ route('stock.takes.cancel', $take) }}" style="display:inline;" onsubmit="return confirm('Cancel this stock take? Inventory will not be changed.');">
                    @csrf
                    <button type="submit" class="btn btn-danger">Cancel</button>
                </form>
            @endif
        </div>

        @if($canUpload)
            <form method="post" action="{{ route('stock.takes.upload', $take) }}" enctype="multipart/form-data" class="form-inline" style="margin-bottom:14px;" id="sx-take-upload">
                @csrf
                <div class="form-group">
                    <label class="sr-only" for="sx-take-sheet">Completed Excel sheet</label>
                    <input type="file" name="sheet" id="sx-take-sheet" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <button type="submit" class="btn btn-primary">Upload Count Sheet</button>
                <p class="help-block" style="margin-top:8px;">Leave Counted Quantity blank for items you did not count. A blank cell is not zero. Inventory is not updated until this sheet is approved and reconciled.</p>
            </form>
        @endif

        <p>
            Created by {{ optional($take->creator)->name ?: '—' }}
            on {{ optional($take->created_at)->format('Y-m-d H:i') }}.
            @if($take->approved_at)
                Approved by {{ optional($take->approver)->name ?: '—' }} on {{ $take->approved_at->format('Y-m-d H:i') }}.
            @endif
            @if($take->reconciled_at)
                Reconciled by {{ optional($take->reconciler)->name ?: '—' }} on {{ $take->reconciled_at->format('Y-m-d H:i') }}.
            @endif
        </p>

        <div style="margin-bottom:12px;">
            @foreach($filters as $key => $label)
                <a href="{{ route('stock.takes.show', ['stockTake' => $take, 'variance' => $key]) }}" class="btn btn-xs {{ $filter === $key ? 'btn-primary' : 'btn-default' }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="table-responsive">
            <table class="table table-bordered sx-gold-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>SKU</th>
                        <th class="text-right">System Qty</th>
                        @if($take->status !== \App\Models\StockTake::STATUS_RECONCILED)
                            <th class="text-right">Current Qty</th>
                        @endif
                        <th class="text-right">Counted Qty</th>
                        <th class="text-right">Variance</th>
                        @if($canViewCost)
                            <th class="text-right">Value</th>
                        @endif
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lines as $line)
                        @php
                            $key = $line->product_id . ':' . (int) $line->product_variant_id;
                            $variance = $line->variance();
                            $status = $line->countStatus();
                            $cost = $costByLine[$key] ?? null;
                        @endphp
                        <tr>
                            <td>{{ $line->item_name }}</td>
                            <td>{{ $line->sku }}</td>
                            <td class="text-right">{{ rtrim(rtrim(number_format((float) $line->system_quantity, 4, '.', ''), '0'), '.') ?: '0' }}</td>
                            @if($take->status !== \App\Models\StockTake::STATUS_RECONCILED)
                                <td class="text-right">{{ array_key_exists($key, $currentQty) ? (rtrim(rtrim(number_format($currentQty[$key], 4, '.', ''), '0'), '.') ?: '0') : '—' }}</td>
                            @endif
                            <td class="text-right">{{ $line->counted_quantity === null ? '' : (rtrim(rtrim(number_format((float) $line->counted_quantity, 4, '.', ''), '0'), '.') ?: '0') }}</td>
                            <td class="text-right">
                                @if($variance === null)
                                @elseif($variance > 0)
                                    +{{ rtrim(rtrim(number_format($variance, 4, '.', ''), '0'), '.') }}
                                @else
                                    {{ rtrim(rtrim(number_format($variance, 4, '.', ''), '0'), '.') ?: '0' }}
                                @endif
                            </td>
                            @if($canViewCost)
                                <td class="text-right">
                                    @if($variance !== null && $cost !== null)
                                        {{ number_format($variance * $cost, 2) }}
                                    @endif
                                </td>
                            @endif
                            <td>{{ $status }}</td>
                            <td>{{ $line->notes }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No lines match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    var upload = document.getElementById('sx-take-upload');
    if (upload) {
        upload.addEventListener('submit', function () {
            var button = upload.querySelector('button');
            button.disabled = true;
            button.textContent = 'Uploading...';
        });
    }
</script>
@endpush
