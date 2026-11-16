@php
    $profile = $profile ?? ($companyProfile ?? []);
    $logoSrc = function_exists('receipt_logo_src') ? receipt_logo_src($profile) : '';
    $companyName = trim((string) ($profile['companyName'] ?? ''));
    $pin = trim((string) ($profile['companyTaxPin'] ?? ''));
    $contact = [];
    foreach ([
        'Address' => $profile['companyAddress'] ?? '',
        'Email' => $profile['companyEmail'] ?? '',
        'Web' => $profile['companyWebsite'] ?? '',
        'Phone' => $profile['companyPhone'] ?? '',
    ] as $label => $bit) {
        $bit = trim((string) $bit);
        if ($bit !== '' && $bit !== '-') {
            $contact[] = ['label' => $label, 'value' => $bit];
        }
    }

    $source = isset($quotation) ? $quotation : $sale;
    $isQuote = isset($quotation);
    $title = $isQuote ? 'QUOTATION' : 'INVOICE';
    $numberLabel = $isQuote ? 'QUOTE NO:' : 'INVOICE NO:';
    $number = $isQuote ? $quotation->number : $sale->documentNumber();
    $dateText = $isQuote
        ? (optional($quotation->quote_date)->format('j F Y') ?: '')
        : (optional($sale->sale_date)->format('j F Y') ?: '');
    $dueText = $isQuote
        ? (optional($quotation->valid_until)->format('j F Y') ?: '')
        : (optional($sale->due_date)->format('j F Y') ?: '');
    $customer = $source->customer;
    $subtotal = (float) $source->subtotal;
    $discount = (float) $source->discount_amount;
    $taxAmount = (float) $source->tax_amount;
    $grand = (float) $source->total;
    $notes = $source->notes ?? null;
    $terms = $isQuote ? ($quotation->terms ?? null) : null;

    $lines = [];
    $rates = [];
    foreach ($source->items as $item) {
        $rates[] = round((float) $item->tax_rate, 2);
        $size = trim((string) ($item->size ?? ''));
        if ($size === '') {
            $size = trim((string) ($item->color ?? ''));
        }
        $lineAmount = round((float) $item->line_total - (float) $item->tax_amount, 2);
        $description = $isQuote
            ? ($item->description ?: optional($item->product)->name)
            : $item->displayName();
        $lines[] = [
            'description' => $description,
            'size' => $size,
            'qty' => rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.'),
            'rate' => number_format((float) $item->unit_price, 2),
            'total' => number_format($lineAmount, 2),
        ];
    }

    $uniqueRates = array_values(array_unique($rates));
    if (count($uniqueRates) === 1) {
        $rateText = rtrim(rtrim(number_format($uniqueRates[0], 2, '.', ''), '0'), '.');
        $taxLabel = 'VAT: ['.$rateText.'%]';
    } else {
        $taxLabel = 'VAT:';
    }

    if (! $customer || $customer->is_walk_in) {
        $billName = 'WALK IN';
        $billLines = [];
    } else {
        $billName = mb_strtoupper((string) $customer->name);
        $address = implode(', ', array_filter([
            trim((string) $customer->address),
            trim((string) $customer->estate),
            trim((string) $customer->county),
        ]));
        $billLines = array_values(array_filter([
            trim((string) $customer->email),
            trim((string) ($customer->phone ?: $customer->mobile)),
            $address,
        ]));
    }
    $money = function ($amount) {
        return number_format((float) $amount, 2);
    };
    $footerSettings = app(\App\Services\SettingsService::class);
    $footerCompanyId = (int) ($documentCompanyId ?? optional(auth()->user())->company_id);
    $footerLocation = trim((string) $footerSettings->get($footerCompanyId, 'document_footer_location', ''));
    $footerPayment = trim((string) $footerSettings->get($footerCompanyId, 'document_footer_payment', ''));
    $footerNote = trim((string) $footerSettings->get($footerCompanyId, 'document_footer_note', ''));
    $companyTerms = isset($documentCompanyTerms)
        ? trim((string) $documentCompanyTerms)
        : trim((string) optional(optional(auth()->user())->company)->quotation_terms);
    $termsText = trim((string) ($terms ?? ''));
    if ($termsText === '') {
        $termsText = $companyTerms;
    }
    $termLines = [];
    foreach (preg_split('/\r\n|\r|\n/', $termsText) ?: [] as $line) {
        $line = trim((string) preg_replace('/^\d+[\.\)\-]\s*/', '', trim($line)));
        if ($line !== '') {
            $termLines[] = $line;
        }
    }
@endphp

<table class="accent" cellspacing="0" cellpadding="0">
    <tr>
        <td class="accent-slash"><div class="accent-mark"></div></td>
        <td class="accent-bar">&nbsp;</td>
    </tr>
</table>

