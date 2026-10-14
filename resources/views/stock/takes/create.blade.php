@extends('layouts.fleet')
@section('title', 'New Stock Take')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'New Stock Take',
    'subtitle' => 'Choose the branch and the items to count. Creating a session does not change stock.',
    'backUrl' => route('stock.takes.index'),
    'breadcrumbs' => [
        ['label' => 'Home', 'url' => route('dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Stock Take', 'url' => route('stock.takes.index')],
        ['label' => 'New'],
    ],
])

<form method="post" action="{{ route('stock.takes.store') }}" class="sx-item-form" id="sx-stock-take-form">
    @csrf
    <div class="sx-box">
        <div class="sx-box-body" style="min-height:auto;">
            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label>Branch <span class="sx-req">*</span></label>
                        <select name="branch_id" class="form-control" required onchange="window.location='{{ route('stock.takes.create') }}?branch_id='+this.value">
                            @foreach($branches as $row)
                                <option value="{{ $row->id }}" @if((string) old('branch_id', $branchId) === (string) $row->id) selected @endif>{{ $row->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label>What to count <span class="sx-req">*</span></label>
                        <select name="scope" id="sx-take-scope" class="form-control" required>
                            <option value="all" @if(old('scope', 'all') === 'all') selected @endif>All products at this branch</option>
                            <option value="category" @if(old('scope') === 'category') selected @endif>One category</option>
                            <option value="products" @if(old('scope') === 'products') selected @endif>Selected products</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4" id="sx-take-category">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">~~Select~~</option>
                            @foreach($categories as $row)
                                <option value="{{ $row->id }}" @if((string) old('category_id') === (string) $row->id) selected @endif>{{ $row->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div id="sx-take-products" class="form-group">
                <label>Products</label>
                <div class="table-responsive" style="max-height:360px; overflow:auto; border:1px solid #e5e5e5;">
                    <table class="table table-bordered" style="margin-bottom:0;">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>Item</th>
                                <th>SKU</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $product)
                                <tr>
                                    <td><input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @if(in_array($product->id, array_map('intval', old('product_ids', [])))) checked @endif></td>
                                    <td>{{ $product->name }}</td>
                                    <td>{{ $product->sku }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3">No products are stocked at this branch.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" id="sx-take-save"><i class="fa fa-check"></i> Create Stock Take</button>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
    (function () {
        var scope = document.getElementById('sx-take-scope');
        var category = document.getElementById('sx-take-category');
        var products = document.getElementById('sx-take-products');
        function sync() {
            category.style.display = scope.value === 'category' ? '' : 'none';
            products.style.display = scope.value === 'products' ? '' : 'none';
        }
        scope.addEventListener('change', sync);
        sync();
        document.getElementById('sx-stock-take-form').addEventListener('submit', function () {
            var button = document.getElementById('sx-take-save');
            button.disabled = true;
            button.innerHTML = 'Creating...';
        });
    })();
</script>
@endpush
