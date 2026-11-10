@extends('layouts.fleet')

@section('title', 'Close Shift')

@section('content')
<link rel="stylesheet" href="{{ asset('css/sellix-shifts.css') }}?v=1">
@include('layouts.partials.page-header', [
    'title' => 'Close Shift',
    'subtitle' => $shift->number . ' · ' . $shift->cashier->name,
    'backUrl' => route('pos.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'POS', 'url' => route('pos.index')],
        ['label' => 'Close Shift'],
    ],
])

@php
    $rows = [
        ['Opening cash', $figures['opening_cash']],
        ['Opening M-Pesa', $figures['opening_mpesa']],
        ['Cash sales', $figures['cash_sales']],
        ['M-Pesa sales', $figures['mpesa_sales']],
        ['Card sales', $figures['card_sales']],
        ['Bank transfer sales', $figures['bank_transfer_sales']],
        ['Other sales', $figures['other_sales']],
        ['Cash refunds', $figures['cash_refunds']],
        ['M-Pesa refunds', $figures['mpesa_refunds']],
        ['Cash expenses', $figures['cash_expenses']],
        ['M-Pesa expenses', $figures['mpesa_expenses']],
    ];
@endphp

<div class="row">
    <div class="col-md-7">
        <div class="sx-acc-card">
            <div class="sx-acc-card-head">System figures</div>
            <div class="sx-acc-card-body">
                <table class="table table-striped">
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td>{{ $row[0] }}</td>
                                <td class="text-right">{{ format_kes($row[1]) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <th>Expected closing cash</th>
                            <th class="text-right" id="sx-expected-cash" data-amount="{{ $figures['expected_cash'] }}">{{ format_kes($figures['expected_cash']) }}</th>
                        </tr>
                        <tr>
                            <th>Expected closing M-Pesa</th>
                            <th class="text-right" id="sx-expected-mpesa" data-amount="{{ $figures['expected_mpesa'] }}">{{ format_kes($figures['expected_mpesa']) }}</th>
                        </tr>
                        <tr>
                            <th>Expected total</th>
                            <th class="text-right">{{ format_kes($figures['expected_total']) }}</th>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <form method="post" action="{{ route('pos.shift.close.store') }}" class="sx-acc-card">
            @csrf
            <div class="sx-acc-card-head">Counted balances</div>
            <div class="sx-acc-card-body">
                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif
                @if($errors->any())
                    <div class="alert alert-danger">{{ $errors->first() }}</div>
                @endif
                <div class="form-group">
                    <label>Actual physical cash counted (KSh)</label>
                    <input type="number" name="actual_cash" id="sx-actual-cash" class="form-control input-lg" min="0" step="0.01" required value="{{ old('actual_cash') }}">
                </div>
                <div class="form-group">
                    <label>Actual M-Pesa balance (KSh)</label>
                    <input type="number" name="actual_mpesa" id="sx-actual-mpesa" class="form-control input-lg" min="0" step="0.01" required value="{{ old('actual_mpesa') }}">
                </div>
                <div class="sx-shift-figure">
                    <span>Cash variance</span>
                    <strong id="sx-var-cash">Enter the count</strong>
                </div>
                <div class="sx-shift-figure">
                    <span>M-Pesa variance</span>
                    <strong id="sx-var-mpesa">Enter the count</strong>
                </div>
                <div class="sx-shift-figure">
                    <span>Total variance</span>
                    <strong id="sx-var-total">Enter the count</strong>
                </div>
                <div class="form-group">
                    <label>Closing remarks</label>
                    <textarea name="closing_remarks" class="form-control" rows="2" maxlength="1000">{{ old('closing_remarks') }}</textarea>
                </div>
                <button type="submit" class="btn btn-warning btn-lg">Submit cash-up</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var expectedCash = parseFloat(document.getElementById('sx-expected-cash').getAttribute('data-amount')) || 0;
    var expectedMpesa = parseFloat(document.getElementById('sx-expected-mpesa').getAttribute('data-amount')) || 0;
    function paint(id, variance) {
        var el = document.getElementById(id);
        if (!isFinite(variance)) {
            el.textContent = 'Enter the count';
            el.className = '';
            return;
        }
        var label = Math.abs(variance) < 0.01 ? 'Balanced' : (variance < 0 ? 'Shortage' : 'Surplus');
        el.textContent = (variance < -0.004 ? '-' : '') + 'KSh ' + Math.abs(variance).toFixed(2) + ' (' + label + ')';
        el.className = Math.abs(variance) < 0.01 ? 'sx-shift-even' : (variance < 0 ? 'sx-shift-short' : 'sx-shift-over');
    }
    function refresh() {
        var cashRaw = document.getElementById('sx-actual-cash').value;
        var mpesaRaw = document.getElementById('sx-actual-mpesa').value;
        var cashVar = cashRaw === '' ? NaN : (parseFloat(cashRaw) - expectedCash);
        var mpesaVar = mpesaRaw === '' ? NaN : (parseFloat(mpesaRaw) - expectedMpesa);
        paint('sx-var-cash', cashVar);
        paint('sx-var-mpesa', mpesaVar);
        paint('sx-var-total', (isFinite(cashVar) && isFinite(mpesaVar)) ? (cashVar + mpesaVar) : NaN);
    }
    document.getElementById('sx-actual-cash').addEventListener('input', refresh);
    document.getElementById('sx-actual-mpesa').addEventListener('input', refresh);
    refresh();
})();
</script>
@endpush
