@extends('layouts.fleet')
@section('title', 'Transfer ' . $transfer->number)

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Stock Transfer',
    'subtitle' => $transfer->number,
    'backUrl' => route('stock.transfers.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Stock Transfers', 'url' => route('stock.transfers.index')],
        ['label' => $transfer->number],
    ],
])

<div class="sx-box sx-items-card">
    <div class="sx-items-toolbar">
        <h3 class="sx-box-title" style="flex:none;margin:0;">{{ $transfer->number }}</h3>
        <div class="sx-toolbar-actions">
            @php
                $isSender = (int) $currentBranchId === (int) $transfer->from_branch_id;
                $isReceiver = (int) $currentBranchId === (int) $transfer->to_branch_id;
            @endphp
            @if($canTransfer && $transfer->status === 'draft' && $isSender)
                <form method="post" action="{{ route('stock.transfers.complete', $transfer) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-success"><i class="fa fa-paper-plane"></i> Send to branch</button>
                </form>
                <form method="post" action="{{ route('stock.transfers.cancel', $transfer) }}" style="display:inline;" onsubmit="return confirm('Cancel this transfer?');">
                    @csrf
                    <button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> Cancel</button>
                </form>
            @endif
            @if($canTransfer && $transfer->status === 'pending' && $isReceiver)
                <form method="post" action="{{ route('stock.transfers.receive', $transfer) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Receive</button>
                </form>
                <form method="post" action="{{ route('stock.transfers.reject', $transfer) }}" style="display:inline;" onsubmit="return confirm('Reject this transfer and return the items to the sending branch?');">
                    @csrf
                    <button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> Reject</button>
                </form>
            @endif
            @if($canTransfer && $transfer->status === 'pending' && $isSender)
                <form method="post" action="{{ route('stock.transfers.cancel', $transfer) }}" style="display:inline;" onsubmit="return confirm('Cancel this transfer and return the items to this branch?');">
                    @csrf
                    <button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> Cancel send</button>
                </form>
            @endif
            <a href="{{ route('stock.transfers.index') }}" class="btn btn-default">Back</a>
        </div>
    </div>
    <div class="sx-box-body sx-items-body">
        <div class="row" style="margin-bottom:16px;">
            <div class="col-md-3"><strong>Date:</strong> {{ optional($transfer->transfer_date)->format('Y-m-d') }}</div>
            <div class="col-md-3"><strong>From:</strong> {{ optional($transfer->fromBranch)->name }}</div>
            <div class="col-md-3"><strong>To:</strong> {{ optional($transfer->toBranch)->name }}</div>
            <div class="col-md-3">
                <strong>Status:</strong>
                @if($transfer->status === 'completed')
                    <span class="sx-status-active">{{ $transfer->statusLabel() }}</span>
                @elseif(in_array($transfer->status, ['cancelled', 'rejected'], true))
                    <span class="sx-status-inactive">{{ $transfer->statusLabel() }}</span>
                @else
                    <span class="label label-warning">{{ $transfer->statusLabel() }}</span>
                @endif
            </div>
        </div>
        @if($transfer->notes)
            <p><strong>Notes:</strong> {{ $transfer->notes }}</p>
        @endif

        <div class="table-responsive">
            <table class="table table-bordered sx-gold-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Unit Cost</th>
                        <th class="text-right">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transfer->items as $i => $item)
                        @php
                            $qty = (float) $item->quantity;
                            $cost = (float) $item->unit_cost;
                        @endphp
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ optional($item->product)->name }}</td>
                            <td class="text-right">{{ number_format($qty, 4) }}</td>
                            <td class="text-right">{{ number_format($cost, 4) }}</td>
                            <td class="text-right">{{ number_format($qty * $cost, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-muted" style="margin-top:12px;">
            Posted by {{ optional($transfer->user)->name ?: '-' }}
            @if($transfer->status === 'pending')
                · Waiting for {{ optional($transfer->toBranch)->name ?: 'the receiving branch' }} to receive or reject
            @endif
            @if($transfer->completed_at) · Received {{ $transfer->completed_at }} @endif
            @if($transfer->cancelled_at) · Closed {{ $transfer->cancelled_at }} @endif
        </p>
    </div>
</div>
@endsection
