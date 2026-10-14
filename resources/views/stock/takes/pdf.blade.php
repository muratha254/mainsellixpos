<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 12mm 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .meta { margin: 0 0 12px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 5px 6px; vertical-align: top; }
        th { background: #1f4e79; color: #fff; text-align: left; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        .num { text-align: right; }
        .blank { height: 18px; }
        .pages { position: fixed; right: 0; bottom: -8mm; font-size: 9px; color: #555; }
        .pages:after { content: "Page " counter(page) " of " counter(pages); }
    </style>
</head>
<body>
    <div class="pages"></div>
    <h1>{{ $profile['companyName'] ?? 'Stock Take' }}</h1>
    <div class="meta">
        <strong>Stock Take Sheet</strong><br>
        Branch: {{ optional($take->branch)->name }}<br>
        Reference: {{ $take->number }}<br>
        Date generated: {{ optional($take->created_at)->format('d-M-Y H:i') }}
    </div>
    <table>
        <thead>
            <tr>
                <th style="width:14%;">SKU</th>
                <th>Item</th>
                <th style="width:10%;">Unit</th>
                <th style="width:12%;" class="num">System Qty</th>
                <th style="width:14%;">Physical Count</th>
                <th style="width:22%;">Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach($take->lines as $line)
                <tr>
                    <td>{{ $line->sku }}</td>
                    <td>{{ $line->item_name }}</td>
                    <td>{{ $line->unit_name }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->system_quantity, 4, '.', ''), '0'), '.') ?: '0' }}</td>
                    <td class="blank"></td>
                    <td class="blank"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
