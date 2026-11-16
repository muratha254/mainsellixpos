<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $systemName ?? 'Sellix POS' }} | {{ $sale->documentNumber() }}</title>
    @include('documents.branded-styles')
</head>
<body>
    <p class="no-print">
        <button type="button" onclick="window.print()">Print</button>
        <a href="{{ route('invoices.pdf', $sale) }}">Download PDF</a>
        <button type="button" onclick="window.close()">Close</button>
    </p>
    @include('documents.branded-body')
</body>
</html>
