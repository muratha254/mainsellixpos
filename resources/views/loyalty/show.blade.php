@extends('layouts.fleet')

@section('title', 'Loyalty - ' . $customer->name)

@section('content')
@include('layouts.partials.page-header', [
    'title' => $customer->name,
    'subtitle' => 'Loyalty points',
    'backUrl' => route('customers.loyalty.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Loyalty', 'url' => route('customers.loyalty.index')],
        ['label' => $customer->name],
    ],
])

<div class="row">
    @foreach([
        ['Available', number_format($summary['available'], 2)],
        ['Value', 'Ksh ' . number_format($summary['value'], 2)],
        ['Earned', number_format($summary['earned'], 2)],
        ['Redeemed', number_format($summary['redeemed'], 2)],
        ['Pending', number_format($summary['pending'], 2)],
        ['Minimum to redeem', number_format($summary['min_redeem'], 2)],
    ] as $card)
        <div class="col-sm-6 col-md-2">
            <div class="sx-box">
                <div class="sx-box-body">
                    <div class="text-muted">{{ $card[0] }}</div>
                    <strong>{{ $card[1] }}</strong>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if($canAdjust)
<div class="sx-box">
    <div class="sx-box-body">
        <form method="post" action="{{ route('customers.loyalty.adjust', $customer) }}" class="form-inline">
            @csrf
            <select name="direction" class="form-control" required>
                <option value="award">Award</option>
                <option value="deduct">Deduct</option>
            </select>
            <input type="number" step="0.01" min="0.01" name="points" class="form-control" placeholder="Points" required>
            <input type="text" name="reason" class="form-control" placeholder="Reason" required style="min-width:240px;">
            <button type="submit" class="btn btn-success">Save adjustment</button>
        </form>
    </div>
</div>
@endif

<div class="sx-box">
    <div class="sx-box-body">
        <div class="table-responsive">
            <table class="table table-bordered sx-gold-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-right">Points</th>
                        <th class="text-right">Balance</th>
                        <th>Sale</th>
                        <th>Reference</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $row)
                        <tr>
                            <td>{{ optional($row->created_at)->format('d-m-Y H:i') }}</td>
                            <td>{{ ucfirst($row->type) }}</td>
                            <td>{{ ucfirst($row->status ?: 'posted') }}</td>
                            <td class="text-right">{{ number_format((float) $row->points, 2) }}</td>
                            <td class="text-right">{{ number_format((float) $row->balance_after, 2) }}</td>
                            <td>{{ optional($row->sale)->number ?: '-' }}</td>
                            <td>{{ $row->reference }}</td>
                            <td>{{ $row->notes }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No loyalty transactions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
