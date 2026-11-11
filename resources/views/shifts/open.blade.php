@extends('layouts.fleet')

@section('title', 'Open Shift')

@section('content')
<link rel="stylesheet" href="{{ asset('css/sellix-shifts.css') }}?v=1">
@include('layouts.partials.page-header', [
    'title' => 'Open Shift',
    'subtitle' => 'Confirm the float before selling',
    'backUrl' => route('dashboard'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Open Shift'],
    ],
])

<div class="row">
    <div class="col-md-8 col-md-offset-2">
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if(session('warning'))
            <div class="alert alert-warning">{{ session('warning') }}</div>
        @endif

        @if($existing)
            <div class="sx-acc-card">
                <div class="sx-acc-card-head">Shift already open</div>
                <div class="sx-acc-card-body">
                    <p>{{ $existing->number }} is still open from {{ optional($existing->opened_at)->timezone(config('sellix.timezone', 'Africa/Nairobi'))->format('d/m/Y H:i') }} at {{ optional($existing->branch)->name ?: 'another branch' }}. Close it before opening today's shift.</p>
                    <a href="{{ route('pos.shift.close') }}" class="btn btn-warning">Close that shift</a>
                </div>
            </div>
        @elseif($closedToday)
            <div class="sx-acc-card">
                <div class="sx-acc-card-head">Shift already closed today</div>
                <div class="sx-acc-card-body">
                    <p>{{ $closedToday->number }} is already closed for today. An administrator has to approve reopening it before you can sell again. The same shift and opening balance continue.</p>
                    @if($closedToday->reopen_requested_at)
                        <p>Waiting for an administrator to approve the reopen.</p>
                    @else
                        <form method="post" action="{{ route('pos.shift.reopen.request', $closedToday) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">Request reopen</button>
                        </form>
                    @endif
                </div>
            </div>
        @else
            <form method="post" action="{{ route('pos.shift.store') }}" class="sx-acc-card" id="sx-open-shift">
                @csrf
                <div class="sx-acc-card-head"><i class="fa fa-clock-o"></i> Opening balances</div>
                <div class="sx-acc-card-body">
                    <div class="row">
                        <div class="col-sm-6"><div class="sx-shift-figure"><span>Cashier</span><strong>{{ auth()->user()->name }}</strong></div></div>
                        <div class="col-sm-6"><div class="sx-shift-figure"><span>Branch</span><strong>{{ $branch->name ?? 'Branch' }}</strong></div></div>
                        <div class="col-sm-12"><div class="sx-shift-figure"><span>Opening time</span><strong>{{ now()->format('d/m/Y H:i') }}</strong></div></div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label>Cash opening balance (KSh)</label>
                                <input type="number" name="opening_cash" id="sx-open-cash" class="form-control input-lg" min="0" step="0.01" required value="{{ old('opening_cash', '0.00') }}">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label>M-Pesa opening balance (KSh)</label>
                                <input type="number" name="opening_mpesa" id="sx-open-mpesa" class="form-control input-lg" min="0" step="0.01" required value="{{ old('opening_mpesa', '0.00') }}">
                            </div>
                        </div>
                    </div>
                    <p class="sx-shift-total">Total opening balance: <span id="sx-open-total">KSh 0.00</span></p>
                    <div class="form-group">
                        <label>Opening remarks</label>
                        <textarea name="opening_remarks" class="form-control" rows="2" maxlength="1000">{{ old('opening_remarks') }}</textarea>
                    </div>
                    @if($errors->any())
                        <div class="alert alert-danger">{{ $errors->first() }}</div>
                    @endif
                    <button type="submit" class="btn btn-primary btn-lg">Confirm and open till</button>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    function money(value) {
        var n = Math.round((parseFloat(value) || 0) * 100) / 100;
        return 'KSh ' + n.toFixed(2);
    }
    function refresh() {
        var cash = parseFloat(document.getElementById('sx-open-cash').value) || 0;
        var mpesa = parseFloat(document.getElementById('sx-open-mpesa').value) || 0;
        document.getElementById('sx-open-total').textContent = money(cash + mpesa);
    }
    var cash = document.getElementById('sx-open-cash');
    if (!cash) return;
    cash.addEventListener('input', refresh);
    document.getElementById('sx-open-mpesa').addEventListener('input', refresh);
    refresh();
})();
</script>
@endpush
