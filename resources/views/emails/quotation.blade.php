<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $quotation->number }}</title>
</head>
<body style="font-family: Arial, sans-serif; color:#333; line-height:1.5;">
    @php
        $customer = $quotation->customer;
        $name = ($customer && ! $customer->is_walk_in) ? trim((string) $customer->name) : '';
        $companyName = trim((string) optional($quotation->company)->name);
    @endphp
    <p>{{ $name !== '' ? 'Hello '.$name.',' : 'Hello,' }}</p>
    <p>Please find quotation <strong>{{ $quotation->number }}</strong>{{ $companyName !== '' ? ' from '.$companyName : '' }} attached as a PDF.</p>
    <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;">
        <tr><td>Quotation</td><td><strong>{{ $quotation->number }}</strong></td></tr>
        <tr><td>Date</td><td>{{ optional($quotation->quote_date)->format('j F Y') }}</td></tr>
        @if($quotation->valid_until)
            <tr><td>Valid until</td><td>{{ $quotation->valid_until->format('j F Y') }}</td></tr>
        @endif
        <tr><td>Total</td><td><strong>KSh {{ number_format((float) $quotation->total, 2) }}</strong></td></tr>
    </table>
    <p>Thank you{{ $companyName !== '' ? ', '.$companyName : '' }}.</p>
</body>
</html>
