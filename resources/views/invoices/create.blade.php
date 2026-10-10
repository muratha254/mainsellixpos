@extends('layouts.fleet')

@section('title', 'New Invoice')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'Invoice',
    'subtitle' => 'Create Invoice',
    'backUrl' => route('invoices.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Invoices', 'url' => route('invoices.index')],
        ['label' => 'New Invoice'],
    ],
])

<form method="post" action="{{ route('invoices.store') }}" id="sx-invoice-form" class="sx-purchase-form">
    @csrf
    @include('invoices._form')
</form>
@endsection

@push('scripts')
@include('quotations._form-scripts')
@endpush
