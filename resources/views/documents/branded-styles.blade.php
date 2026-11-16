@php
    $documentColors = \App\Support\DocumentColors::forCompany((int) optional(auth()->user())->company_id);
@endphp
<style>
    @page { margin: 28px 32px; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; color: #1a1a1a; font-size: 12px; margin: 0; }
    table { border-collapse: collapse; }
    .accent { width: 100%; margin: 0 0 10px; }
    .accent td { height: 16px; padding: 0; line-height: 0; font-size: 0; }
    .accent-slash { width: 18px; background: {{ $documentColors['tint'] }}; }
    .accent-mark { border-top: 16px solid {{ $documentColors['accent'] }}; border-right: 14px solid {{ $documentColors['tint'] }}; width: 0; height: 0; line-height: 0; font-size: 0; }
    .accent-bar { background: {{ $documentColors['tint'] }}; }
    .contact-card .doc-title { text-align: right; font-size: 22px; font-weight: 700; letter-spacing: 1.2px; padding: 0 0 8px; color: #111; }
    .brand { width: 100%; margin: 8px 0 18px; border-bottom: 1px solid #e4e4e4; }
    .brand-side { vertical-align: middle; padding-bottom: 14px; }
    .logo { max-height: 68px; max-width: 220px; }
    .company-name { font-size: 18px; font-weight: 700; color: {{ $documentColors['header'] }}; margin: 0; }
    .contact-card { width: 280px; border-collapse: collapse; }
    .contact-card td { padding: 1px 0; border-bottom: 0; vertical-align: top; }
    .contact-card .cl { width: 62px; text-align: right; color: {{ $documentColors['header'] }}; font-size: 9px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase; padding: 2px 10px 2px 0; }
    .contact-card .cv { text-align: left; font-size: 11px; color: #222; padding: 2px 0; }
    .parties { width: 100%; margin-top: 8px; }
    .parties td { vertical-align: top; }
    .bill-label { font-weight: 700; font-size: 12px; margin-bottom: 8px; }
    .bill-name { font-weight: 700; font-size: 13px; margin-bottom: 4px; }
    .bill-line { font-size: 12px; line-height: 1.45; color: #333; }
    .meta { width: 280px; border-collapse: separate; border-spacing: 0 4px; }
    .meta td { padding: 6px 8px; font-size: 11px; font-weight: 700; }
    .meta .k { background: {{ $documentColors['header'] }}; color: #fff; width: 108px; }
    .meta .v { background: {{ $documentColors['tint'] }}; color: #1a1a1a; }
    .items { width: 100%; margin-top: 26px; border-bottom: 3px solid {{ $documentColors['header'] }}; }
    .items th { background: {{ $documentColors['header'] }}; color: #fff; font-size: 11px; font-weight: 700; text-align: center; padding: 8px 6px; }
    .items td { padding: 8px 6px; font-size: 12px; }
    .items tr.alt td { background: #f2f2f2; }
    .c { text-align: center; }
    .r { text-align: right; }
    .desc { text-align: left; }
    .totals-wrap { width: 100%; margin-top: 14px; }
    .totals-side { width: 62%; vertical-align: top; padding-right: 16px; }
    .totals-box { width: 38%; vertical-align: top; text-align: right; }
    .totals { width: 250px; margin-left: auto; }
    .totals td { border: 1px solid #c8c8c8; padding: 7px 8px; font-size: 12px; font-weight: 700; }
    .totals .amt { text-align: right; font-weight: 400; width: 120px; }
    .totals .grand td { font-size: 13px; }
    .notes { margin-top: 18px; font-size: 11px; color: #333; }
    .doc-footer { width: 100%; margin-top: 28px; border-top: 3px solid {{ $documentColors['header'] }}; background: {{ $documentColors['tint'] }}; }
    .doc-footer td { vertical-align: top; padding: 12px 14px; }
    .foot-label { color: {{ $documentColors['header'] }}; font-size: 10px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; margin-bottom: 4px; }
    .foot-text { font-size: 11px; line-height: 1.45; color: #222; }
    .foot-split { border-left: 1px solid {{ $documentColors['header'] }}; }
    .foot-note { text-align: center; font-size: 11px; color: #333; padding: 8px 12px 2px; }
    .foot-close { height: 6px; background: {{ $documentColors['header'] }}; margin-top: 8px; }
    .terms { width: 100%; margin-top: 0; border-collapse: collapse; }
    .terms .terms-head { background: {{ $documentColors['header'] }}; color: #fff; font-size: 12px; font-weight: 700; letter-spacing: 0.6px; padding: 7px 10px; }
    .terms td { font-size: 11px; color: #222; padding: 5px 0 2px; vertical-align: top; }
    .terms .terms-num { width: 28px; padding-left: 4px; font-weight: 700; }
    .terms-sign { margin-top: 14px; font-size: 12px; font-weight: 700; }
    .terms-line { width: 220px; border-bottom: 1px solid #222; height: 22px; margin-top: 4px; }
    .no-print { margin: 0 0 14px; }
    .no-print button, .no-print a { font-family: Arial, Helvetica, sans-serif; font-size: 13px; margin-right: 8px; }
    @media print { .no-print { display: none; } }
</style>
