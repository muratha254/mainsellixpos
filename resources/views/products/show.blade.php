@extends('layouts.fleet')

@section('title', 'Item profile')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Item profile',
    'backUrl' => route('products.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Item', 'url' => route('products.index')],
        ['label' => 'Profile'],
    ],
])

@php
    $money = function ($amount) use ($currencyCode) {
        return $currencyCode . ' ' . number_format((float) $amount, 2);
    };
    $currentBranch = $branch->name ?? 'MAIN';
    $remainingDisplay = $product->manage_stock
        ? $conversion->stockDisplay($product, $quantity)
        : '—';
    $soldDisplay = $product->manage_stock
        ? $conversion->stockDisplay($product, $soldQty ?? 0)
        : '—';
    $basePrice = $conversion->pricePerBase($product);
    $packPrice = $conversion->packSellingPrice($product);
    $packWholesale = (float) $product->wholesale_price;
    $baseWholesale = $conversion->wholesalePerBase($product);
    $hasPackaging = $conversion->hasPackaging($product);
    $baseUnit = optional($product->unit)->short_name ?: optional($product->unit)->name ?: 'unit';
    $packUnit = optional($product->purchaseUnit)->short_name ?: optional($product->purchaseUnit)->name ?: $baseUnit;
    $qtyFmt = function ($qty) use ($conversion) {
        return $conversion->formatQty($qty);
    };
@endphp

