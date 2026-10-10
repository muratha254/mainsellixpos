@extends('layouts.fleet')

@php
    $isEdit = $product->exists;
    $selectedBranch = old('branch_id', $selectedBranchId ?? session('current_branch_id'));
    $forSale = old('for_sale', $product->for_sale ? '1' : '0');
    $manageStock = old('manage_stock', $product->manage_stock ? '1' : '0');
    $allowNegative = old('allow_negative_stock', $product->allow_negative_stock ? '1' : '0');
    $taxInclusive = old('tax_inclusive', $product->tax_inclusive ? '1' : '0');
@endphp

@section('title', 'Items')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Items',
    'subtitle' => 'Add/Update Items',
    'backUrl' => route('products.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Items List', 'url' => route('products.index')],
        ['label' => 'Items'],
    ],
])

<form class="sx-item-form" action="{{ $isEdit ? route('products.update', $product) : route('products.store') }}" method="post" enctype="multipart/form-data">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div class="sx-box">
        <div class="sx-register-bar">Register Item</div>
        <div class="sx-box-body" style="min-height:auto;">
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Branch *</label>
                        <select name="branch_id" class="form-control" required>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @if((string) $selectedBranch === (string) $branch->id) selected @endif>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Item Name*</label>
                        <input type="text" name="name" class="form-control" placeholder="Product Name" value="{{ old('name', $product->name) }}" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Brand</label>
                        <div class="input-group">
                            <select name="brand_id" id="brand_id" class="form-control">
                                <option value="">-Select-</option>
                                @foreach($brands as $brand)
                                    <option value="{{ $brand->id }}" @if(old('brand_id', $product->brand_id) == $brand->id) selected @endif>{{ $brand->name }}</option>
                                @endforeach
                            </select>
                            @if(auth()->user()->hasPermission('brands.manage'))
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-info sx-plus-btn" data-quick="brand" title="Add brand"><i class="fa fa-plus"></i></button>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Category *</label>
                        <div class="input-group">
                            <select name="category_id" id="category_id" class="form-control" required>
                                <option value="">-Select-</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" @if(old('category_id', $product->category_id) == $category->id) selected @endif>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @if(auth()->user()->hasPermission('categories.manage'))
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-info sx-plus-btn" data-quick="category" title="Add category"><i class="fa fa-plus"></i></button>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Stock / Base Unit*</label>
                        <div class="input-group">
                            <select name="unit_id" id="unit_id" class="form-control" required>
                                <option value="">-Select-</option>
                                @foreach($units as $unit)
                                    <option value="{{ $unit->id }}" @if(old('unit_id', $product->unit_id) == $unit->id) selected @endif>{{ $unit->name }} ({{ $unit->short_name }})</option>
                                @endforeach
                            </select>
                            @if(auth()->user()->hasPermission('units.manage'))
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-info sx-plus-btn" data-quick="unit" title="Add unit"><i class="fa fa-plus"></i></button>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Alert Quantity</label>
                        <input type="number" step="0.01" min="0" name="reorder_level" class="form-control" value="{{ old('reorder_level', $product->reorder_level ?? 0) }}">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Barcode</label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fa fa-barcode"></i></span>
                            <input type="text" name="barcode" id="sx-item-barcode" class="form-control" placeholder="Scan barcode" title="Scan the item. The POS scanner finds it with this code." autocomplete="off" value="{{ old('barcode', $product->barcode) }}">
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>SKU</label>
                        <input type="text" name="sku" class="form-control" placeholder="Item code" value="{{ old('sku', $product->sku) }}">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Expire Date <span class="sx-format">Format(dd-mm-yyyy)</span></label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fa fa-calendar"></i></span>
                            <input type="date" name="expiry_date" class="form-control" placeholder="Expiry Date DD-MM-YYYY" value="{{ old('expiry_date', optional($product->expiry_date)->format('Y-m-d')) }}">
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Purpose <i class="fa fa-question-circle" title="State if For Sale or Not"></i> <span class="sx-hint">State if For Sale or Not</span></label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fa fa-anchor"></i></span>
                            <select name="for_sale" class="form-control">
                                <option value="1" @if($forSale == '1') selected @endif>For Sale</option>
                                <option value="0" @if($forSale == '0') selected @endif>Not For Sale</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Manage Stock <i class="fa fa-info-circle sx-info" title="Track quantity on hand for this item"></i></label>
                        <select name="manage_stock" class="form-control">
                            <option value="1" @if($manageStock == '1') selected @endif>Yes</option>
                            <option value="0" @if($manageStock == '0') selected @endif>No</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Allow -ve Sale <i class="fa fa-info-circle sx-info" title="Allow selling when stock is below zero"></i></label>
                        <select name="allow_negative_stock" class="form-control">
                            <option value="0" @if($allowNegative == '0') selected @endif>No</option>
                            <option value="1" @if($allowNegative == '1') selected @endif>Yes</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Tax Category*</label>
                        <select name="tax_id" class="form-control" required>
                            <option value="">-Select-</option>
                            @foreach($taxes as $tax)
                                <option value="{{ $tax->id }}" @if(old('tax_id', $product->tax_id) == $tax->id) selected @endif>{{ $tax->name }} ({{ $tax->rate }}%)</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Purchase Price*</label>
                        <input type="number" step="0.01" min="0" name="purchase_price" id="purchase_price" class="form-control" placeholder="Total Price with Tax Amount" value="{{ old('purchase_price', $isEdit ? $product->purchase_price : '') }}" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Tax Type*</label>
                        <select name="tax_inclusive" class="form-control">
                            <option value="1" @if($taxInclusive == '1') selected @endif>Inclusive</option>
                            <option value="0" @if($taxInclusive == '0') selected @endif>Exclusive</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Profit Margin(%)</label>
                        <input type="number" step="0.01" name="profit_margin" id="profit_margin" class="form-control" value="{{ old('profit_margin', $product->profit_margin ?? 0) }}">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Selling Price (pack / purchase unit)*</label>
                        <input type="number" step="0.01" min="0" name="selling_price" id="selling_price" class="form-control" placeholder="e.g. 4500 per Bag" value="{{ old('selling_price', $isEdit ? $product->selling_price : '') }}" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Wholesale Price (pack / purchase unit)*</label>
                        <input type="number" step="0.01" min="0" name="wholesale_price" id="wholesale_price" class="form-control" value="{{ old('wholesale_price', $product->wholesale_price ?? 0) }}">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="sx-req">Promo/Minimum Price*</label>
                        <input type="number" step="0.01" min="0" name="promo_price" class="form-control" value="{{ old('promo_price', $product->promo_price ?? 0) }}">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Sales Commission(%)</label>
                        <input type="number" step="0.01" min="0" name="sales_commission" class="form-control" value="{{ old('sales_commission', $product->sales_commission ?? 0) }}">
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>{{ $isEdit ? 'Opening Stock' : 'New Opening Stock' }}</label>
                        <input type="number" step="0.01" name="opening_stock" id="opening_stock" class="form-control" placeholder="-/+" value="{{ old('opening_stock', $isEdit ? ($openingStock ?? '') : '') }}">
                        <p class="help-block" id="sx-opening-preview" style="margin:4px 0 0;">{{ $isEdit ? 'Current remaining at this branch. Change the qty to adjust stock.' : '' }}</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Select Image</label>
                        <input type="file" name="image" class="form-control-file" accept="image/*">
                        @if($product->image_path)
                            <label class="sx-remove-image"><input type="checkbox" name="remove_image" value="1"> Remove current image</label>
                        @endif
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Description">{{ old('description', $product->description) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="sx-register-bar" style="margin-top:12px;">Unit &amp; Packaging</div>
            <div class="row" style="padding-top:12px;">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Purchase Unit</label>
                        <div class="input-group">
                            <select name="purchase_unit_id" id="purchase_unit_id" class="form-control">
                                <option value="">Same as stock unit</option>
                                @foreach($units as $unit)
                                    <option value="{{ $unit->id }}" @if(old('purchase_unit_id', $product->purchase_unit_id) == $unit->id) selected @endif>{{ $unit->name }} ({{ $unit->short_name }})</option>
                                @endforeach
                            </select>
                            @if(auth()->user()->hasPermission('units.manage'))
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-info sx-plus-btn" data-quick="purchase_unit" title="Add purchase unit"><i class="fa fa-plus"></i></button>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Purchase conversion</label>
                        <div class="input-group">
                            <span class="input-group-addon">1 =</span>
                            <input type="number" step="0.000001" min="0.000001" name="purchase_to_base" id="purchase_to_base" class="form-control" value="{{ old('purchase_to_base', $product->purchase_to_base ?? 1) }}">
                            <span class="input-group-addon" id="sx-base-unit-label">base</span>
                        </div>
                        <p class="help-block" style="margin:4px 0 0;">Example: 1 Bag = 90 KG. POS then sells any KG quantity.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Pricing Mode</label>
                        <select name="unit_pricing_mode" id="unit_pricing_mode" class="form-control">
                            <option value="automatic" @if(old('unit_pricing_mode', $product->unit_pricing_mode ?? 'automatic') === 'automatic') selected @endif>Automatic conversion</option>
                            <option value="manual" @if(old('unit_pricing_mode', $product->unit_pricing_mode ?? 'automatic') === 'manual') selected @endif>Manual extra unit prices</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Opening stock is in</label>
                        <select name="opening_stock_unit" id="opening_stock_unit" class="form-control">
                            <option value="purchase" @if(old('opening_stock_unit', 'purchase') === 'purchase') selected @endif>Purchase unit (Bag, carton…)</option>
                            <option value="base" @if(old('opening_stock_unit', 'purchase') === 'base') selected @endif>Stock / base unit (KG…)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Selling price / <span id="sx-base-price-unit-label">base unit</span></label>
                        <input type="number" step="0.0001" min="0" name="base_selling_price" id="base_selling_price" class="form-control" placeholder="Auto from pack price" value="{{ old('base_selling_price', $product->base_selling_price) }}">
                        <p class="help-block" id="sx-base-price-hint" style="margin:4px 0 0;">Converted from selling price ÷ purchase conversion. You can change the per-unit price.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label>Wholesale price / <span id="sx-base-wholesale-unit-label">base unit</span></label>
                        <input type="number" step="0.0001" min="0" name="base_wholesale_price" id="base_wholesale_price" class="form-control" placeholder="Auto from pack wholesale" value="{{ old('base_wholesale_price', $product->base_wholesale_price) }}">
                        <p class="help-block" id="sx-base-wholesale-hint" style="margin:4px 0 0;">Converted from wholesale price ÷ purchase conversion. You can change the per-unit wholesale.</p>
                    </div>
                </div>
            </div>
            <p class="help-block">POS: pack prices are Selling Price and Wholesale Price. The two fields above are used when selling in the stock unit (e.g. per KG).</p>
            <details @if($product->exists && $product->sellingUnits()->exists()) open @endif>
                <summary style="cursor:pointer;font-weight:600;margin-bottom:8px;">Optional extra selling units / barcodes</summary>
            <div class="table-responsive">
                <table class="table table-bordered table-condensed" id="sx-selling-units">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Equals (base qty)</th>
                            <th>Selling price</th>
                            <th>Barcode</th>
                            <th>Default</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $unitRows = old('selling_units');
                            if (! is_array($unitRows)) {
                                $unitRows = $product->exists
                                    ? $product->sellingUnits()->get()->map(function ($u) {
                                        return [
                                            'id' => $u->id,
                                            'name' => $u->name,
                                            'factor_to_base' => $u->factor_to_base,
                                            'selling_price' => $u->selling_price,
                                            'barcode' => $u->barcode,
                                            'is_default' => $u->is_default ? '1' : '',
                                        ];
                                    })->all()
                                    : [];
                            }
                        @endphp
                        @foreach($unitRows as $i => $row)
                            <tr>
                                <td>
                                    <input type="hidden" name="selling_units[{{ $i }}][id]" value="{{ $row['id'] ?? '' }}">
                                    <input type="text" name="selling_units[{{ $i }}][name]" class="form-control" value="{{ $row['name'] ?? '' }}" placeholder="e.g. ½ KG">
                                </td>
                                <td><input type="number" step="0.000001" min="0.000001" name="selling_units[{{ $i }}][factor_to_base]" class="form-control" value="{{ $row['factor_to_base'] ?? 1 }}"></td>
                                <td><input type="number" step="0.01" min="0" name="selling_units[{{ $i }}][selling_price]" class="form-control sx-unit-price" value="{{ $row['selling_price'] ?? 0 }}"></td>
                                <td><input type="text" name="selling_units[{{ $i }}][barcode]" class="form-control" value="{{ $row['barcode'] ?? '' }}"></td>
                                <td class="text-center"><input type="checkbox" name="selling_units[{{ $i }}][is_default]" value="1" @if(!empty($row['is_default'])) checked @endif></td>
                                <td><button type="button" class="btn btn-danger btn-xs sx-remove-unit">&times;</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button type="button" class="btn btn-info btn-sm" id="sx-add-selling-unit"><i class="fa fa-plus"></i> Add extra unit / barcode</button>
            </div>
            </details>

            <div class="sx-form-actions">
                <button type="submit" class="btn btn-success">Save</button>
                <a href="{{ route('products.index') }}" class="btn btn-warning">Close</a>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="sx-quick-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="sx-quick-title">Add</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" id="sx-quick-name" class="form-control">
                </div>
                <div class="form-group" id="sx-quick-short-wrap" style="display:none;">
                    <label>Short name</label>
                    <input type="text" id="sx-quick-short" class="form-control" maxlength="32">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="sx-quick-save">Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var form = document.querySelector('.sx-item-form');
    var barcodeInput = document.getElementById('sx-item-barcode');
    var scan = { buf: '', last: 0, target: null, start: null };

    function isBarcodeField(el) {
        if (!el) return false;
        return el.id === 'sx-item-barcode' || (el.name && el.name.indexOf('[barcode]') !== -1);
    }
    function markBarcode(el) {
        if (!el) return;
        el.classList.add('sx-barcode-ok');
    }
    if (form && barcodeInput) {
        form.addEventListener('keydown', function (e) {
            if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
                var now = Date.now();
                if (now - scan.last > 70) {
                    scan.buf = '';
                    scan.target = e.target;
                    var tag = ((e.target && e.target.tagName) || '').toLowerCase();
                    scan.start = (!isBarcodeField(e.target) && (tag === 'input' || tag === 'textarea')) ? e.target.value : null;
                }
                scan.buf += e.key;
                scan.last = now;
            }
            if (e.key !== 'Enter') return;
            var burst = scan.buf.length >= 4 && (Date.now() - scan.last) <= 70;
            var code = scan.buf;
            var target = scan.target;
            var start = scan.start;
            scan.buf = '';
            scan.start = null;
            if (burst) {
                e.preventDefault();
                if (isBarcodeField(target)) {
                    markBarcode(target);
                    return;
                }
                if (target && start != null) target.value = start;
                barcodeInput.value = code;
                markBarcode(barcodeInput);
                var name = form.querySelector('input[name="name"]');
                if (name && !name.value.trim()) name.focus();
                return;
            }
            if (isBarcodeField(e.target)) {
                e.preventDefault();
                markBarcode(e.target);
                var nameField = form.querySelector('input[name="name"]');
                if (e.target.id === 'sx-item-barcode' && nameField && !nameField.value.trim()) {
                    nameField.focus();
                }
            }
        });
    }

    var purchase = document.getElementById('purchase_price');
    var selling = document.getElementById('selling_price');
    var margin = document.getElementById('profit_margin');
    var updating = false;

    function toNumber(el) {
        return parseFloat(el && el.value ? el.value : 0) || 0;
    }

    function round2(n) {
        return Math.round(n * 100) / 100;
    }

    function fromPrices() {
        if (updating) return;
        updating = true;
        var cost = toNumber(purchase);
        var price = toNumber(selling);
        margin.value = cost > 0 ? round2(((price - cost) / cost) * 100) : 0;
        updating = false;
    }

    function fromMargin() {
        if (updating) return;
        updating = true;
        var cost = toNumber(purchase);
        selling.value = cost > 0 ? round2(cost * (1 + (toNumber(margin) / 100))) : selling.value;
        updating = false;
    }

    if (purchase && selling && margin) {
        purchase.addEventListener('input', fromPrices);
        selling.addEventListener('input', fromPrices);
        margin.addEventListener('input', fromMargin);
    }

    var endpoints = {
        brand: { url: @json(route('brands.store')), select: 'brand_id', extra: [], title: 'Add Brand' },
        category: { url: @json(route('categories.store')), select: 'category_id', extra: [], title: 'Add Category' },
        unit: { url: @json(route('units.store')), select: 'unit_id', extra: ['purchase_unit_id'], title: 'Add Unit' },
        purchase_unit: { url: @json(route('units.store')), select: 'purchase_unit_id', extra: ['unit_id'], title: 'Add Purchase Unit' }
    };
    var currentType = null;
    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    document.querySelectorAll('[data-quick]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentType = this.getAttribute('data-quick');
            document.getElementById('sx-quick-title').textContent = endpoints[currentType].title;
            document.getElementById('sx-quick-name').value = '';
            document.getElementById('sx-quick-short').value = '';
            document.getElementById('sx-quick-short-wrap').style.display = (currentType === 'unit' || currentType === 'purchase_unit') ? 'block' : 'none';
            $('#sx-quick-modal').modal('show');
            setTimeout(function () { document.getElementById('sx-quick-name').focus(); }, 300);
        });
    });

    document.getElementById('sx-quick-save').addEventListener('click', function () {
        if (!currentType) return;
        var name = document.getElementById('sx-quick-name').value.trim();
        if (!name) return;
        var payload = { name: name };
        if (currentType === 'unit' || currentType === 'purchase_unit') {
            payload.short_name = document.getElementById('sx-quick-short').value.trim() || name.substring(0, 3);
        }
        fetch(endpoints[currentType].url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token
            },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) throw data;
                return data;
            });
        }).then(function (data) {
            function addOption(selectId, selected) {
                var select = document.getElementById(selectId);
                if (!select) return;
                if (select.querySelector('option[value="' + data.id + '"]')) {
                    if (selected) select.value = String(data.id);
                    return;
                }
                var option = document.createElement('option');
                option.value = data.id;
                option.textContent = data.label || data.name;
                option.selected = !!selected;
                select.appendChild(option);
            }
            var cfg = endpoints[currentType];
            addOption(cfg.select, true);
            (cfg.extra || []).forEach(function (id) { addOption(id, false); });
            if (typeof refreshBaseLabel === 'function') refreshBaseLabel();
            if (typeof refreshConvertedPrices === 'function') refreshConvertedPrices();
            $('#sx-quick-modal').modal('hide');
        }).catch(function () {
            alert('Could not save. Check the name and try again.');
        });
    });

    function refreshBaseLabel() {
        var sel = document.getElementById('unit_id');
        var label = document.getElementById('sx-base-unit-label');
        if (!sel || !label) return;
        var opt = sel.options[sel.selectedIndex];
        var text = opt && opt.value ? (opt.text || '').split('(')[0].trim() : '';
        label.textContent = text || 'base unit';
    }
    var unitSelect = document.getElementById('unit_id');
    if (unitSelect) {
        unitSelect.addEventListener('change', refreshBaseLabel);
        refreshBaseLabel();
    }
    var unitIndex = document.querySelectorAll('#sx-selling-units tbody tr').length;
    var addBtn = document.getElementById('sx-add-selling-unit');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var i = unitIndex++;
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="hidden" name="selling_units[' + i + '][id]" value="">' +
                '<input type="text" name="selling_units[' + i + '][name]" class="form-control" placeholder="e.g. 5 KG"></td>' +
                '<td><input type="number" step="0.000001" min="0.000001" name="selling_units[' + i + '][factor_to_base]" class="form-control" value="1"></td>' +
                '<td><input type="number" step="0.01" min="0" name="selling_units[' + i + '][selling_price]" class="form-control sx-unit-price" value="0"></td>' +
                '<td><input type="text" name="selling_units[' + i + '][barcode]" class="form-control"></td>' +
                '<td class="text-center"><input type="checkbox" name="selling_units[' + i + '][is_default]" value="1"></td>' +
                '<td><button type="button" class="btn btn-danger btn-xs sx-remove-unit">&times;</button></td>';
            document.querySelector('#sx-selling-units tbody').appendChild(tr);
        });
    }
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('sx-remove-unit')) {
            e.target.closest('tr').remove();
        }
    });
    var basePriceDirty = false;
    var wholesaleDirty = false;
    var basePriceEl = document.getElementById('base_selling_price');
    var wholesaleEl = document.getElementById('base_wholesale_price');
    function conversionFactor() {
        var factor = parseFloat(document.getElementById('purchase_to_base') && document.getElementById('purchase_to_base').value) || 1;
        return factor > 0 ? factor : 1;
    }
    function baseUnitName() {
        var label = (document.getElementById('sx-base-unit-label') || {}).textContent || '';
        label = String(label).replace(/^-Select-$/i, '').trim();
        return label || 'base unit';
    }
    function autoFromPack(packId) {
        var pack = parseFloat(document.getElementById(packId) && document.getElementById(packId).value) || 0;
        return Math.round((pack / conversionFactor()) * 10000) / 10000;
    }
    function bindConvertedPrice(el, dirtyFlagName, packId, hintId, titleId) {
        function refresh() {
            var unitLabel = baseUnitName();
            var title = document.getElementById(titleId);
            if (title) title.textContent = unitLabel;
            var auto = autoFromPack(packId);
            var dirty = dirtyFlagName === 'sell' ? basePriceDirty : wholesaleDirty;
            if (el && !dirty && auto > 0) {
                el.value = auto;
            }
            var hint = document.getElementById(hintId);
            if (hint) {
                var packVal = parseFloat(document.getElementById(packId) && document.getElementById(packId).value) || 0;
                if (!(auto > 0) || packVal <= 0) {
                    hint.textContent = 'Enter pack price and conversion (e.g. 1 Bag = 90 KG), then edit this per-' + unitLabel + ' price if needed.';
                } else {
                    hint.textContent = '1 pack ÷ ' + conversionFactor() + ' = ' + auto + ' per ' + unitLabel + '. Edit to set a different price.';
                }
            }
        }
        if (el) {
            var saved = parseFloat(el.value);
            var auto = autoFromPack(packId);
            if (saved > 0 && auto > 0 && Math.abs(saved - auto) > 0.00005) {
                if (dirtyFlagName === 'sell') basePriceDirty = true;
                else wholesaleDirty = true;
            }
            el.addEventListener('input', function () {
                if (dirtyFlagName === 'sell') basePriceDirty = true;
                else wholesaleDirty = true;
            });
        }
        return refresh;
    }
    var refreshBasePrice = bindConvertedPrice(basePriceEl, 'sell', 'selling_price', 'sx-base-price-hint', 'sx-base-price-unit-label');
    var refreshWholesalePrice = bindConvertedPrice(wholesaleEl, 'wholesale', 'wholesale_price', 'sx-base-wholesale-hint', 'sx-base-wholesale-unit-label');
    function refreshConvertedPrices() {
        refreshBasePrice();
        refreshWholesalePrice();
    }
    ['selling_price', 'wholesale_price', 'purchase_to_base'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', refreshConvertedPrices);
    });
    var unitSelPrice = document.getElementById('unit_id');
    if (unitSelPrice) unitSelPrice.addEventListener('change', refreshConvertedPrices);
    function refreshOpeningPreview() {
        var out = document.getElementById('sx-opening-preview');
        var qtyEl = document.getElementById('opening_stock');
        var modeEl = document.getElementById('opening_stock_unit');
        var factorEl = document.getElementById('purchase_to_base');
        var purchaseEl = document.getElementById('purchase_unit_id');
        if (!out || !qtyEl) return;
        var qty = parseFloat(qtyEl.value);
        var factor = parseFloat(factorEl && factorEl.value) || 1;
        var packaged = purchaseEl && purchaseEl.value && document.getElementById('unit_id')
            && purchaseEl.value !== document.getElementById('unit_id').value;
        if (!packaged && factor == 1) {
            out.textContent = '';
            return;
        }
        if (!qty) {
            out.textContent = '';
            return;
        }
        var mode = modeEl ? modeEl.value : 'purchase';
        var purchaseLabel = 'purchase unit';
        if (purchaseEl && purchaseEl.selectedIndex > 0) {
            purchaseLabel = (purchaseEl.options[purchaseEl.selectedIndex].text || 'purchase unit').split('(')[0].trim();
        }
        var baseLabel = (document.getElementById('sx-base-unit-label') || {}).textContent || 'base';
        if (mode === 'base') {
            out.textContent = 'Saves as ' + qty + ' ' + baseLabel + ' (no conversion).';
            return;
        }
        var baseQty = Math.round(qty * factor * 1000000) / 1000000;
        out.textContent = qty + ' ' + purchaseLabel + ' × ' + factor + ' = ' + baseQty + ' ' + baseLabel;
    }
    function preferPurchaseOpeningUnit() {
        var modeEl = document.getElementById('opening_stock_unit');
        var purchaseEl = document.getElementById('purchase_unit_id');
        var unitEl = document.getElementById('unit_id');
        var factorEl = document.getElementById('purchase_to_base');
        if (!modeEl) return;
        var factor = parseFloat(factorEl && factorEl.value) || 1;
        var packaged = purchaseEl && purchaseEl.value && unitEl && purchaseEl.value !== unitEl.value;
        if (packaged || factor !== 1) {
            modeEl.value = 'purchase';
        }
        refreshOpeningPreview();
    }
    ['opening_stock', 'opening_stock_unit', 'purchase_to_base', 'purchase_unit_id', 'unit_id'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', refreshOpeningPreview);
        el.addEventListener('change', refreshOpeningPreview);
    });
    var purchaseSel = document.getElementById('purchase_unit_id');
    if (purchaseSel) purchaseSel.addEventListener('change', preferPurchaseOpeningUnit);
    var factorEl = document.getElementById('purchase_to_base');
    if (factorEl) factorEl.addEventListener('input', preferPurchaseOpeningUnit);
    refreshOpeningPreview();
    refreshConvertedPrices();
})();
</script>
@endpush
