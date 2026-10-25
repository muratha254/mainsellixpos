(function () {
    var seed = window.SellixVariationSeed || { attributes: [], variations: [] };
    var canCost = !!window.SellixVariationCanCost;
    var maxCombinations = 100;
    var select = document.getElementById('sx-has-variants');
    var builder = document.getElementById('sx-variation-builder');
    var list = document.getElementById('sx-variation-attributes');
    var tableBody = document.querySelector('#sx-variation-table tbody');
    var warning = document.getElementById('sx-variation-warning');
    var payload = document.getElementById('sx-variation-payload');
    var parentStock = document.getElementById('sx-parent-stock');
    var form = document.querySelector('.sx-item-form');
    if (!select || !builder || !list || !tableBody || !form) return;

    var saved = {};
    (seed.variations || []).forEach(function (row) {
        if (row && row.signature) saved[row.signature] = row;
    });

    function signature(options) {
        var pairs = Object.keys(options).map(function (name) {
            return [String(name).toLowerCase(), String(options[name]).trim().toLowerCase()];
        }).sort(function (a, b) {
            return a[0] < b[0] ? -1 : 1;
        });
        return pairs.map(function (pair) { return pair[0] + '=' + pair[1]; }).join('|');
    }

    function readAttributes() {
        var rows = [];
        list.querySelectorAll('.sx-attr-row').forEach(function (row) {
            var name = row.querySelector('.sx-attr-name').value.trim();
            var values = row.querySelector('.sx-attr-values').value.split(',').map(function (value) {
                return value.trim();
            }).filter(Boolean);
            rows.push({ name: name, values: values });
        });
        return rows;
    }

    function combinations(attributes) {
        var sets = [[]];
        attributes.forEach(function (attribute) {
            var next = [];
            sets.forEach(function (set) {
                attribute.values.forEach(function (value) {
                    var copy = set.slice();
                    copy.push({ name: attribute.name, value: value });
                    next.push(copy);
                });
            });
            sets = next;
        });
        return sets.map(function (set) {
            var options = {};
            set.forEach(function (part) { options[part.name] = part.value; });
            return {
                options: options,
                name: set.map(function (part) { return part.value; }).join(' / '),
                signature: signature(options)
            };
        });
    }

    function field(value) {
        return value === null || value === undefined ? '' : value;
    }

    function renderTable() {
        var attributes = readAttributes();
        var problems = [];
        var names = {};
        attributes.forEach(function (attribute) {
            if (!attribute.name) problems.push('Every attribute needs a name.');
            var key = attribute.name.toLowerCase();
            if (key && names[key]) problems.push('Attribute "' + attribute.name + '" is listed twice.');
            names[key] = true;
            var seen = {};
            attribute.values.forEach(function (value) {
                var valueKey = value.toLowerCase();
                if (seen[valueKey]) problems.push('Value "' + value + '" is repeated on ' + attribute.name + '.');
                seen[valueKey] = true;
            });
            if (attribute.name && !attribute.values.length) problems.push('Add at least one value for ' + attribute.name + '.');
        });
        var usable = attributes.filter(function (attribute) { return attribute.name && attribute.values.length; });
        var combos = usable.length ? combinations(usable) : [];
        if (combos.length > maxCombinations) {
            problems.push('That is ' + combos.length + ' variations. Keep the list at ' + maxCombinations + ' or fewer.');
            combos = combos.slice(0, maxCombinations);
        }
        warning.textContent = problems.filter(function (item, index, all) { return all.indexOf(item) === index; }).join(' ');
        tableBody.innerHTML = '';
        combos.forEach(function (combo) {
            var prior = saved[combo.signature] || {};
            var tr = document.createElement('tr');
            tr.dataset.signature = combo.signature;
            tr.dataset.name = combo.name;
            var cells = '<td>' + combo.name.replace(/[&<>]/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch];
            }) + '</td>' +
                '<td><input class="form-control input-sm sx-var-sku" value="' + field(prior.sku) + '"></td>' +
                '<td><input class="form-control input-sm sx-var-barcode" value="' + field(prior.barcode) + '"></td>';
            if (canCost) {
                cells += '<td><input type="number" step="0.01" min="0" class="form-control input-sm sx-var-cost" value="' + field(prior.purchase_price) + '" placeholder="Parent"></td>';
            }
            cells += '<td><input type="number" step="0.01" min="0" class="form-control input-sm sx-var-price" value="' + field(prior.selling_price) + '" placeholder="Parent"></td>' +
                '<td><input type="number" step="0.0001" class="form-control input-sm sx-var-stock" value="' + field(prior.stock) + '"></td>' +
                '<td><input type="number" step="0.0001" min="0" class="form-control input-sm sx-var-reorder" value="' + field(prior.reorder_level) + '" placeholder="Parent"></td>' +
                '<td><select class="form-control input-sm sx-var-active"><option value="1"' + (prior.is_active === false ? '' : ' selected') + '>Yes</option><option value="0"' + (prior.is_active === false ? ' selected' : '') + '>No</option></select></td>';
            tr.innerHTML = cells;
            tableBody.appendChild(tr);
        });
        writePayload();
    }

    function writePayload() {
        var variations = [];
        tableBody.querySelectorAll('tr').forEach(function (row) {
            var item = {
                signature: row.dataset.signature,
                name: row.dataset.name,
                sku: row.querySelector('.sx-var-sku').value,
                barcode: row.querySelector('.sx-var-barcode').value,
                selling_price: row.querySelector('.sx-var-price').value,
                stock: row.querySelector('.sx-var-stock').value,
                reorder_level: row.querySelector('.sx-var-reorder').value,
                is_active: row.querySelector('.sx-var-active').value === '1'
            };
            if (canCost) item.purchase_price = row.querySelector('.sx-var-cost').value;
            variations.push(item);
            saved[item.signature] = item;
        });
        payload.value = JSON.stringify({
            attributes: readAttributes(),
            variations: variations
        });
    }

    function addAttribute(name, values) {
        var row = document.createElement('div');
        row.className = 'row sx-attr-row';
        row.style.marginBottom = '8px';
        row.innerHTML = '<div class="col-md-3"><input class="form-control sx-attr-name" placeholder="Attribute name, e.g. Size" value="' + (name || '') + '"></div>' +
            '<div class="col-md-7"><input class="form-control sx-attr-values" placeholder="Values separated by commas" value="' + (values || []).join(', ') + '"></div>' +
            '<div class="col-md-2"><button type="button" class="btn btn-danger btn-sm sx-remove-attr">Remove</button></div>';
        list.appendChild(row);
    }

    function toggle() {
        var on = select.value === '1';
        builder.hidden = !on;
        if (parentStock) parentStock.style.display = on ? 'none' : '';
        if (on && !list.children.length) addAttribute('', []);
        if (on) renderTable();
        else payload.value = '';
    }

    list.addEventListener('input', renderTable);
    list.addEventListener('click', function (event) {
        if (!event.target.classList.contains('sx-remove-attr')) return;
        var rows = list.querySelectorAll('.sx-attr-row');
        if (rows.length === 1) return;
        event.target.closest('.sx-attr-row').remove();
        renderTable();
    });
    document.getElementById('sx-add-attribute').addEventListener('click', function () {
        addAttribute('', []);
    });
    tableBody.addEventListener('input', writePayload);
    tableBody.addEventListener('change', writePayload);
    select.addEventListener('change', toggle);
    form.addEventListener('submit', function () {
        if (select.value === '1') writePayload();
    });

    (seed.attributes || []).forEach(function (attribute) {
        addAttribute(attribute.name, attribute.values || []);
    });
    if (select.value === '1' || seed.enabled) {
        select.value = '1';
    }
    toggle();
})();