<div class="sx-profile-card">
    <div class="sx-profile-top">
        <div class="sx-profile-photo">
            @if($product->image_path)
                <img src="{{ asset('storage/' . $product->image_path) }}" alt="{{ $product->name }}">
            @else
                <div class="sx-no-photo" aria-hidden="true">
                    <i class="fa fa-camera"></i>
                </div>
            @endif
        </div>
        <div class="sx-profile-info">
            <h3 class="sx-basic-title">Basic Info</h3>
            <div class="sx-info-grid">
                <div><span>Name:</span> <strong>{{ $product->name }}</strong></div>
                <div><span>Code:</span> <strong>{{ $product->item_code }}</strong></div>
                <div><span>Unit:</span> <strong>{{ optional($product->unit)->short_name ?: optional($product->unit)->name ?: '-' }}</strong></div>
                <div><span>Purchase unit:</span> <strong>{{ optional($product->purchaseUnit)->short_name ?: optional($product->unit)->short_name ?: '-' }}</strong></div>
                <div><span>Brand:</span> <strong>{{ optional($product->brand)->name ?: '-' }}</strong></div>
                <div><span>Category:</span> <strong>{{ optional($product->category)->name ?: '-' }}</strong></div>
                @if($hasPackaging)
                    <div><span>Selling ({{ $packUnit }}):</span> <strong>{{ $money($packPrice) }}</strong></div>
                    <div><span>Selling / {{ $baseUnit }}:</span> <strong>{{ $money($basePrice) }}</strong></div>
                    <div><span>Wholesale ({{ $packUnit }}):</span> <strong>{{ $money($packWholesale) }}</strong></div>
                    <div><span>Wholesale / {{ $baseUnit }}:</span> <strong>{{ $money($baseWholesale) }}</strong></div>
                @else
                    <div><span>Selling price:</span> <strong>{{ $money($packPrice) }}</strong></div>
                    <div><span>Wholesale price:</span> <strong>{{ $money($packWholesale) }}</strong></div>
                @endif
                <div><span>Remaining:</span> <strong>{{ $remainingDisplay }}</strong></div>
                <div><span>Sold:</span> <strong>{{ $soldDisplay }}</strong></div>
                <div><span>Alert Qty:</span> <strong>{{ (float) $product->reorder_level == (int) $product->reorder_level ? (int) $product->reorder_level : $product->reorder_level }}</strong></div>
                <div><span>Tax Type:</span> <strong>{{ $product->taxLabel() }}</strong></div>
                <div><span>Expiry Date:</span> <strong>{{ optional($product->expiry_date)->format('d-m-Y') ?: '-' }}</strong></div>
                <div class="sx-info-wide"><span>Description:</span> <strong>{{ $product->description ?: '-' }}</strong></div>
            </div>
            <div class="sx-profile-actions">
                @if($canUpdate)
                    <form action="{{ route('products.image', $product) }}" method="post" enctype="multipart/form-data" class="sx-inline-upload">
                        @csrf
                        <input type="file" name="image" id="sx-change-image" accept="image/*" onchange="this.form.submit()">
                        <button type="button" class="btn btn-primary btn-xs" onclick="document.getElementById('sx-change-image').click()">Change image</button>
                    </form>
                    <a href="{{ route('products.edit', $product) }}" class="btn btn-success btn-xs">Edit Info</a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="sx-profile-section">
    <div class="sx-ribbon">Item movement</div>
    <div class="table-responsive">
        <table class="table table-bordered sx-cyan-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Action</th>
                    <th>Reference</th>
                    <th>Sold / entered</th>
                    <th class="text-right">In</th>
                    <th class="text-right">Out</th>
                    <th class="text-right">Balance</th>
                    <th>User</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @forelse($movements as $movement)
                    <tr>
                        <td>{{ optional($movement->occurred_at)->format('d-m-Y H:i') }}</td>
                        <td>{{ $movement->typeLabel() }}</td>
                        <td>{{ $movement->reference_number ?: '-' }}</td>
                        <td>
                            @if($movement->quantity_entered !== null && $movement->unit_name)
                                {{ $qtyFmt($movement->quantity_entered) }} {{ $movement->unit_name }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-right">{{ (float) $movement->quantity_in > 0 ? $qtyFmt($movement->quantity_in) : '—' }}</td>
                        <td class="text-right">{{ (float) $movement->quantity_out > 0 ? $qtyFmt($movement->quantity_out) : '—' }}</td>
                        <td class="text-right">{{ $conversion->stockDisplay($product, (float) $movement->quantity_after) }}</td>
                        <td>{{ optional($movement->user)->name ?: '-' }}</td>
                        <td>{{ $movement->notes ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="sx-none-found">No stock movement recorded for this item at the active branch.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="sx-profile-section">
    <div class="sx-ribbon">Batches</div>
    <div class="table-responsive">
        <table class="table table-bordered sx-cyan-table">
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Branch</th>
                    <th>Date</th>
                    <th>Expiry</th>
                    @if(can_view_item_buying_price())<th>Cost P</th>@endif
                    <th>Retail P</th>
                    <th>Wholesale P</th>
                    <th>Promotion P</th>
                    <th>Stocked Qty</th>
                    <th>Balance Qty</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($batches as $index => $batch)
                    <tr>
                        <td>{{ $index + 1 }}.</td>
                        <td>{{ optional($batch->branch)->name }}</td>
                        <td>{{ optional($batch->batch_date)->format('Y-m-d') }}</td>
                        <td>{{ optional($batch->expiry_date)->format('Y-m-d') }}</td>
                        @if(can_view_item_buying_price())<td>{{ number_format((float) $batch->cost_price, 2) }}</td>@endif
                        <td>{{ number_format((float) $batch->retail_price, 2) }}</td>
                        <td>{{ number_format((float) $batch->wholesale_price, 2) }}</td>
                        <td>{{ number_format((float) $batch->promo_price, 2) }}</td>
                        <td>{{ $conversion->stockDisplay($product, (float) $batch->stocked_qty, 'base') }}</td>
                        <td>{{ $batches->count() === 1 ? $remainingDisplay : $conversion->stockDisplay($product, (float) $batch->balance_qty, 'base') }}</td>
                        <td>
                            @if($batch->is_active)
                                <span class="label label-success">Active</span>
                            @else
                                <span class="label label-default">Inactive</span>
                            @endif
                        </td>
                        <td>
                            @if($canCreate)
                                <button type="button" class="btn btn-success btn-xs sx-open-child-stock"
                                    data-batch="{{ $batch->id }}"
                                    data-name="{{ $product->name }}"
                                    data-rate="{{ $product->conversion_rate ?: 1 }}"
                                    data-url="{{ route('products.child-stock.store', $product) }}">
                                    Create Child <i class="fa fa-download"></i>
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="sx-none-found">No record found!!!</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($product->has_variants)
<div class="sx-profile-section">
    <div class="sx-orange-bar">Variations</div>
    <div class="table-responsive">
        <table class="table table-bordered sx-cyan-table">
            <thead>
                <tr>
                    <th>Variation</th>
                    <th>SKU</th>
                    <th>Barcode</th>
                    @if(can_view_item_buying_price())<th>Purchase Price</th>@endif
                    <th>Sale Price</th>
                    <th>Available Stock</th>
                    <th>Low Stock</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($product->variants as $variant)
                    <tr>
                        <td>{{ $variant->label() }}</td>
                        <td>{{ $variant->sku ?: '—' }}</td>
                        <td>{{ $variant->barcode ?: '—' }}</td>
                        @if(can_view_item_buying_price())
                            <td>{{ $variant->purchase_price !== null ? number_format((float) $variant->purchase_price, 2) : '—' }}</td>
                        @endif
                        <td>{{ $variant->selling_price !== null ? number_format((float) $variant->selling_price, 2) : number_format((float) $product->selling_price, 2) }}</td>
                        <td>{{ number_format((float) ($variationStock[$variant->id] ?? 0), 2) }}</td>
                        <td>{{ $variant->reorder_level !== null ? $variant->reorder_level : '—' }}</td>
                        <td>
                            @if($variant->is_active)
                                <span class="label label-success">Active</span>
                            @else
                                <span class="label label-default">Inactive</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">No variations yet. Edit the item to add them.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="help-block">Edit the item to add, change, or deactivate a variation. Variations with sales or stock history stay on the item.</p>
</div>
@endif

<div class="sx-profile-section">
    <div class="sx-orange-bar">Child Item</div>
    <div class="table-responsive">
        <table class="table table-bordered sx-cyan-table">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Branch</th>
                    @if(can_view_item_buying_price())<th>Purchase Price</th>@endif
                    <th>Sale Price</th>
                    <th>Available Stock</th>
                    <th>Reorder Level</th>
                    <th>Control Qty</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($children as $child)
                    <tr>
                        <td>{{ $child->name }}</td>
                        <td>{{ $currentBranch }}</td>
                        @if(can_view_item_buying_price())<td>{{ number_format((float) $child->purchase_price, 2) }}</td>@endif
                        <td>{{ number_format((float) $child->selling_price, 2) }}</td>
                        <td>{{ number_format((float) ($childStock[$child->id] ?? 0), 2) }}</td>
                        <td>{{ (float) $child->reorder_level == (int) $child->reorder_level ? (int) $child->reorder_level : $child->reorder_level }}</td>
                        <td>{{ (float) $child->conversion_rate == (int) $child->conversion_rate ? (int) $child->conversion_rate : $child->conversion_rate }}</td>
                        <td>
                            @if($child->is_active)
                                <span class="label label-success">Active</span>
                            @else
                                <span class="label label-default">Inactive</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('products.show', $child) }}" class="btn btn-primary btn-xs">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="sx-none-found">No record found!!!</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('products.partials.child-stock-modal')
@endsection

@push('scripts')
@include('products.partials.child-stock-script')
@endpush
