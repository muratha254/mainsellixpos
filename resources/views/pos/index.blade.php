@extends('layouts.pos')

@section('content')
<div class="pos-wrap">
    <div class="pos-left">
        <div class="pos-filters">
            @if(auth()->user()->canSwitchBranches())
                <form action="{{ route('branch.switch') }}" method="post" class="pos-branch-form">
                    @csrf
                    <select name="branch_id" class="form-control" onchange="this.form.submit()">
                        @foreach($branches as $option)
                            <option value="{{ $option->id }}" @if(!empty($branch) && (int) $branch->id === (int) $option->id) selected @endif>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </form>
            @else
                <span class="form-control pos-branch-form">{{ optional($branch)->name ?? 'Branch' }}</span>
            @endif
            <div class="pos-customer-wrap">
                <select id="sx-pos-customer" class="form-control">
                    @foreach($customers as $customer)
                        @php
                            $pointsLabel = rtrim(rtrim(number_format((float) $customer->loyalty_points, 2, '.', ''), '0'), '.');
                            $customerLabel = $customer->is_walk_in
                                ? 'WALK-IN'
                                : implode(' - ', array_filter([
                                    trim($customer->name),
                                    $customer->phone,
                                    $pointsLabel.' pts',
                                ]));
                        @endphp
                        <option
                            value="{{ $customer->id }}"
                            data-walk-in="{{ $customer->is_walk_in ? '1' : '0' }}"
                            data-credit-limit="{{ (float) $customer->credit_limit }}"
                            data-credit-used="{{ $customer->creditAmount() }}"
                            data-points="{{ number_format((float) $customer->loyalty_points, 2, '.', '') }}"
                            @if((int) $customer->id === (int) $walkInId) selected @endif
                        >{{ $customerLabel }}</option>
                    @endforeach
                </select>
                @if($canCreateCustomer)
                    <button type="button" class="pos-cust-btn" id="sx-pos-add-cust" title="Add customer"><i class="fa fa-user-plus"></i></button>
                @endif
            </div>
        </div>
        <div class="pos-filters">
            <div class="pos-date-wrap">
                <span class="pos-search-icon"><i class="fa fa-calendar"></i></span>
                <input type="date" id="sx-pos-date" class="form-control" value="{{ now()->toDateString() }}">
            </div>
            <div class="pos-search-wrap">
                <span class="pos-search-icon"><i class="fa fa-th"></i></span>
                <input type="text" id="sx-pos-search" class="form-control" placeholder="Scan barcode or type item name (F10)" autocomplete="off">
                <div id="sx-pos-results" class="pos-search-results" hidden></div>
            </div>
        </div>

        <div class="pos-cart-wrap">
            <table class="table pos-cart">
                <thead>
                    <tr>
                        <th class="pos-col-name">ITEM NAME</th>
                        <th class="pos-col-qty">ITEM QTY</th>
                        <th class="pos-col-price">PRICE<br>INC. TAX</th>
                        <th class="pos-col-type">TYPE</th>
                        <th class="pos-col-sub">SUBTOTAL</th>
                        <th class="pos-col-tax">TAX</th>
                        <th class="pos-x"><span class="pos-x-icon"><i class="fa fa-times"></i></span></th>
                    </tr>
                </thead>
                <tbody id="sx-pos-rows"></tbody>
            </table>
        </div>

        <div class="pos-footer">
            <div class="pos-totals">
                <div>
                    <span>Total Item Qty:</span>
                    <strong id="sx-pos-qty">0</strong>
                </div>
                <div>
                    <span>Total Amount:</span>
                    <strong>Ksh <span id="sx-pos-amount">0.00</span></strong>
                </div>
                <div>
                    <span>Total Discount: <a href="#" id="sx-pos-disc-btn" title="Discount">Disc. <i class="fa fa-edit"></i></a></span>
                    <strong>Ksh <span id="sx-pos-discount">0.00</span></strong>
                </div>
                <div>
                    <span>Grand Total:</span>
                    <strong>Ksh <span id="sx-pos-grand">0.00</span></strong>
                </div>
            </div>

            <div class="pos-meta">
                <div class="pos-meta-field">
                    <label for="sx-pos-due">Due Date</label>
                    <input type="date" id="sx-pos-due" class="form-control" value="{{ now()->toDateString() }}">
                </div>
                <div class="pos-meta-field">
                    <label for="sx-pos-doctype">Inv No</label>
                    <select id="sx-pos-doctype" class="form-control">
                        <option value="pos" selected>Receipt</option>
                        <option value="invoice">Invoice</option>
                    </select>
                </div>
            </div>

            <div class="pos-actions">
                <button type="button" class="btn pos-btn-hold" id="sx-pos-hold"><i class="fa fa-hand-stop-o"></i> HOLD (F4)</button>
                <button type="button" class="btn pos-btn-cust" id="sx-pos-cust"><i class="fa fa-user"></i> CUST (F7)</button>
            </div>
            <div class="pos-links">
                <a href="{{ route('stock.conversion') }}"><i class="fa fa-barcode"></i> Stock Conversion</a>
                <a href="{{ route('stock.manager') }}"><i class="fa fa-balance-scale"></i> +/- ADJUST</a>
                <a href="#" id="sx-pos-holds-link"><i class="fa fa-list"></i> HOLD LIST</a>
            </div>
        </div>
    </div>

    <div class="pos-right">
        <form id="sx-pos-pay-form" class="pos-pay-panel">
            <div class="pos-pay-head">
                <i class="fa fa-money"></i> RECEIVE PAYMENT
            </div>
            <div class="pos-pay-sheet">
                <div class="pos-pay-label">Payment method</div>
                <div class="pos-pay-methods" role="group" aria-label="Payment method">
                    <button type="button" class="pos-pay-method is-active" data-method="cash">
                        <i class="fa fa-money"></i>
                        <span>Cash</span>
                    </button>
                    <button type="button" class="pos-pay-method" data-method="mpesa">
                        <i class="fa fa-mobile"></i>
                        <span>M-Pesa</span>
                    </button>
                    <button type="button" class="pos-pay-method" data-method="card">
                        <i class="fa fa-credit-card"></i>
                        <span>Card</span>
                    </button>
                </div>
                <input type="hidden" id="sx-pay-method" value="cash">

                <label class="pos-pay-label" id="sx-pay-received-label" for="sx-pay-received-input">Cash received</label>
                <div class="pos-pay-amount-box">
                    <span>KSh</span>
                    <input type="number" step="0.01" min="0" id="sx-pay-received-input" value="0.00" inputmode="decimal">
                </div>

                <div class="pos-pay-line">
                    <span>Change to return</span>
                    <strong class="is-green" id="sx-pay-change">KSh 0.00</strong>
                </div>

                <button type="button" id="sx-pay-with-points" class="pos-pay-points">
                    <i class="fa fa-star"></i>
                    <span>
                        <strong>Pay with points</strong>
                        <small id="sx-loyalty-caption">Select a customer account</small>
                    </span>
                </button>
                <input type="hidden" id="sx-loyalty-points" value="0">

                <label class="pos-pay-label" for="sx-pay-note">Note (optional)</label>
                <textarea id="sx-pay-note" rows="2" placeholder="What's this payment for?"></textarea>

                <div class="pos-pay-line" id="sx-pay-sale-line" hidden>
                    <span>Sale total</span>
                    <strong id="sx-pay-sale">KSh 0.00</strong>
                </div>
                <div class="pos-pay-line" id="sx-loyalty-deducted" hidden>
                    <span>Points deduction</span>
                    <strong class="is-green" id="sx-loyalty-discount">KSh 0.00</strong>
                </div>
                <div class="pos-pay-line">
                    <span>Payment amount</span>
                    <strong id="sx-pay-amount">KSh 0.00</strong>
                </div>
                <div class="pos-pay-line">
                    <span>Transaction fee</span>
                    <strong id="sx-pay-fee">KSh 0.00</strong>
                </div>
                <div class="pos-pay-line pos-pay-total">
                    <span>Total to pay</span>
                    <strong id="sx-pay-total">KSh 0.00</strong>
                </div>
                <div class="pos-pay-credit-note" id="sx-pay-credit-note" hidden>
                    Credit sale allowed for this customer. Leave unpaid amount as account balance.
                </div>
            </div>
            <div class="pos-pay-actions">
                <button type="submit" class="pos-pay-complete">
                    <i class="fa fa-check"></i>
                    <span>Complete transaction</span>
                    <i class="fa fa-arrow-right"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="sx-pos-discount-modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content sx-conversion-modal">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Discount</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Type</label>
                    <select id="sx-pos-disc-type" class="form-control">
                        <option value="fixed">Fixed amount (Ksh)</option>
                        <option value="percent">Percentage (%)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value</label>
                    <input type="number" step="0.01" min="0" id="sx-pos-disc-value" class="form-control" value="0">
                </div>
                <div class="sx-form-actions">
                    <button type="button" class="btn btn-success" id="sx-pos-disc-apply">Apply</button>
                    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="sx-pos-customer-modal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content sx-conversion-modal">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Add Customer</h4>
            </div>
            <div class="modal-body">
                <form id="sx-pos-customer-form">
                    <div class="form-group">
                        <label class="sx-req">Name*</label>
                        <input type="text" name="name" class="form-control" required placeholder="Customer name">
                    </div>
                    <div class="form-group">
                        <label>Mobile</label>
                        <input type="text" name="phone" class="form-control" placeholder="Mobile Number">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" placeholder="Email Address">
                    </div>
                    <div class="sx-form-actions">
                        <button type="submit" class="btn btn-success">Save</button>
                        <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="sx-pos-sell-modal" tabindex="-1">
    <div class="modal-dialog" style="max-width:380px;">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="sx-sell-name">Sell</h4>
            </div>
            <div class="modal-body">
                <p class="help-block" id="sx-sell-stock" style="margin-top:0;"></p>
                <div class="form-group">
                    <label>Sell As</label>
                    <select id="sx-sell-as" class="form-control"></select>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" id="sx-sell-qty" class="form-control" min="0.0001" step="0.0001" value="1">
                </div>
                <div class="form-group">
                    <label>Price</label>
                    <input type="text" id="sx-sell-price" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Total</label>
                    <input type="text" id="sx-sell-total" class="form-control" readonly>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="sx-sell-add">Add to Cart</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="sx-pos-holds-modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content sx-conversion-modal">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Hold List</h4>
            </div>
            <div class="modal-body">
                <table class="table table-bordered sx-gold-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Number</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Held at</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="sx-pos-holds-rows">
                        <tr><td colspan="6">No held sales.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function ($) {
    var catalogUrl = @json(route('pos.catalog'));
    var searchUrl = @json(route('pos.search'));
    var customerUrl = @json(route('pos.customers.store'));
    var holdUrl = @json(route('pos.hold'));
    var orderUrl = @json(route('pos.order'));
    var holdsUrl = @json(route('pos.holds'));
    var token = $('meta[name="csrf-token"]').attr('content');
    function customerMenuLabel(name, phone, points) {
        var pts = parseFloat(points);
        if (isNaN(pts)) pts = 0;
        var ptsText = pts.toFixed(2).replace(/\.?0+$/, '');
        var parts = [$.trim(name)];
        if ($.trim(phone)) parts.push($.trim(phone));
        parts.push(ptsText + ' pts');
        return parts.join(' - ');
    }
    $('#sx-pos-customer').select2({
        width: '100%',
        minimumResultsForSearch: 0,
        dropdownCssClass: 'pos-customer-dropdown'
    });
    var cart = [];
    var discountType = 'fixed';
    var discountValue = 0;
    var holdId = null;
    var payAmountEdited = false;
    var fillingReceived = false;
    var categoryId = null;
    var page = 1;
    var pages = 1;
    var searchTimer = null;

    function money(n) {
        return (Math.round((n || 0) * 100) / 100).toFixed(2);
    }
    function escapeHtml(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    function amount(value) {
        var n = parseFloat(value);
        return isNaN(n) ? 0 : n;
    }
    function linePrice(row) {
        var inc = amount(row.price);
        var rate = amount(row.tax_rate);
        if (row.type === 'W' && amount(row.wholesale_price) > 0) {
            var wholesale = amount(row.wholesale_price);
            inc = row.tax_inclusive ? wholesale : wholesale * (1 + rate / 100);
        } else if (row.type === 'P' && amount(row.promo_price) > 0) {
            var promo = amount(row.promo_price);
            inc = row.tax_inclusive ? promo : promo * (1 + rate / 100);
        }
        return inc;
    }
    function lineTax(row) {
        return row.apply_tax ? (row.tax_amount || 0) : 0;
    }

    function totals() {
        var qty = 0, amount = 0, tax = 0;
        cart.forEach(function (row) {
            qty += row.quantity;
            amount += linePrice(row) * row.quantity;
            tax += lineTax(row) * row.quantity;
        });
        var disc = discountType === 'percent'
            ? Math.min(amount, amount * (Math.min(100, Math.max(0, discountValue)) / 100))
            : Math.min(amount, Math.max(0, discountValue));
        return {
            qty: qty,
            amount: amount,
            exclusive: Math.max(0, amount - tax),
            tax: tax,
            discount: disc,
            grand: Math.max(0, amount - disc)
        };
    }

    function tracksStock(item) {
        return !!(item && item.manage_stock && !item.allow_negative_stock);
    }

    function unitFactorOf(unit) {
        var factor = parseFloat(unit && (unit.factor || unit.unit_factor));
        return factor > 0 ? factor : 1;
    }

    function stockBaseOf(item) {
        var stock = parseFloat(item && item.qty);
        if (isNaN(stock)) stock = parseFloat(item && item.stock_base);
        return isNaN(stock) ? 0 : stock;
    }

    function formatStock(n) {
        var rounded = Math.round((parseFloat(n) || 0) * 10000) / 10000;
        return String(rounded);
    }

    function maxSellQty(stock, factor, usedBase) {
        var left = (parseFloat(stock) || 0) - (parseFloat(usedBase) || 0);
        if (left <= 0.0000005) return 0;
        var max = left / (factor > 0 ? factor : 1);
        return Math.floor((max + 0.000000001) * 10000) / 10000;
    }

    function usedBaseExcept(productId, skipIndex) {
        var used = 0;
        cart.forEach(function (row, i) {
            if (i === skipIndex || !row.track_stock || String(row.id) !== String(productId)) return;
            used += (parseFloat(row.quantity) || 0) * (parseFloat(row.unit_factor) || 1);
        });
        return used;
    }

    function clampLineQty(productId, factor, stock, track, desired, skipIndex) {
        desired = parseFloat(desired) || 0;
        if (!track) return { qty: desired, limited: false, max: null };
        var max = maxSellQty(stock, factor, usedBaseExcept(productId, skipIndex));
        if (desired - max > 0.0000005) {
            return { qty: max, limited: true, max: max };
        }
        return { qty: desired, limited: false, max: max };
    }

    var stockNoticeAt = 0;
    function notifyStock(name, maxQty, unitName) {
        var now = Date.now();
        if (now - stockNoticeAt < 1200) return;
        stockNoticeAt = now;
        var text = maxQty > 0
            ? 'Only ' + formatStock(maxQty) + (unitName ? ' ' + unitName : '') + ' of ' + name + ' is in this store.'
            : name + ' is out of stock in this store.';
        Swal.fire({ icon: 'warning', title: 'Not enough stock', text: text, timer: 1800, showConfirmButton: false });
    }

    function renderCart() {
        var html = '';
        cart.forEach(function (row, i) {
            var price = linePrice(row);
            var lineMax = row.track_stock
                ? maxSellQty(row.stock_base, row.unit_factor || 1, usedBaseExcept(row.id, i))
                : null;
            var atMax = lineMax !== null && row.quantity + 0.0000005 >= lineMax;
            html += '<tr class="pos-line-row">';
            html += '<td class="pos-line-name">' + escapeHtml(row.name) + (row.unit_name ? ' <small class="pos-unit-tag">' + escapeHtml(row.unit_name) + '</small>' : '') + '</td>';
            html += '<td class="pos-line-qty">';
            html += '<div class="pos-stepper">';
            html += '<button type="button" class="pos-step" data-i="' + i + '" data-d="-1">&minus;</button>';
            html += '<input type="number" min="0.0001" step="0.0001" class="pos-qty" data-i="' + i + '" value="' + row.quantity + '"' + (lineMax !== null ? ' max="' + lineMax + '"' : '') + '>';
            html += '<button type="button" class="pos-step" data-i="' + i + '" data-d="1"' + (atMax ? ' disabled' : '') + '>+</button>';
            html += '</div>';
            html += '<input type="text" class="pos-serial" data-i="' + i + '" placeholder="Enter Serial/Key/Unit" value="' + escapeHtml(row.serial || '') + '">';
            html += '</td>';
            html += '<td>' + money(price) + '</td>';
            html += '<td><select class="pos-type" data-i="' + i + '" title="R retail, W wholesale, P promo">';
            html += '<option value="R"' + (row.type === 'R' ? ' selected' : '') + '>R</option>';
            html += '<option value="W"' + (row.type === 'W' ? ' selected' : '') + '>W</option>';
            html += '<option value="P"' + (row.type === 'P' ? ' selected' : '') + '>P</option>';
            html += '</select></td>';
            html += '<td>' + money(price * row.quantity) + '</td>';
            html += '<td class="pos-tax-cell"><input type="checkbox" class="pos-tax" data-i="' + i + '"' + (row.apply_tax ? ' checked' : '') + '></td>';
            html += '<td><button type="button" class="pos-remove" data-i="' + i + '" title="Remove"><i class="fa fa-trash-o"></i></button></td>';
            html += '</tr>';
        });
        $('#sx-pos-rows').html(html);
        var t = totals();
        $('#sx-pos-qty').text(t.qty);
        $('#sx-pos-amount').text(money(t.amount));
        $('#sx-pos-discount').text(money(t.discount));
        $('#sx-pos-grand').text(money(t.grand));
        refreshPayPanel();
    }

    function addProduct(item, qty, unitKey) {
        qty = parseFloat(qty) || 1;
        var units = item.selling_units || [];
        var unit = null;
        if (unitKey) {
            unit = units.filter(function (u) {
                return String(u.key) === String(unitKey) || String(u.id) === String(unitKey);
            })[0];
        }
        if (!unit && item.scan_sell_as) {
            unit = units.filter(function (u) { return String(u.key) === String(item.scan_sell_as); })[0];
        }
        if (!unit && item.scan_unit_id) {
            unit = units.filter(function (u) { return String(u.id) === String(item.scan_unit_id); })[0];
        }
        if (!unit) {
            unit = units.filter(function (u) { return u.is_default; })[0] || units[0] || {
                id: item.unit_id || null,
                key: item.sell_as || 'base',
                name: item.unit_name || '',
                selling_price: item.selling_price,
                factor: item.unit_factor || 1
            };
        }
        var rate = item.tax_rate || 0;
        var selling = Number(unit.selling_price || item.selling_price || 0);
        var inclusive = !!item.tax_inclusive;
        var price = inclusive ? selling : +(selling * (1 + rate / 100)).toFixed(2);
        var taxAmount = rate > 0
            ? (inclusive ? +(selling * rate / (100 + rate)).toFixed(2) : +(selling * rate / 100).toFixed(2))
            : 0;
        var key = String(item.id) + ':' + String(unit.key || unit.id || 0);
        var factor = unitFactorOf(unit);
        var track = tracksStock(item);
        var stock = stockBaseOf(item);
        var existingIndex = -1;
        cart.forEach(function (row, i) {
            if (row.line_key === key) existingIndex = i;
        });
        var desired = existingIndex >= 0 ? +(cart[existingIndex].quantity + qty).toFixed(4) : qty;
        var clamped = clampLineQty(item.id, factor, stock, track, desired, existingIndex);
        if (clamped.qty <= 0) {
            notifyStock(item.name, 0, unit.name || '');
            return;
        }
        if (clamped.limited) {
            notifyStock(item.name, clamped.qty, unit.name || '');
        }
        if (existingIndex >= 0) {
            cart[existingIndex].quantity = clamped.qty;
            cart[existingIndex].track_stock = track;
            cart[existingIndex].stock_base = stock;
            cart[existingIndex].unit_factor = factor;
        } else {
            cart.push({
                id: item.id,
                line_key: key,
                product_unit_id: unit.id || null,
                sell_as: unit.key || 'base',
                unit_name: unit.name || '',
                unit_factor: factor,
                track_stock: track,
                stock_base: stock,
                name: item.name,
                price: price,
                wholesale_price: amount(unit.wholesale_price) || amount(item.wholesale_price),
                promo_price: amount(unit.promo_price) || amount(item.promo_price),
                tax_inclusive: inclusive,
                tax_rate: rate,
                tax_amount: taxAmount,
                apply_tax: false,
                type: 'R',
                serial: '',
                quantity: clamped.qty
            });
        }
        renderCart();
    }

    var sellItem = null;
    function openSellModal(item, presetKey) {
        sellItem = item;
        $('#sx-sell-name').text(item.name);
        $('#sx-sell-stock').text('Available stock: ' + (item.qty_display || item.qty || 0));
        var units = item.selling_units || [];
        var html = '';
        units.forEach(function (u) {
            html += '<option value="' + escapeHtml(u.key || '') + '">' + escapeHtml(u.name) + '</option>';
        });
        $('#sx-sell-as').html(html);
        if (presetKey) $('#sx-sell-as').val(presetKey);
        else if (item.scan_sell_as) $('#sx-sell-as').val(item.scan_sell_as);
        var startQty = 1;
        if (tracksStock(item)) {
            var sellUnit = currentSellUnit();
            var sellMax = maxSellQty(stockBaseOf(item), unitFactorOf(sellUnit), usedBaseExcept(item.id, -1));
            $('#sx-sell-qty').attr('max', sellMax);
            startQty = sellMax >= 1 ? 1 : sellMax;
            if (sellMax <= 0) notifyStock(item.name, 0, sellUnit ? sellUnit.name : '');
        } else {
            $('#sx-sell-qty').removeAttr('max');
        }
        $('#sx-sell-qty').val(startQty);
        refreshSellPreview();
        $('#sx-pos-sell-modal').modal('show');
        setTimeout(function () { $('#sx-sell-qty').focus().select(); }, 300);
    }
    function currentSellUnit() {
        if (!sellItem) return null;
        var key = $('#sx-sell-as').val();
        return (sellItem.selling_units || []).filter(function (u) { return String(u.key) === String(key); })[0];
    }
    function refreshSellPreview() {
        var unit = currentSellUnit();
        var qty = parseFloat($('#sx-sell-qty').val()) || 0;
        if (!unit) return;
        $('#sx-sell-price').val(money(unit.selling_price) + ' / ' + unit.name);
        $('#sx-sell-total').val(money(unit.selling_price * qty));
    }
    $('#sx-sell-as, #sx-sell-qty').on('input change', refreshSellPreview);
    $('#sx-sell-qty').on('input', function () {
        if (!sellItem || !tracksStock(sellItem)) return;
        var qty = parseFloat($(this).val());
        if (!(qty > 0)) return;
        var unit = currentSellUnit();
        var clamped = clampLineQty(sellItem.id, unitFactorOf(unit), stockBaseOf(sellItem), true, qty, -1);
        if (!clamped.limited) return;
        $(this).val(clamped.qty > 0 ? clamped.qty : 0);
        notifyStock(sellItem.name, clamped.max, unit ? unit.name : '');
        refreshSellPreview();
    });
    $('#sx-sell-add').on('click', function () {
        var qty = parseFloat($('#sx-sell-qty').val());
        if (!(qty > 0) || !sellItem) return;
        addProduct(sellItem, qty, $('#sx-sell-as').val());
        $('#sx-pos-sell-modal').modal('hide');
    });
    $('#sx-sell-qty').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#sx-sell-add').click();
        }
    });

    function cartPayload() {
        return {
            hold_id: holdId,
            customer_id: $('#sx-pos-customer').val(),
            sale_date: $('#sx-pos-date').val(),
            due_date: $('#sx-pos-due').val(),
            document_type: $('#sx-pos-doctype').val(),
            discount_type: discountType,
            discount_value: discountValue,
            discount_amount: totals().discount,
            items: cart.map(function (row) {
                return {
                    product_id: row.id,
                    product_unit_id: row.product_unit_id || null,
                    sell_as: row.sell_as || null,
                    quantity: row.quantity,
                    apply_tax: row.apply_tax ? 1 : 0,
                    unit_price: linePrice(row),
                    price_type: row.type || 'R'
                };
            })
        };
    }

    function clearPaymentFields() {
        payAmountEdited = false;
        $('#sx-pay-received-input').val('0.00');
        $('#sx-pay-note').val('');
        setPayMethod('cash');
    }

    function resetCart() {
        cart = [];
        discountType = 'fixed';
        discountValue = 0;
        holdId = null;
        clearPaymentFields();
        renderCart();
    }

    function updateNavState() {
        pages = Math.max(1, pages || 1);
        page = Math.min(Math.max(1, page), pages);
        $('#sx-pos-page').text(page + ' / ' + pages);
        $('#sx-pos-prev').prop('disabled', page <= 1).toggleClass('is-disabled', page <= 1);
        $('#sx-pos-next').prop('disabled', page >= pages).toggleClass('is-disabled', page >= pages);
    }

    function flashNav($btn) {
        $btn.addClass('is-flash');
        setTimeout(function () { $btn.removeClass('is-flash'); }, 280);
    }

    function loadCatalog(opts) {
        opts = opts || {};
        var $busy = $('#sx-pos-nav .pos-nav-btn');
        $busy.addClass('is-busy');
        return $.getJSON(catalogUrl, { category_id: categoryId || '', page: page })
            .done(function (data) {
                pages = data.pages || 1;
                if (data.page) {
                    page = data.page;
                }
                updateNavState();
                var cats = '<button type="button" class="pos-cat' + (!categoryId ? ' is-active' : '') + '" data-id="">All</button>';
                (data.categories || []).forEach(function (cat) {
                    cats += '<button type="button" class="pos-cat' + (String(categoryId) === String(cat.id) ? ' is-active' : '') + '" data-id="' + cat.id + '">' + escapeHtml(cat.name) + '</button>';
                });
                $('#sx-pos-cats').html(cats);
                var tiles = '';
                (data.products || []).forEach(function (item) {
                    var empty = !(item.qty > 0);
                    tiles += '<button type="button" class="pos-tile' + (empty ? ' is-empty' : '') + '" data-item=\'' + JSON.stringify(item).replace(/'/g, '&#39;') + '\'>';
                    tiles += '<div class="pos-tile-meta">Qty: ' + escapeHtml(item.qty_display || String(item.qty)) + '</div>';
                    tiles += '<div class="pos-tile-icon">';
                    if (item.image) {
                        tiles += '<img src="' + item.image + '" alt="">';
                    } else {
                        tiles += '<span class="pos-no-photo"><i class="fa fa-camera"></i></span>';
                    }
                    tiles += '</div><div class="pos-tile-name">' + escapeHtml(item.name) + '</div>';
                    tiles += '<div class="pos-tile-price">' + money(item.price) + '</div>';
                    tiles += '</button>';
                });
                $('#sx-pos-grid').html(tiles || '<div class="pos-empty">No items</div>');
                if (opts.notify) {
                    var $grid = $('#sx-pos-grid');
                    $grid.addClass('is-reloaded');
                    setTimeout(function () { $grid.removeClass('is-reloaded'); }, 450);
                }
            })
            .fail(function (xhr) {
                updateNavState();
                Swal.fire({
                    icon: 'error',
                    title: 'Catalog failed',
                    text: (xhr.responseJSON && xhr.responseJSON.message) || 'Could not load POS items.'
                });
            })
            .always(function () {
                $busy.removeClass('is-busy');
            });
    }

    $(document).on('click', '.pos-cat', function () {
        categoryId = $(this).data('id') || null;
        page = 1;
        loadCatalog();
    });
    $(document).on('click', '#sx-pos-prev', function (e) {
        e.preventDefault();
        flashNav($(this));
        if (page <= 1) {
            return;
        }
        page -= 1;
        loadCatalog();
    });
    $(document).on('click', '#sx-pos-next', function (e) {
        e.preventDefault();
        flashNav($(this));
        if (page >= pages) {
            return;
        }
        page += 1;
        loadCatalog();
    });
    $(document).on('click', '#sx-pos-refresh, #sx-pos-ok', function (e) {
        e.preventDefault();
        flashNav($(this));
        if ($(this).is('#sx-pos-refresh') && $(this).find('i').length) {
            $(this).find('i').addClass('fa-spin');
            var $icon = $(this).find('i');
            loadCatalog({ notify: true }).always(function () {
                setTimeout(function () { $icon.removeClass('fa-spin'); }, 400);
            });
            return;
        }
        loadCatalog({ notify: true });
    });
    $(document).on('click', '#sx-pos-cust-nav', function (e) {
        e.preventDefault();
        flashNav($(this));
        openCustomer();
    });

    $(document).on('click', '.pos-unit-chip', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var item = $(this).closest('.pos-tile, .pos-search-item').data('item');
        if (typeof item === 'string') {
            item = JSON.parse(item.replace(/&#39;/g, "'"));
        }
        openSellModal(item, $(this).data('unit-key') || null);
        $('#sx-pos-search').val('');
        $('#sx-pos-results').prop('hidden', true).empty();
    });

    $(document).on('click', '.pos-tile', function (e) {
        if ($(e.target).closest('.pos-unit-chip').length) {
            return;
        }
        var item = $(this).data('item');
        if (typeof item === 'string') {
            item = JSON.parse(item.replace(/&#39;/g, "'"));
        }
        if (item.packaging) {
            openSellModal(item, item.scan_sell_as || null);
        } else {
            addProduct(item, 1, item.scan_sell_as || 'base');
        }
    });

    var searchSeq = 0;
    var scan = { buf: '', last: 0, target: null, start: null };
    var scanAudio = null;

    function codeMatchesItem(item, code) {
        if (String(item.barcode || '') === code || String(item.sku || '') === code) return true;
        if (item.scan_sell_as || item.scan_unit_id) return true;
        return (item.selling_units || []).some(function (unit) {
            return String(unit.barcode || '') === code;
        });
    }
    function pickScanItem(items, code) {
        var exact = (items || []).filter(function (item) { return codeMatchesItem(item, code); });
        if (exact.length === 1) return exact[0];
        if (exact.length > 1) {
            var scanned = exact.filter(function (item) { return item.scan_sell_as || item.scan_unit_id; });
            if (scanned.length === 1) return scanned[0];
        }
        return items.length === 1 ? items[0] : null;
    }
    function showSearchResults(items) {
        if (!items.length) {
            $('#sx-pos-results').html('<div class="pos-search-empty">No item for this code</div>').prop('hidden', false);
            return;
        }
        var html = '';
        items.forEach(function (item) {
            html += '<button type="button" class="pos-search-item" data-item=\'' + JSON.stringify(item).replace(/'/g, '&#39;') + '\'><strong>' + escapeHtml(item.name) + '</strong>';
            html += ' <small>' + money(item.price) + '</small>';
            html += '</button>';
        });
        $('#sx-pos-results').html(html).prop('hidden', false);
    }
    function scanBeep(ok) {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!scanAudio) scanAudio = new Ctx();
            if (scanAudio.state === 'suspended') scanAudio.resume();
            var tone = scanAudio.createOscillator();
            var gain = scanAudio.createGain();
            tone.type = 'square';
            tone.frequency.value = ok ? 880 : 220;
            gain.gain.value = 0.05;
            tone.connect(gain);
            gain.connect(scanAudio.destination);
            tone.start();
            tone.stop(scanAudio.currentTime + (ok ? 0.06 : 0.14));
        } catch (err) {}
    }
    function flashScanBox() {
        var box = $('.pos-search-wrap');
        box.addClass('is-scan');
        setTimeout(function () { box.removeClass('is-scan'); }, 180);
    }
    function placeFoundItem(item, fromExactScan) {
        if (fromExactScan && (item.scan_sell_as || item.scan_unit_id)) {
            addProduct(item, 1, item.scan_sell_as || 'base');
        } else if (item.packaging) {
            openSellModal(item, item.scan_sell_as || null);
        } else {
            addProduct(item, 1, item.scan_sell_as || 'base');
        }
        $('#sx-pos-search').val('');
        $('#sx-pos-results').prop('hidden', true).empty();
        if (!$('.modal.in:visible, .modal.show:visible').length) {
            $('#sx-pos-search').focus();
        }
    }
    function addByCode(raw) {
        var code = $.trim(raw);
        if (!code) return;
        var seq = ++searchSeq;
        $.getJSON(searchUrl, { q: code }).done(function (items) {
            if (seq !== searchSeq) return;
            var item = pickScanItem(items, code);
            if (!item) {
                $('#sx-pos-search').val(code).focus().select();
                showSearchResults(items);
                scanBeep(false);
                return;
            }
            placeFoundItem(item, codeMatchesItem(item, code));
            flashScanBox();
            scanBeep(true);
        });
    }
    function rememberScan(e) {
        var now = Date.now();
        if (now - scan.last > 70) {
            scan.buf = '';
            scan.target = e.target;
            var tag = ((e.target && e.target.tagName) || '').toLowerCase();
            var isSearch = e.target && e.target.id === 'sx-pos-search';
            scan.start = (!isSearch && (tag === 'input' || tag === 'textarea')) ? e.target.value : null;
        }
        scan.buf += e.key;
        scan.last = now;
    }
    function takeScan(e) {
        var burst = scan.buf.length >= 4 && (Date.now() - scan.last) <= 70;
        var code = scan.buf;
        var target = scan.target;
        var start = scan.start;
        scan.buf = '';
        scan.start = null;
        if (!burst) return null;
        e.preventDefault();
        if (target && start != null && (!target.id || target.id !== 'sx-pos-search')) {
            target.value = start;
            if (target.id === 'sx-pay-received-input') {
                payAmountEdited = false;
                refreshPayPanel();
            }
        }
        return code;
    }

    $('#sx-pos-search').on('input', function () {
        var q = $.trim($(this).val());
        clearTimeout(searchTimer);
        var seq = ++searchSeq;
        if (!q) {
            $('#sx-pos-results').prop('hidden', true).empty();
            return;
        }
        searchTimer = setTimeout(function () {
            $.getJSON(searchUrl, { q: q }).done(function (items) {
                if (seq !== searchSeq) return;
                if (!items.length) {
                    $('#sx-pos-results').prop('hidden', true).empty();
                    return;
                }
                showSearchResults(items);
            });
        }, 200);
    });
    $(document).on('click', '.pos-search-item', function (e) {
        if ($(e.target).closest('.pos-unit-chip').length) {
            return;
        }
        var item = $(this).data('item');
        if (typeof item === 'string') item = JSON.parse(item.replace(/&#39;/g, "'"));
        if (item.packaging) {
            openSellModal(item, item.scan_sell_as || null);
        } else {
            addProduct(item, 1, item.scan_sell_as || 'base');
        }
        $('#sx-pos-search').val('');
        $('#sx-pos-results').prop('hidden', true).empty();
    });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.pos-search-wrap').length) {
            $('#sx-pos-results').prop('hidden', true);
        }
    });

    function applyLineQty(i, qty) {
        var row = cart[i];
        if (!row) return;
        if (!(qty > 0)) {
            cart.splice(i, 1);
            renderCart();
            return;
        }
        var clamped = clampLineQty(row.id, row.unit_factor || 1, row.stock_base || 0, !!row.track_stock, qty, i);
        if (clamped.limited) {
            notifyStock(row.name, clamped.max, row.unit_name);
        }
        if (clamped.qty <= 0) {
            cart.splice(i, 1);
            renderCart();
            return;
        }
        cart[i].quantity = clamped.qty;
        renderCart();
        if (clamped.limited) {
            var field = $('.pos-qty[data-i="' + i + '"]').get(0);
            if (field) field.focus();
        }
    }
    $(document).on('click', '.pos-step', function () {
        var i = $(this).data('i');
        if (!cart[i]) return;
        applyLineQty(i, +(cart[i].quantity + parseInt($(this).data('d'), 10)).toFixed(4));
    });
    $(document).on('input', '.pos-qty', function () {
        var i = $(this).data('i');
        var row = cart[i];
        if (!row || !row.track_stock) return;
        var qty = parseFloat($(this).val());
        if (!(qty > 0)) return;
        var clamped = clampLineQty(row.id, row.unit_factor || 1, row.stock_base || 0, true, qty, i);
        if (!clamped.limited) return;
        applyLineQty(i, qty);
    });
    $(document).on('change', '.pos-qty', function () {
        applyLineQty($(this).data('i'), parseFloat($(this).val()));
    });
    $(document).on('input', '.pos-serial', function () {
        cart[$(this).data('i')].serial = $(this).val();
    });
    $(document).on('change', '.pos-type', function () {
        cart[$(this).data('i')].type = $(this).val();
        renderCart();
    });
    $(document).on('change', '.pos-tax', function () {
        cart[$(this).data('i')].apply_tax = this.checked;
        renderCart();
    });
    $(document).on('click', '.pos-remove', function () {
        cart.splice($(this).data('i'), 1);
        renderCart();
    });

    $(document).on('click', '#sx-pos-dashboard', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        window.location.href = this.getAttribute('href') || @json(route('dashboard', [], false));
    });
    $('#sx-pos-new').on('click', resetCart);
    $('#sx-pos-disc-btn').on('click', function (e) {
        e.preventDefault();
        $('#sx-pos-disc-type').val(discountType);
        $('#sx-pos-disc-value').val(discountValue || 0);
        $('#sx-pos-discount-modal').modal('show');
    });
    $('#sx-pos-disc-apply').on('click', function () {
        discountType = $('#sx-pos-disc-type').val() === 'percent' ? 'percent' : 'fixed';
        discountValue = Math.max(0, parseFloat($('#sx-pos-disc-value').val()) || 0);
        if (discountType === 'percent') {
            discountValue = Math.min(100, discountValue);
        }
        $('#sx-pos-discount-modal').modal('hide');
        renderCart();
    });

    function openCustomer() {
        $('#sx-pos-customer-form')[0].reset();
        $('#sx-pos-customer-modal').modal('show');
    }
    $('#sx-pos-add-cust, #sx-pos-cust').on('click', openCustomer);
    $('#sx-pos-customer-form').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: customerUrl,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            data: $(this).serialize()
        }).done(function (data) {
            var $opt = $('<option>', {
                value: data.id,
                text: customerMenuLabel(data.name, data.phone, 0),
                selected: true
            });
            $opt.attr('data-walk-in', '0');
            $opt.attr('data-points', '0.00');
            $opt.attr('data-credit-limit', data.credit_limit != null ? data.credit_limit : 0);
            $opt.attr('data-credit-used', data.credit_used != null ? data.credit_used : 0);
            $('#sx-pos-customer').append($opt).trigger('change');
            updateCreditUi();
            $('#sx-pos-customer-modal').modal('hide');
        }).fail(function () {
            Swal.fire({ icon: 'error', title: 'Could not save customer' });
        });
    });

    function requireCart() {
        if (!cart.length) {
            Swal.fire({ icon: 'warning', title: 'Add an item' });
            return false;
        }
        return true;
    }

    $('#sx-pos-hold').on('click', function () {
        if (!requireCart()) return;
        $.ajax({
            url: holdUrl,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            data: cartPayload()
        }).done(function (data) {
            $('#sx-pos-hold-count').text(data.held_count || 0);
            resetCart();
            if (walkInId) {
                $('#sx-pos-customer').val(String(walkInId)).trigger('change');
            }
            Swal.fire({ icon: 'success', title: 'Held', text: data.number, timer: 1400, showConfirmButton: false });
        }).fail(function (xhr) {
            Swal.fire({ icon: 'error', title: 'Hold failed', text: (xhr.responseJSON && xhr.responseJSON.message) || 'Could not hold sale.' });
        });
    });

    var walkInId = @json($walkInId);
    var loyaltyRules = @json($loyalty);
    var payWithPoints = false;

    function selectedCustomerOption() {
        return $('#sx-pos-customer option:selected');
    }
    function canSellOnCredit() {
        var $opt = selectedCustomerOption();
        if (!$opt.length) return false;
        if (String($opt.data('walk-in')) === '1') return false;
        if (walkInId && String($opt.val()) === String(walkInId)) return false;
        return true;
    }
    function updateCreditUi() {
        var creditOk = canSellOnCredit();
        $('#sx-pay-credit-note').prop('hidden', !creditOk);
    }

    function roundMoney(n) {
        return Math.round((n || 0) * 100) / 100;
    }
    function selectedPayMethod() {
        return $('#sx-pay-method').val() || 'cash';
    }
    function setPayMethod(method) {
        $('#sx-pay-method').val(method);
        $('.pos-pay-method').removeClass('is-active');
        $('.pos-pay-method[data-method="' + method + '"]').addClass('is-active');
        var labels = { cash: 'Cash received', mpesa: 'M-Pesa received', card: 'Card amount' };
        $('#sx-pay-received-label').text(labels[method] || 'Amount received');
    }
    function receivedAmount() {
        return roundMoney(parseFloat($('#sx-pay-received-input').val()) || 0);
    }
    function loyaltyOffer(grandBefore) {
        var opt = selectedCustomerOption();
        var available = parseFloat(opt.attr('data-points')) || 0;
        var walkIn = String(opt.data('walk-in')) === '1' || (walkInId && String(opt.val()) === String(walkInId));
        var each = parseFloat(loyaltyRules && loyaltyRules.point_value) || 0;
        var can = !walkIn && each > 0;
        var minPoints = parseFloat(loyaltyRules && loyaltyRules.min_redeem) || 0;
        var maxPoints = parseFloat(loyaltyRules && loyaltyRules.max_redeem) || 0;
        var cap = available;
        if (maxPoints > 0) cap = Math.min(cap, maxPoints);
        var worth = roundMoney(Math.max(0, cap * each));
        var amount = roundMoney(Math.min(worth, Math.max(0, grandBefore || 0)));
        var points = each > 0 ? roundMoney(Math.min(cap, amount / each)) : 0;
        amount = roundMoney(Math.min(points * each, Math.max(0, grandBefore || 0)));
        var meetsMin = minPoints <= 0 || available + 0.0001 >= minPoints;
        var usable = can && meetsMin && available > 0 && each > 0;
        var ready = usable && points > 0 && amount > 0;
        var note = 'Select a customer account';
        if (!walkIn && each <= 0) note = 'Set the shilling value of 1 point in Loyalty Settings';
        else if (!walkIn && available <= 0) note = 'This customer has no points';
        else if (!walkIn && !meetsMin) note = 'Needs ' + money(minPoints) + ' points. This customer has ' + money(available);
        else if (!walkIn) note = money(available) + ' points × KSh ' + money(each) + ' = KSh ' + money(worth);
        return { ready: ready, usable: usable, points: points, amount: amount, each: each, note: note };
    }
    function loyaltyQuote(grandBefore) {
        var offer = loyaltyOffer(grandBefore);
        var on = payWithPoints && offer.ready;
        var due = roundMoney(Math.max(0, (grandBefore || 0) - (on ? offer.amount : 0)));
        $('#sx-pay-with-points').prop('disabled', false).toggleClass('is-active', on);
        $('#sx-loyalty-caption').text(on
            ? (money(offer.points) + ' pts × KSh ' + money(offer.each) + ' = KSh ' + money(offer.amount) + ' off. Enter KSh ' + money(due))
            : offer.note);
        if (!on) {
            $('#sx-loyalty-points').val('0');
            $('#sx-loyalty-deducted').prop('hidden', true);
            return { points: 0, amount: 0 };
        }
        $('#sx-loyalty-points').val(offer.points.toFixed(2));
        $('#sx-loyalty-discount').text('KSh ' + money(offer.amount));
        $('#sx-loyalty-deducted').prop('hidden', false);
        return { points: offer.points, amount: offer.amount };
    }
    function refreshPayPanel() {
        var t = totals();
        var fee = 0;
        var loy = loyaltyQuote(t.grand);
        var due = roundMoney(Math.max(0, t.grand - loy.amount) + fee);
        if (!payAmountEdited) {
            writeReceived(due);
        }
        var received = receivedAmount();
        $('#sx-pay-sale').text('KSh ' + money(t.grand));
        $('#sx-pay-sale-line').prop('hidden', loy.amount <= 0);
        $('#sx-pay-amount').text('KSh ' + money(due));
        $('#sx-pay-fee').text('KSh ' + money(fee));
        $('#sx-pay-total').text('KSh ' + money(due));
        $('#sx-pay-change').text('KSh ' + money(Math.max(0, received - due)));
        updateCreditUi();
    }
    function writeReceived(amount) {
        fillingReceived = true;
        $('#sx-pay-received-input').val(money(amount));
        fillingReceived = false;
    }
    $('#sx-pos-customer').on('change', function () {
        payWithPoints = false;
        payAmountEdited = false;
        $('#sx-loyalty-points').val('0');
        refreshPayPanel();
    });
    $('#sx-pay-with-points').on('click', function (e) {
        e.preventDefault();
        var offer = loyaltyOffer(totals().grand);
        var active = $(this).hasClass('is-active');
        if (!active && !offer.ready) {
            Swal.fire({ icon: 'info', title: 'Pay with points', text: offer.note });
            return;
        }
        payWithPoints = !active;
        payAmountEdited = false;
        refreshPayPanel();
    });
    $(document).on('click', '.pos-pay-method', function () {
        setPayMethod($(this).data('method'));
        $('#sx-pay-received-input').focus().select();
    });
    $('#sx-pay-received-input').on('input', function () {
        if (fillingReceived) return;
        payAmountEdited = true;
        refreshPayPanel();
    });
    $('#sx-pay-received-input').on('focus', function () {
        if ((parseFloat($(this).val()) || 0) <= 0) {
            $(this).val(money(payableTotal()));
            refreshPayPanel();
        }
        $(this).select();
    });

    function payableTotal() {
        var t = totals();
        return roundMoney(Math.max(0, t.grand - loyaltyQuote(t.grand).amount));
    }
    function buildPaymentsFromTenders() {
        var due = payableTotal();
        var method = selectedPayMethod();
        var amount = receivedAmount();
        var adjusted = false;
        if (amount <= 0) {
            return { payments: [], adjusted: false, grand: due };
        }
        if (method !== 'cash' && amount - due > 0.009) {
            amount = due;
            adjusted = true;
        }
        var applied = method === 'cash' ? Math.min(amount, due) : amount;
        applied = roundMoney(applied);
        if (applied <= 0) {
            return { payments: [], adjusted: adjusted, grand: due };
        }
        return { payments: [{ method: method, amount: applied }], adjusted: adjusted, grand: due };
    }

    function submitSale(payments, opts) {
        opts = opts || {};
        var t = totals();
        var due = payableTotal();
        var paySum = payments.reduce(function (sum, row) { return sum + row.amount; }, 0);
        var balanceDue = roundMoney(Math.max(0, due - paySum));
        var creditOk = canSellOnCredit();

        if (balanceDue > 0.009 && !creditOk) {
            Swal.fire({
                icon: 'warning',
                title: 'Full payment required',
                text: 'Walk-in customers cannot buy on credit. Select a customer account or receive full payment.'
            });
            return;
        }

        if (balanceDue > 0.009 && creditOk && !opts.confirmedCredit) {
            var $opt = selectedCustomerOption();
            var limit = parseFloat($opt.data('credit-limit')) || 0;
            var used = parseFloat($opt.data('credit-used')) || 0;
            var nextUsed = roundMoney(used + balanceDue);
            var limitNote = limit > 0
                ? (' Credit used after this sale: ' + money(nextUsed) + ' / limit ' + money(limit) + '.')
                : '';
            Swal.fire({
                icon: 'question',
                title: 'Charge ' + money(balanceDue) + ' to account?',
                text: 'Customer will owe the unpaid balance.' + limitNote,
                showCancelButton: true,
                confirmButtonText: 'Yes, charge account',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.isConfirmed) {
                    submitSale(payments, { confirmedCredit: true });
                }
            });
            return;
        }

        var payload = cartPayload();
        payload.notes = $('#sx-pay-note').val();
        payload.service_type = '';
        payload.payments = payments;
        payload.payment_amount = Math.min(due, paySum);
        payload.payment_method = payments[0] ? payments[0].method : 'cash';
        payload.loyalty_points = loyaltyQuote(t.grand).points;
        payload.on_credit = balanceDue > 0.009 ? 1 : 0;
        if (balanceDue > 0.009) {
            payload.document_type = 'invoice';
        }
        $.ajax({
            url: orderUrl,
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            data: payload
        }).done(function (data) {
            // Refresh credit used for selected customer after a credit sale.
            if (balanceDue > 0.009) {
                var $opt = selectedCustomerOption();
                var used = parseFloat($opt.data('credit-used')) || 0;
                $opt.attr('data-credit-used', roundMoney(used + balanceDue));
            }
            if (data.loyalty_points != null) {
                selectedCustomerOption().attr('data-points', data.loyalty_points);
            }
            resetCart();
            if (data.receipt_url) {
                window.open(data.receipt_url, 'sx-pos-receipt', 'width=380,height=720');
            }
            var msg = data.receipt_number || data.invoice_number || data.number;
            if (balanceDue > 0.009) {
                msg = (msg || 'Sale') + ' — balance due ' + money(balanceDue);
            }
            Swal.fire({ icon: 'success', title: 'Sale saved', text: msg, timer: 1800, showConfirmButton: false });
        }).fail(function (xhr) {
            Swal.fire({ icon: 'error', title: 'Order failed', text: (xhr.responseJSON && xhr.responseJSON.message) || 'Could not complete order.' });
        });
    }

    $('#sx-pos-pay-form').on('submit', function (e) {
        e.preventDefault();
        if (!requireCart()) return;
        var built = buildPaymentsFromTenders();
        var paySum = built.payments.reduce(function (sum, row) { return sum + row.amount; }, 0);
        var dueNow = roundMoney(Math.max(0, totals().grand - loyaltyQuote(totals().grand).amount));
        if (paySum <= 0 && dueNow > 0.009 && !canSellOnCredit()) {
            Swal.fire({ icon: 'warning', title: 'Enter a payment amount' });
            return;
        }
        if (built.adjusted) {
            Swal.fire({
                icon: 'info',
                title: 'Payment adjusted',
                text: 'M-Pesa and card cannot be more than the total. Extra change is for cash only.'
            });
        }
        submitSale(built.payments);
    });

    function openHolds() {
        $('#sx-pos-holds-rows').html('<tr><td colspan="6">Loading...</td></tr>');
        $('#sx-pos-holds-modal').modal('show');
        $.getJSON(holdsUrl).done(function (rows) {
            if (!rows.length) {
                $('#sx-pos-holds-rows').html('<tr><td colspan="6">No held sales.</td></tr>');
                return;
            }
            var html = '';
            rows.forEach(function (row, i) {
                html += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(row.number) + '</td><td>' + escapeHtml(row.customer) + '</td><td>' + row.total + '</td><td>' + row.held_at + '</td>';
                html += '<td>';
                html += '<button type="button" class="btn btn-primary btn-xs sx-resume-hold" data-url="' + row.url + '">Resume</button> ';
                html += '<button type="button" class="btn btn-danger btn-xs sx-cancel-hold" data-id="' + row.id + '" data-url="' + row.cancel_url + '" data-number="' + escapeHtml(row.number) + '">Cancel</button>';
                html += '</td></tr>';
            });
            $('#sx-pos-holds-rows').html(html);
        });
    }
    $('#sx-pos-holds-top, #sx-pos-holds-link').on('click', function (e) {
        e.preventDefault();
        openHolds();
    });
    function loadHold(url) {
        $.getJSON(url).done(function (data) {
            holdId = data.id;
            if ((parseFloat(data.discount_percent) || 0) > 0) {
                discountType = 'percent';
                discountValue = parseFloat(data.discount_percent) || 0;
            } else {
                discountType = 'fixed';
                discountValue = parseFloat(data.discount_amount) || 0;
            }
            if (data.customer_id) $('#sx-pos-customer').val(String(data.customer_id)).trigger('change');
            if (data.due_date) $('#sx-pos-due').val(data.due_date);
            cart = (data.items || []).map(function (item) {
                return {
                    id: item.id,
                    line_key: String(item.id) + ':' + String(item.sell_as || item.unit_id || 0),
                    product_unit_id: item.unit_id || null,
                    sell_as: item.sell_as || 'base',
                    unit_name: item.unit_name || '',
                    name: item.name,
                    price: item.price,
                    wholesale_price: item.wholesale_price || 0,
                    promo_price: item.promo_price || 0,
                    tax_inclusive: !!item.tax_inclusive,
                    tax_rate: item.tax_rate || 0,
                    tax_amount: item.tax_amount || 0,
                    apply_tax: item.apply_tax === true || item.apply_tax === 1 || item.apply_tax === '1',
                    type: 'R',
                    serial: '',
                    unit_factor: parseFloat(item.unit_factor) || 1,
                    track_stock: !!(item.manage_stock && !item.allow_negative_stock),
                    stock_base: parseFloat(item.qty) || 0,
                    quantity: item.qty_sold || 1
                };
            });
            cart.forEach(function (row, i) {
                if (!row.track_stock) return;
                var clamped = clampLineQty(row.id, row.unit_factor || 1, row.stock_base || 0, true, row.quantity, i);
                if (!clamped.limited) return;
                row.quantity = clamped.qty;
                notifyStock(row.name, clamped.max, row.unit_name);
            });
            cart = cart.filter(function (row) { return row.quantity > 0; });
            renderCart();
            $('#sx-pos-holds-modal').modal('hide');
        });
    }

    $(document).on('click', '.sx-resume-hold', function () {
        loadHold($(this).data('url'));
    });
    $(document).on('click', '.sx-cancel-hold', function () {
        var button = $(this);
        var id = button.data('id');
        var number = button.data('number') || 'This hold';
        Swal.fire({
            icon: 'warning',
            title: 'Cancel this hold?',
            text: number + ' will be removed from the hold list.',
            showCancelButton: true,
            confirmButtonText: 'Cancel hold',
            cancelButtonText: 'Keep it'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $.ajax({
                url: button.data('url'),
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
            }).done(function (data) {
                if (String(holdId) === String(id)) {
                    resetCart();
                }
                $('#sx-pos-hold-count').text(data.held_count || 0);
                openHolds();
                Swal.fire({ icon: 'success', title: 'Hold cancelled', timer: 1200, showConfirmButton: false });
            }).fail(function (xhr) {
                Swal.fire({ icon: 'error', title: 'Could not cancel hold', text: (xhr.responseJSON && xhr.responseJSON.message) || 'Try again.' });
            });
        });
    });

    var holdParam = new URLSearchParams(window.location.search).get('hold');
    if (holdParam) {
        loadHold(@json(url('/pos/holds')) + '/' + encodeURIComponent(holdParam));
    }

    $(document).on('keydown', function (e) {
        var modalOpen = $('.modal.in:visible, .modal.show:visible').length > 0;
        if (!modalOpen && e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            rememberScan(e);
        }
        if (!modalOpen && (e.key === 'Enter' || e.key === 'Tab')) {
            var scanned = takeScan(e);
            if (scanned) {
                addByCode(scanned);
                return;
            }
            if (e.key === 'Enter' && e.target && e.target.id === 'sx-pos-search') {
                e.preventDefault();
                addByCode($('#sx-pos-search').val());
                return;
            }
        }
        if (!modalOpen && (e.key === 'F1' || e.key === 'F2' || e.key === 'F3')) {
            e.preventDefault();
            if (e.key === 'F1') setPayMethod('cash');
            if (e.key === 'F2') setPayMethod('mpesa');
            if (e.key === 'F3') setPayMethod('card');
            $('#sx-pay-received-input').focus().select();
            return;
        }
        if (e.key === 'F10') {
            e.preventDefault();
            $('#sx-pos-search').focus();
        }
        if (e.key === 'F4') {
            e.preventDefault();
            $('#sx-pos-hold').click();
        }
        if (e.key === 'F7') {
            e.preventDefault();
            openCustomer();
        }
    });

    renderCart();
    $('#sx-pos-search').focus();
})(jQuery);
</script>
@endpush
