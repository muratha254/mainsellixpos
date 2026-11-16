<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #222; }
        h2, h3 { margin: 0 0 6px; }
        .muted { color: #666; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding-bottom: 12px; }
        .items { margin-top: 12px; }
        .items th, .items td { border: 1px solid #ccc; padding: 6px 8px; }
        .items th { background: #c9a027; color: #fff; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 280px; margin-left: auto; margin-top: 12px; }
        .totals td { padding: 3px 0; border: 0; }
        .grand { font-weight: 700; font-size: 14px; }
        img { max-height: 52px; max-width: 160px; }
        .notes { margin-top: 16px; }
    </style>
</head>
<body>
@php
    $money = function ($amount) {
        return number_format((float) $amount, 2);
    };
    $company = $profile['companyName'] ?? $companyName ?? 'Sellix POS';
@endphp
    <table class="head">
        <tr>
            <td width="60%">
                @if(!empty($logo_pdf_path) && file_exists($logo_pdf_path))
                    <img src="{{ $logo_pdf_path }}" alt=""><br>
                @endif
                <h2>{{ $company }}</h2>
                @if(!empty($profile['companyAddress']) && $profile['companyAddress'] !== '-')
                    <div class="muted">{{ $profile['companyAddress'] }}</div>
                @endif
                <div class="muted">
                    {{ $profile['companyPhone'] ?? '' }}
                    @if(!empty($profile['companyEmail'])) | {{ $profile['companyEmail'] }} @endif
                </div>
                @if(!empty($profile['companyTaxPin']))
                    <div class="muted">VAT PIN: {{ $profile['companyTaxPin'] }}</div>
                @endif
            </td>
            <td width="40%" align="right">
                <h3>QUOTATION</h3>
                <div><strong>{{ $quotation->number }}</strong></div>
                <div class="muted">Date: {{ optional($quotation->quote_date)->format('d-m-Y') }}</div>
                <div class="muted">Valid until: {{ optional($quotation->valid_until)->format('d-m-Y') ?: '-' }}</div>
                <div class="muted">Status: {{ $statuses[$quotation->status] ?? ucfirst((string) $quotation->status) }}</div>
            </td>
        </tr>
    </table>

    <div>
        <strong>Customer</strong><br>
        {{ optional($quotation->customer)->name ?: '-' }}
        @if(optional($quotation->customer)->phone)
            <div class="muted">{{ $quotation->customer->phone }}</div>
        @endif
        @if(optional($quotation->customer)->email)
            <div class="muted">{{ $quotation->customer->email }}</div>
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Tax</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->description ?: optional($item->product)->name }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->tax_amount) }}</td>
                    <td class="num">{{ $money($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ $money($quotation->subtotal) }}</td>
        </tr>
        <tr>
            <td>Discount</td>
            <td class="num">{{ $money($quotation->discount_amount) }}</td>
        </tr>
        <tr>
            <td>Tax</td>
            <td class="num">{{ $money($quotation->tax_amount) }}</td>
        </tr>
        <tr class="grand">
            <td>Grand Total</td>
            <td class="num">Ksh {{ $money($quotation->total) }}</td>
        </tr>
    </table>

    @if($quotation->notes || $quotation->terms)
        <div class="notes">
            @if($quotation->notes)
                <div><strong>Notes:</strong> {{ $quotation->notes }}</div>
            @endif
            @if($quotation->terms)
                <div><strong>Terms:</strong> {{ $quotation->terms }}</div>
            @endif
        </div>
    @endif
</body>
</html>
