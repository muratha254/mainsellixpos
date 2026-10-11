@extends('layouts.fleet')

@section('title', 'Loyalty Settings')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Loyalty Settings',
    'backUrl' => route('settings.company'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Loyalty', 'url' => route('customers.loyalty.index')],
        ['label' => 'Settings'],
    ],
])

<div class="sx-box">
    <div class="sx-box-body">
        <form method="post" action="{{ route('settings.loyalty.update') }}">
            @csrf
            @method('PUT')
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="sx-req">Loyalty program*</label>
                        <select name="loyalty_enabled" class="form-control" @if(! $canUpdate) disabled @endif>
                            <option value="1" @if($rules['enabled']) selected @endif>Enabled</option>
                            <option value="0" @if(! $rules['enabled']) selected @endif>Disabled</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="sx-req">How points are earned*</label>
                        <select name="loyalty_earn_mode" class="form-control" @if(! $canUpdate) disabled @endif>
                            <option value="spend" @if($rules['mode'] === 'spend') selected @endif>By amount spent</option>
                            <option value="item" @if($rules['mode'] === 'item') selected @endif>By item quantity</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="sx-req">Eligible products*</label>
                        <select name="loyalty_eligible" class="form-control" @if(! $canUpdate) disabled @endif>
                            <option value="all" @if($rules['eligible'] === 'all') selected @endif>All products</option>
                            <option value="selected" @if($rules['eligible'] === 'selected') selected @endif>Selected products only</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Points awarded*</label>
                        <input type="number" step="0.01" min="0" name="points_awarded" class="form-control" value="{{ old('points_awarded', $spendPoints) }}" @if(! $canUpdate) disabled @endif required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">For every KSh spent*</label>
                        <input type="number" step="0.01" min="0.01" name="spend_amount" class="form-control" value="{{ old('spend_amount', $spendAmount) }}" @if(! $canUpdate) disabled @endif required>
                        <small class="text-muted">Example: 1 point for every KSh 100.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Points per item*</label>
                        <input type="number" step="0.01" min="0" name="loyalty_points_per_item" class="form-control" value="{{ old('loyalty_points_per_item', $rules['points_per_item']) }}" @if(! $canUpdate) disabled @endif required>
                        <small class="text-muted">Used when earning is by item quantity.</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Points expire after (days)*</label>
                        <input type="number" min="0" name="loyalty_expiry_days" class="form-control" value="{{ old('loyalty_expiry_days', $rules['expiry_days']) }}" @if(! $canUpdate) disabled @endif required>
                        <small class="text-muted">0 means points do not expire.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="sx-req">Minimum points to redeem*</label>
                        <input type="number" step="0.01" min="0" name="loyalty_min_redeem" class="form-control" value="{{ old('loyalty_min_redeem', $rules['min_redeem']) }}" @if(! $canUpdate) disabled @endif required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="sx-req">KSh value of 1 point*</label>
                        <input type="number" step="0.01" min="0" name="loyalty_point_value" class="form-control" value="{{ old('loyalty_point_value', $rules['point_value']) }}" @if(! $canUpdate) disabled @endif required>
                        <small class="text-muted">The POS uses this amount. 1.00 means 100 points take KSh 100 off a sale.</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Maximum points per sale</label>
                        <input type="number" step="0.01" min="0" name="loyalty_max_redeem" class="form-control" value="{{ old('loyalty_max_redeem', $rules['max_redeem']) }}" @if(! $canUpdate) disabled @endif>
                        <small class="text-muted">0 means no limit.</small>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="form-group">
                        <label>Selected products</label>
                        <div style="max-height:240px; overflow:auto; border:1px solid #d2d6de; padding:8px;">
                            @forelse($products as $product)
                                <label class="checkbox-inline" style="display:block; margin:0 0 6px;">
                                    <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @if(in_array($product->id, $rules['product_ids'], true)) checked @endif @if(! $canUpdate) disabled @endif>
                                    {{ $product->name }} @if($product->sku) ({{ $product->sku }}) @endif
                                </label>
                            @empty
                                <p class="text-muted">No active products yet.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
            @if($canUpdate)
                <button type="submit" class="btn btn-success">Save settings</button>
            @endif
        </form>
    </div>
</div>
@endsection