<table class="brand">
    <tr>
        <td class="brand-side" width="48%" valign="middle">
            @if($logoSrc !== '')
                <img class="logo" src="{{ $logoSrc }}" alt="{{ $companyName }}">
            @elseif($companyName !== '')
                <div class="company-name">{{ $companyName }}</div>
            @endif
        </td>
        <td class="brand-side" width="52%" align="right" valign="middle">
            <table class="contact-card" align="right">
                <tr>
                    <td class="doc-title" colspan="2">{{ $title }}</td>
                </tr>
                @foreach($contact as $line)
                    <tr>
                        <td class="cl">{{ $line['label'] }}</td>
                        <td class="cv">{{ $line['value'] }}</td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td width="52%">
            <div class="bill-label">BILL TO:</div>
            <div class="bill-name">{{ $billName }}</div>
            @foreach($billLines as $line)
                <div class="bill-line">{{ $line }}</div>
            @endforeach
        </td>
        <td width="48%" align="right">
            <table class="meta" align="right">
                <tr>
                    <td class="k">{{ $numberLabel }}</td>
                    <td class="v">{{ $number }}</td>
                </tr>
                <tr>
                    <td class="k">DATE:</td>
                    <td class="v">{{ $dateText }}</td>
                </tr>
                <tr>
                    <td class="k">DUE DATE:</td>
                    <td class="v">{{ $dueText }}</td>
                </tr>
                <tr>
                    <td class="k">PIN:</td>
                    <td class="v">{{ $pin }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th width="6%">#</th>
            <th width="40%">DESCRIPTION</th>
            <th width="14%">SIZE</th>
            <th width="10%">QTY</th>
            <th width="14%">RATE</th>
            <th width="16%">TOTAL</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
            <tr class="{{ $loop->iteration % 2 === 0 ? 'alt' : '' }}">
                <td class="c">{{ $loop->iteration }}</td>
                <td class="desc">{{ $line['description'] }}</td>
                <td class="c">{{ $line['size'] }}</td>
                <td class="c">{{ $line['qty'] }}</td>
                <td class="r">{{ $line['rate'] }}</td>
                <td class="r">{{ $line['total'] }}</td>
            </tr>
        @endforeach
        <tr class="{{ (count($lines) + 1) % 2 === 0 ? 'alt' : '' }}">
            <td>&nbsp;</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
    </tbody>
</table>

<table class="totals-wrap">
    <tr>
        <td class="totals-side" width="62%" valign="top">
            @if(count($termLines))
                <table class="terms" cellspacing="0" cellpadding="0">
                    <tr>
                        <td class="terms-head" colspan="2">TERMS AND CONDITIONS</td>
                    </tr>
                    @foreach($termLines as $line)
                        <tr>
                            <td class="terms-num">{{ $loop->iteration }}.</td>
                            <td>{{ $line }}</td>
                        </tr>
                    @endforeach
                </table>
                <div class="terms-sign">Customer Acceptance (sign below):</div>
                <div class="terms-line"></div>
            @endif
            @if($notes)
                <div class="notes">
                    <div><strong>Notes:</strong> {{ $notes }}</div>
                </div>
            @endif
        </td>
        <td class="totals-box" width="38%" align="right" valign="top">
            <table class="totals">
                <tr>
                    <td>SUBTOTAL:</td>
                    <td class="amt">{{ $money($subtotal) }}</td>
                </tr>
                @if($discount > 0.0001)
                    <tr>
                        <td>DISCOUNT:</td>
                        <td class="amt">{{ $money($discount) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>{{ $taxLabel }}</td>
                    <td class="amt">{{ $money($taxAmount) }}</td>
                </tr>
                <tr class="grand">
                    <td>TOTAL:</td>
                    <td class="amt">{{ $money($grand) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if($footerLocation !== '' || $footerPayment !== '' || $footerNote !== '')
    <table class="doc-footer" cellspacing="0" cellpadding="0">
        @if($footerLocation !== '' || $footerPayment !== '')
            <tr>
                @if($footerLocation !== '')
                    <td width="{{ $footerPayment !== '' ? '50%' : '100%' }}">
                        <div class="foot-label">Location</div>
                        <div class="foot-text">{!! nl2br(e($footerLocation)) !!}</div>
                    </td>
                @endif
                @if($footerPayment !== '')
                    <td class="{{ $footerLocation !== '' ? 'foot-split' : '' }}" width="{{ $footerLocation !== '' ? '50%' : '100%' }}">
                        <div class="foot-label">Payment</div>
                        <div class="foot-text">{!! nl2br(e($footerPayment)) !!}</div>
                    </td>
                @endif
            </tr>
        @endif
        @if($footerNote !== '')
            <tr>
                <td class="foot-note" colspan="2">{!! nl2br(e($footerNote)) !!}</td>
            </tr>
        @endif
    </table>
    <div class="foot-close"></div>
@endif
