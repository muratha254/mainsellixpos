<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>POS {{ $sale->documentNumber() }}</title>
    <style>
        body, td, th, div { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #000; font-weight: 700; }
        body { margin: 0; padding: 6px; }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .company { font-size: 14px; font-weight: 700; text-transform: uppercase; }
        .location { font-size: 9px; font-weight: 700; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 1px 0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .name { text-transform: uppercase; }
        .items { table-layout: fixed; }
        .items th { font-size: 9px; border-bottom: 1px dotted #000; padding-bottom: 3px; text-transform: none; }
        .sum { margin-top: 3px; border-top: 1px dotted #000; border-bottom: 1px dotted #000; }
        .sum td { font-weight: 700; padding: 3px 0; }
        .pay .mamt { text-align: right; padding-right: 8px; }
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
    $paybill = trim((string) ($options['paybill'] ?? ''));
    $accountNo = trim((string) ($options['account_no'] ?? ''));
    $pin = trim((string) ($profile['companyTaxPin'] ?? ''));
    if (in_array($pin, ['', '-', '0'], true)) {
        $pin = '';
    }
    $showPin = $show('show_pin') && $pin !== '';
    $logoSrc = receipt_logo_src($profile);
    $showLogo = $logoSrc !== '' && ($show('show_logo') || is_file((string) ($profile['logo_pdf_path'] ?? '')));
@endphp
    @if($showLogo)
        <div class="center"><img src="{{ $logoSrc }}" alt="Logo" style="max-width:160px;max-height:70px;"></div>
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
    <div class="center bold" style="margin:6px 0;">{{ $options['heading'] ?? 'Original Cash Sale Receipt' }}</div>
    <div>
        @if($showPin)
            <div>Pin No: {{ $pin }}</div>
        @endif
        <div>Rct No: {{ $number }}</div>
        <div>Date: {{ optional($sale->created_at)->format('d-M-Y H:i') }}</div>
        <div>Sold To: {{ $customerName }}</div>
    </div>
    <table class="items">
        <thead>
            <tr>
                <th>Product</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
                <tr>
                    <td class="name">{{ $item->name }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 1) }}</td>
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
    <div style="margin-top:8px;">Paymode:</div>
    <table class="pay">
        @foreach($usedMethods as $index => $method)
            <tr>
                <td>{{ $method['label'] }}</td>
                <td class="mamt">{{ number_format($method['amount'], 2) }}</td>
                @if($index === 0)
                    <td>Tendered</td>
                    <td class="num">{{ number_format($tendered, 2) }}</td>
                @elseif($index === 1)
                    <td>Change</td>
                    <td class="num">{{ number_format($change, 2) }}</td>
                @else
                    <td></td>
                    <td></td>
                @endif
            </tr>
        @endforeach
        @if(count($usedMethods) === 0)
            <tr>
                <td></td>
                <td></td>
                <td>Tendered</td>
                <td class="num">{{ number_format($tendered, 2) }}</td>
            </tr>
        @endif
        @if(count($usedMethods) < 2)
            <tr>
                <td></td>
                <td></td>
                <td>Change</td>
                <td class="num">{{ number_format($change, 2) }}</td>
            </tr>
        @endif
    </table>
    @if($paybill !== '' || $accountNo !== '')
        <div class="center bold" style="margin-top:8px;">
            @if($paybill !== '')PAYBILL NO. {{ $paybill }}@endif
            @if($accountNo !== '') ACCOUNT NO. {{ $accountNo }}@endif
        </div>
    @endif
    <div class="center" style="margin-top:6px;">You were served by: {{ strtoupper(optional($sale->cashier)->name ?: '') }}</div>
    @if(! empty($options['confirm_note']))
        <div class="center bold" style="margin-top:8px;">{{ $options['confirm_note'] }}</div>
    @endif
    @if(! empty($options['vat_note']))
        <div class="center">{{ $options['vat_note'] }}</div>
    @endif
    @if(! empty($options['footer']))
        <div class="center bold">{{ $options['footer'] }}</div>
    @endif
</body>
</html>
