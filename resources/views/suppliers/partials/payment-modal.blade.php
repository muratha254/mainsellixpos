@if($canPay ?? false)
<div class="modal fade" id="sx-supplier-pay-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content sx-conversion-modal">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="fa fa-money"></i> Add Supplier Payment</h4>
            </div>
            <div class="modal-body">
                <div class="sx-po-pay-meta">
                    <div><span>Supplier</span> <strong id="sx-su-pay-name">-</strong></div>
                    <div><span>Outstanding</span> <strong id="sx-su-pay-due">0.00</strong></div>
                </div>
                <form method="post" action="#" id="sx-supplier-pay-form">
                    @csrf
                    <div class="form-group">
                        <label class="sx-req">Amount*</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="sx-su-pay-amount" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="sx-req">Payment Type*</label>
                        <select name="method" class="form-control" required>
                            <option value="">-Select-</option>
                            @foreach(($paymentMethods ?? []) as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reference / Voucher No</label>
                        <input type="text" name="reference" class="form-control" placeholder="Reference">
                    </div>
                    <div class="form-group">
                        <label class="sx-req">Payment Date*</label>
                        <input type="date" name="paid_at" class="form-control" required value="{{ now()->toDateString() }}">
                    </div>
                    <div class="form-group">
                        <label>Payment Note</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="sx-form-actions">
                        <button type="submit" class="btn btn-success">Submit</button>
                        <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
(function ($) {
    $(document).on('click', '.sx-open-supplier-pay', function (e) {
        e.preventDefault();
        var btn = $(this);
        var due = parseFloat(btn.data('due-raw')) || 0;
        if (due <= 0) {
            if (window.Swal) {
                Swal.fire({ icon: 'info', title: 'Nothing to pay', text: 'This supplier has no outstanding balance.' });
            } else {
                window.alert('This supplier has no outstanding balance.');
            }
            return;
        }
        $('#sx-supplier-pay-form').attr('action', btn.data('url'));
        $('#sx-su-pay-name').text(btn.data('name') || '-');
        $('#sx-su-pay-due').text(btn.data('due') || '0.00');
        $('#sx-su-pay-amount').attr('max', due.toFixed(2)).val(due.toFixed(2));
        $('#sx-supplier-pay-form').find('select[name="method"]').val('');
        $('#sx-supplier-pay-form').find('input[name="reference"]').val('');
        $('#sx-supplier-pay-form').find('textarea[name="notes"]').val('');
        $('#sx-supplier-pay-form').find('input[name="paid_at"]').val(@json(now()->toDateString()));
        $('#sx-supplier-pay-modal').modal('show');
    });
})(jQuery);
</script>
@endpush
