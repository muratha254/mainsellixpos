<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $sale->receipt_number ?: $sale->invoice_number ?: $sale->number }}</title>
    <style>
        @page { size: {{ $paper }} auto; margin: 3mm; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: "Courier New", Courier, monospace;
            font-size: {{ $paper === '58mm' ? '12px' : '13px' }};
            font-weight: 700;
            line-height: 1.3;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .ticket, .ticket div, .ticket td, .ticket th, .ticket span {
            color: #000;
            font-weight: 700;
        }
        .ticket {
            width: {{ $paper === '58mm' ? '52mm' : '72mm' }};
            margin: 0 auto;
            padding: 2px 0 8px;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .logo {
            display: block;
            max-width: 46mm;
            max-height: 22mm;
            margin: 0 auto 4px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .company { font-size: {{ $paper === '58mm' ? '14px' : '16px' }}; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; }
        .location { font-size: 11px; font-weight: 700; text-transform: uppercase; margin-top: 2px; }
        .title { font-weight: 700; margin: 6px 0 4px; }
        .meta { margin: 0 0 6px; }
        .meta div { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 1px 0; vertical-align: top; }
        .items { table-layout: fixed; }
        .items .name { width: 40%; }
        .items .qty { width: 14%; }
        .items .price { width: 22%; }
        .items .amount { width: 24%; }
        .items th { font-size: 11px; border-bottom: 1px dotted #000; padding-bottom: 3px; text-transform: none; }
        .num { text-align: right; white-space: nowrap; }
        .name { text-transform: uppercase; word-wrap: break-word; }
        .meta-split td { padding: 0; }
        .sum { margin-top: 3px; border-top: 1px dotted #000; border-bottom: 1px dotted #000; }
        .sum td { font-weight: 700; padding: 3px 0; }
        .paymode { margin-top: 8px; }
        .pay td { padding-top: 1px; }
        .pay .method { width: 18%; }
        .pay .mamt { width: 32%; text-align: right; padding-right: 8px; }
        .pay .plabel { width: 22%; }
        .pay .pamt { width: 28%; }
        .paybill, .confirm { font-size: 11px; }
        .paybill { margin-top: 8px; }
        .no-print { text-align: center; margin: 12px 0; }
        .no-print button {
            background: #c9a027;
            color: #fff;
            border: 0;
            padding: 8px 16px;
            font-weight: 700;
            cursor: pointer;
        }
        @media print {
            .no-print { display: none; }
            html, body, .ticket, .ticket div, .ticket td, .ticket th, .ticket span {
                color: #000 !important;
                font-weight: 700 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
@php
    $options = $receipt ?? [];
    $show = function (string $key) use ($options) {
        return ! empty($options[$key]);
    };
    $number = $sale->receipt_number ?: $sale->invoice_number ?: $sale->number;
    $customerName = optional($sale->customer)->is_walk_in ? '' : trim((string) optional($sale->customer)->name);
    $pieces = (float) $sale->items->sum('quantity');
    $qtyText = abs($pieces - round($pieces)) < 0.001 ? number_format($pieces, 0) : number_format($pieces, 1);
    $byMethod = ['cash' => 0.0, 'mpesa' => 0.0, 'card' => 0.0];
    $otherPayments = [];
    foreach ($sale->payments as $payment) {
        $method = (string) $payment->method;
        if (array_key_exists($method, $byMethod)) {
            $byMethod[$method] += (float) $payment->amount;
        } else {
            $otherPayments[] = $payment;
        }
    }
    $methodNames = ['cash' => 'Cash', 'mpesa' => 'Mpesa', 'card' => 'Card'];
    $labels = $paymentLabels ?? config('sellix.payment_methods', []);
    $usedMethods = [];
    foreach ($byMethod as $method => $amount) {
        if ($amount > 0.009) {
            $usedMethods[] = ['label' => $methodNames[$method], 'amount' => $amount];
        }
    }
    foreach ($otherPayments as $payment) {
        $amount = (float) $payment->amount;
        if ($amount <= 0.009) {
            continue;
        }
        $usedMethods[] = [
            'label' => $labels[$payment->method] ?? ucfirst((string) $payment->method),
            'amount' => $amount,
        ];
    }
    $tendered = (float) $sale->payments->sum('amount');
    if ($tendered <= 0) {
        $tendered = (float) $sale->paid_amount;
    }
    $nonCash = $tendered - $byMethod['cash'];
    $dueAfterNonCash = max(0, (float) $sale->total - min($nonCash, (float) $sale->total));
    $change = $byMethod['cash'] > 0
        ? max(0, $byMethod['cash'] - $dueAfterNonCash)
        : max(0, $tendered - (float) $sale->total);
    $cashier = strtoupper(optional($sale->cashier)->name ?: '');
    $paybill = trim((string) ($options['paybill'] ?? ''));
    $accountNo = trim((string) ($options['account_no'] ?? ''));
    $pin = trim((string) ($profile['companyTaxPin'] ?? ''));
    if (in_array($pin, ['', '-', '0'], true)) {
        $pin = '';
    }
    $showPin = $show('show_pin');
    $logoSrc = receipt_logo_src($profile);
    $showLogo = $logoSrc !== '' && ($show('show_logo') || is_file((string) ($profile['logo_pdf_path'] ?? '')));
@endphp
<div class="ticket">
    @if($showLogo)
        <img src="{{ $logoSrc }}" alt="Logo" class="logo">
    @endif
    @if($show('show_name'))
        <div class="center company">{{ $profile['companyName'] ?? fleet_system_name() }}</div>
    @endif
    @if($show('show_address') && ! empty($profile['companyAddress']) && $profile['companyAddress'] !== '-')
        <div class="center location">{{ $profile['companyAddress'] }}</div>
    @endif
    @if($show('show_phone') && ! empty($profile['companyPhone']) && $profile['companyPhone'] !== '-')
        <div class="center bold">TEL: {{ $profile['companyPhone'] }}</div>
    @endif
    @if($show('show_email') && ! empty($profile['companyEmail']) && $profile['companyEmail'] !== '-')
        <div class="center">{{ $profile['companyEmail'] }}</div>
    @endif
    <div class="center title">{{ $options['heading'] ?? 'Original Cash Sale Receipt' }}</div>

    <div class="meta">
        @if($showPin)
            <div>Pin No: {{ $pin }}</div>
        @endif
        <table class="meta-split">
            <tr>
                <td>Rct No: {{ $number }}</td>
                <td class="num">Date: {{ optional($sale->created_at)->format('d-M-Y H:i') }}</td>
            </tr>
        </table>
        <div>Sold To: {{ $customerName }}</div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Product</th>
                <th class="num qty">Qty</th>
                <th class="num price">Price</th>
                <th class="num amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
                @php
                    $qty = number_format((float) $item->quantity, 1);
                @endphp
                <tr>
                    <td class="name">{{ $item->name }}</td>
                    <td class="num">{{ $qty }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 1) }}</td>
                    <td class="num">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table class="sum">
        <tr>
            <td>#Pcs: {{ $qtyText }}</td>
            <td class="num">Total {{ number_format((float) $sale->total, 2) }}</td>
        </tr>
    </table>

    @php
        $cashierDiscount = max(0, (float) $sale->discount_amount - (float) $sale->loyalty_discount);
    @endphp
    @if($cashierDiscount > 0.009 || (float) $sale->loyalty_discount > 0.009)
        <table>
            @if($cashierDiscount > 0.009)
                <tr>
                    <td>Discount</td>
                    <td class="num">{{ number_format($cashierDiscount, 2) }}</td>
                </tr>
            @endif
            @if((float) $sale->loyalty_discount > 0.009)
                <tr>
                    <td>Points</td>
                    <td class="num">{{ number_format((float) $sale->loyalty_points_redeemed, 2) }} pts · {{ number_format((float) $sale->loyalty_discount, 2) }}</td>
                </tr>
            @endif
        </table>
    @endif

    <div class="paymode">Paymode:</div>
    <table class="pay">
        <tr>
            <td class="method">Cash</td>
            <td class="mamt">{{ number_format($byMethod['cash'], 2) }}</td>
            <td class="plabel">Tendered</td>
            <td class="num pamt">{{ number_format($tendered, 2) }}</td>
        </tr>
        <tr>
            <td class="method">Mpesa</td>
            <td class="mamt">{{ number_format($byMethod['mpesa'], 2) }}</td>
            <td class="plabel">Change</td>
            <td class="num pamt">{{ number_format($change, 2) }}</td>
        </tr>
        <tr>
            <td class="method">Card</td>
            <td class="mamt">{{ number_format($byMethod['card'], 2) }}</td>
            <td></td>
            <td></td>
        </tr>
        @foreach($usedMethods as $method)
            @if(! in_array($method['label'], ['Cash', 'Mpesa', 'Card'], true))
                <tr>
                    <td class="method">{{ $method['label'] }}</td>
                    <td class="mamt">{{ number_format($method['amount'], 2) }}</td>
                    <td></td>
                    <td></td>
                </tr>
            @endif
        @endforeach
    </table>

    @if($paybill !== '' || $accountNo !== '')
        <div class="center bold paybill">
            @if($paybill !== '')PAYBILL NO. {{ $paybill }}@endif
            @if($accountNo !== '') ACCOUNT NO. {{ $accountNo }}@endif
        </div>
    @endif
    @if($cashier !== '')
        <div class="center" style="margin-top:6px;">You were served by: {{ $cashier }}</div>
    @endif
    @if(! empty($options['confirm_note']))
        <div class="center bold confirm" style="margin-top:8px;">{{ $options['confirm_note'] }}</div>
    @endif
    @if(! empty($options['vat_note']))
        <div class="center" style="margin-top:8px;">{{ $options['vat_note'] }}</div>
    @endif
    @if(! empty($options['footer']))
        <div class="center bold" style="margin-top:4px;">{{ $options['footer'] }}</div>
    @endif

    <div class="no-print">
        <button type="button" onclick="window.print()">Print Receipt</button>
    </div>
</div>
<script>
    window.addEventListener('load', function () {
        var logo = document.querySelector('.logo');
        var printSlip = function () {
            setTimeout(function () { window.print(); }, 200);
        };
        if (!logo || logo.complete) {
            printSlip();
            return;
        }
        logo.addEventListener('load', printSlip);
        logo.addEventListener('error', printSlip);
    });
</script>
</body>
</html>
