@php
    $oldItems = old('items');
    if (! is_array($oldItems) || count($oldItems) === 0) {
        $oldItems = [[
            'product_id' => '',
            'quantity' => 1,
            'unit_price' => '',
            'tax_rate' => '',
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]];
    }
@endphp

<div class="sx-purchase-meta">
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label class="sx-req">Customer *</label>
                <select name="customer_id" class="form-control" required>
                    <option value="">-Select-</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @if((string) old('customer_id', $sale->customer_id) === (string) $customer->id) selected @endif>
                            {{ $customer->name }}{{ $customer->is_walk_in ? ' (Walk-in)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label>Due Date</label>
                <input type="date" name="due_date" class="form-control" value="{{ old('due_date', optional($sale->due_date)->format('Y-m-d')) }}">
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label class="sx-req">Invoice Date *</label>
                <input type="date" name="sale_date" class="form-control" value="{{ old('sale_date', optional($sale->sale_date)->format('Y-m-d') ?: now()->toDateString()) }}" required>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3">{{ old('notes', $sale->notes) }}</textarea>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-bordered sx-gold-table sx-po-table" id="sx-qt-table">
        <thead>
            <tr>
                <th style="min-width:220px;">Product</th>
                <th style="width:100px;">Qty</th>
                <th style="width:120px;">Unit Price</th>
                <th style="width:90px;">Tax %</th>
                <th style="width:150px;">Discount</th>
                <th style="width:120px;">Line Total</th>
                <th style="width:60px;">Action</th>
            </tr>
        </thead>
        <tbody id="sx-qt-rows">
            @foreach($oldItems as $index => $item)
                <tr class="sx-qt-row">
                    <td>
                        <select name="items[{{ $index }}][product_id]" class="form-control sx-qt-product" required>
                            <option value="">-Select-</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}"
                                    data-price="{{ (float) $product->selling_price }}"
                                    data-tax="{{ (float) (optional($product->tax)->rate ?? 0) }}"
                                    @if((string) ($item['product_id'] ?? '') === (string) $product->id) selected @endif>
                                    {{ $product->name }}@if($product->sku) ({{ $product->sku }})@endif
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <input type="number" step="0.0001" min="0.0001" name="items[{{ $index }}][quantity]" class="form-control sx-qt-qty" value="{{ $item['quantity'] ?? 1 }}" required>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" name="items[{{ $index }}][unit_price]" class="form-control sx-qt-price" value="{{ $item['unit_price'] ?? '' }}" required>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" name="items[{{ $index }}][tax_rate]" class="form-control sx-qt-tax" value="{{ $item['tax_rate'] ?? '' }}">
                    </td>
                    <td>
                        <div class="sx-discount-pair">
                            <select name="items[{{ $index }}][discount_type]" class="form-control sx-qt-discount-type">
                                <option value="fixed" @if(($item['discount_type'] ?? 'fixed') === 'fixed') selected @endif>Ksh</option>
                                <option value="percent" @if(($item['discount_type'] ?? '') === 'percent') selected @endif>%</option>
                            </select>
                            <input type="number" step="0.01" min="0" name="items[{{ $index }}][discount_value]" class="form-control sx-qt-discount" value="{{ $item['discount_value'] ?? $item['discount_amount'] ?? 0 }}">
                        </div>
                    </td>
                    <td class="sx-qt-line-total text-right">0.00</td>
                    <td>
                        <button type="button" class="btn btn-danger btn-xs sx-qt-remove" title="Remove"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<button type="button" class="btn sx-link-add" id="sx-qt-add-row"><i class="fa fa-plus"></i> Add Line</button>

<div class="sx-po-totals" style="margin-top:16px;">
    <div class="sx-po-totals-left"></div>
    <div class="sx-po-totals-right">
        <div class="sx-po-total-row"><span>Subtotal</span><strong>Ksh <span id="sx-qt-subtotal">0.00</span></strong></div>
        <div class="sx-po-total-row">
            <span>Discount</span>
            <div class="sx-discount-pair">
                <select name="discount_type" id="sx-qt-discount-type" class="form-control input-sm">
                    <option value="fixed" @if(old('discount_type', 'fixed') === 'fixed') selected @endif>Ksh</option>
                    <option value="percent" @if(old('discount_type') === 'percent') selected @endif>%</option>
                </select>
                <input type="number" step="0.01" min="0" name="discount_value" id="sx-qt-header-discount" class="form-control input-sm" value="{{ old('discount_value', old('discount_amount', $sale->discount_amount ?? 0)) }}">
            </div>
        </div>
        <div class="sx-po-total-row"><span>Tax</span><strong>Ksh <span id="sx-qt-tax">0.00</span></strong></div>
        <div class="sx-po-total-row"><span>Grand Total</span><strong>Ksh <span id="sx-qt-grand">0.00</span></strong></div>
    </div>
</div>

<div class="sx-label-actions">
    <button type="submit" class="btn sx-btn-preview">Save</button>
    <a href="{{ route('invoices.index') }}" class="btn sx-btn-label-close">Close</a>
</div>

<template id="sx-qt-row-template">
    <tr class="sx-qt-row">
        <td>
            <select name="items[__INDEX__][product_id]" class="form-control sx-qt-product" required>
                <option value="">-Select-</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}"
                        data-price="{{ (float) $product->selling_price }}"
                        data-tax="{{ (float) (optional($product->tax)->rate ?? 0) }}">
                        {{ $product->name }}@if($product->sku) ({{ $product->sku }})@endif
                    </option>
                @endforeach
            </select>
        </td>
        <td>
            <input type="number" step="0.0001" min="0.0001" name="items[__INDEX__][quantity]" class="form-control sx-qt-qty" value="1" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="items[__INDEX__][unit_price]" class="form-control sx-qt-price" value="" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="items[__INDEX__][tax_rate]" class="form-control sx-qt-tax" value="">
        </td>
        <td>
            <div class="sx-discount-pair">
                <select name="items[__INDEX__][discount_type]" class="form-control sx-qt-discount-type">
                    <option value="fixed" selected>Ksh</option>
                    <option value="percent">%</option>
                </select>
                <input type="number" step="0.01" min="0" name="items[__INDEX__][discount_value]" class="form-control sx-qt-discount" value="0">
            </div>
        </td>
        <td class="sx-qt-line-total text-right">0.00</td>
        <td>
            <button type="button" class="btn btn-danger btn-xs sx-qt-remove" title="Remove"><i class="fa fa-trash"></i></button>
        </td>
    </tr>
</template>
