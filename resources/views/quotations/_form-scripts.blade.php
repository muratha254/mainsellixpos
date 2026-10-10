<script>
(function ($) {
    var rowIndex = $('#sx-qt-rows .sx-qt-row').length;

    function money(n) {
        return (Math.round((n + Number.EPSILON) * 100) / 100).toFixed(2);
    }

    function discountMoney(type, value, base) {
        value = parseFloat(value) || 0;
        base = Math.max(0, base || 0);
        if (type === 'percent') {
            return Math.min(base, base * (Math.min(100, Math.max(0, value)) / 100));
        }
        return Math.min(base, Math.max(0, value));
    }

    function recalc() {
        var subtotal = 0;
        var taxTotal = 0;

        $('#sx-qt-rows .sx-qt-row').each(function () {
            var $row = $(this);
            var qty = parseFloat($row.find('.sx-qt-qty').val()) || 0;
            var price = parseFloat($row.find('.sx-qt-price').val()) || 0;
            var taxRate = parseFloat($row.find('.sx-qt-tax').val()) || 0;
            var base = qty * price;
            var discount = discountMoney(
                $row.find('.sx-qt-discount-type').val() || 'fixed',
                $row.find('.sx-qt-discount').val(),
                base
            );
            var taxable = Math.max(0, base - discount);
            var tax = taxable * (taxRate / 100);
            var line = taxable + tax;
            subtotal += taxable;
            taxTotal += tax;
            $row.find('.sx-qt-line-total').text(money(line));
        });

        var headerDiscount = discountMoney(
            $('#sx-qt-discount-type').val() || 'fixed',
            $('#sx-qt-header-discount').val(),
            subtotal
        );
        var grand = Math.max(0, subtotal - headerDiscount + taxTotal);

        $('#sx-qt-subtotal').text(money(subtotal));
        $('#sx-qt-tax').text(money(taxTotal));
        $('#sx-qt-grand').text(money(grand));
    }

    function bindRow($row) {
        $row.find('.sx-qt-product').on('change', function () {
            var $opt = $(this).find('option:selected');
            var price = $opt.data('price');
            var tax = $opt.data('tax');
            if (price !== undefined && price !== '') {
                $row.find('.sx-qt-price').val(price);
            }
            if (tax !== undefined && tax !== '') {
                $row.find('.sx-qt-tax').val(tax);
            }
            recalc();
        });
        $row.find('.sx-qt-qty, .sx-qt-price, .sx-qt-tax, .sx-qt-discount, .sx-qt-discount-type').on('input change', recalc);
        $row.find('.sx-qt-remove').on('click', function () {
            if ($('#sx-qt-rows .sx-qt-row').length <= 1) {
                return;
            }
            $row.remove();
            recalc();
        });
    }

    $('#sx-qt-rows .sx-qt-row').each(function () {
        bindRow($(this));
    });

    $('#sx-qt-add-row').on('click', function () {
        var html = $('#sx-qt-row-template').html().replace(/__INDEX__/g, String(rowIndex++));
        var $row = $(html);
        $('#sx-qt-rows').append($row);
        bindRow($row);
        recalc();
    });

    $('#sx-qt-header-discount, #sx-qt-discount-type').on('input change', recalc);
    recalc();
})(jQuery);
</script>
