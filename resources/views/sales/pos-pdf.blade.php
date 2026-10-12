<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>POS {{ $sale->documentNumber() }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #000; margin: 0; padding: 6px; }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .company { font-size: 14px; font-weight: 700; text-transform: uppercase; }
        .location { font-size: 9px; font-weight: 700; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 1px 0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .name { text-transform: uppercase; }
        .items th { border-bottom: 1px solid #000; font-size: 9px; }
        .total-line td { border-top: 1px solid #000; border-bottom: 3px double #000; font-weight: 700; }
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
    foreach ($sale->payments as $payment) {
        $method = (string) $payment->method;
        if (array_key_exists($method, $byMethod)) {
            $byMethod[$method] += (float) $payment->amount;
        }
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
@endphp
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
    <table>
        <tr>
            <td>Pin No:</td>
            <td class="num">{{ $show('show_pin') ? ($profile['companyTaxPin'] ?? '') : '' }}</td>
        </tr>
        <tr>
            <td>Rct No: {{ $number }}</td>
            <td class="num">Date: {{ optional($sale->created_at)->format('d-M-Y H:i') }}</td>
        </tr>
        <tr><td colspan="2">Sold To : {{ $customerName }}</td></tr>
    </table>
    <table class="items">
        <thead>
            <tr>
                <th class="name">Product</th>
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
            <tr class="total-line">
                <td>#Pcs: {{ $qtyText }}</td>
                <td class="num" colspan="2">Total</td>
                <td class="num">{{ number_format((float) $sale->total, 2) }}</td>
            </tr>
        </tbody>
    </table>
    <table style="margin-top:8px;">
        <tr>
            <td>Paymode</td>
            <td></td>
            <td class="num">Tendered</td>
            <td class="num">{{ number_format($tendered, 2) }}</td>
        </tr>
        <tr>
            <td>Cash</td>
            <td class="num">{{ number_format($byMethod['cash'], 2) }}</td>
            <td class="num">Change</td>
            <td class="num">{{ number_format($change, 2) }}</td>
        </tr>
        <tr>
            <td>Mpesa</td>
            <td class="num">{{ number_format($byMethod['mpesa'], 2) }}</td>
            <td></td>
            <td></td>
        </tr>
        <tr>
            <td>Card</td>
            <td class="num">{{ number_format($byMethod['card'], 2) }}</td>
            <td></td>
            <td></td>
        </tr>
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
