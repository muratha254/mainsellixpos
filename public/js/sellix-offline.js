(function () {
    if (window.SellixOffline) {
        return;
    }

    var PAGE_SIZE = 6;
    var dbPromise = null;
    var syncing = false;
    var uiTimer = null;

    function meta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') || '' : '';
    }

    function userId() {
        return meta('sx-user-id') || 'guest';
    }

    function dbName() {
        return 'sellix-offline-u' + userId();
    }

    function prefix() {
        var snapshot = meta('sx-offline-snapshot');
        var ping = meta('sx-offline-ping');
        var source = snapshot || ping;
        if (!source) {
            return '';
        }
        try {
            var path = new URL(source, window.location.href).pathname;
            var cut = path.indexOf('/pos/');
            if (cut === -1) {
                cut = path.indexOf('/offline/');
            }
            return cut === -1 ? '' : path.slice(0, cut);
        } catch (e) {
            return '';
        }
    }

    function pathOf(url) {
        try {
            return new URL(url, window.location.href).pathname;
        } catch (e) {
            return String(url || '');
        }
    }

    function csrf() {
        return meta('csrf-token');
    }

    function clientKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'off' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function db() {
        if (!window.indexedDB || userId() === 'guest') {
            return Promise.reject(new Error('offline-db'));
        }
        if (!dbPromise) {
            dbPromise = new Promise(function (resolve, reject) {
                var open = window.indexedDB.open(dbName(), 1);
                open.onupgradeneeded = function () {
                    var database = open.result;
                    if (!database.objectStoreNames.contains('products')) {
                        database.createObjectStore('products', { keyPath: 'id' });
                    }
                    if (!database.objectStoreNames.contains('customers')) {
                        database.createObjectStore('customers', { keyPath: 'id' });
                    }
                    if (!database.objectStoreNames.contains('outbox')) {
                        database.createObjectStore('outbox', { keyPath: 'client_key' });
                    }
                    if (!database.objectStoreNames.contains('meta')) {
                        database.createObjectStore('meta', { keyPath: 'key' });
                    }
                };
                open.onsuccess = function () { resolve(open.result); };
                open.onerror = function () { reject(open.error); };
            });
        }
        return dbPromise;
    }

    function store(name, mode) {
        return db().then(function (database) {
            return database.transaction(name, mode).objectStore(name);
        });
    }

    function idbReq(request) {
        return new Promise(function (resolve, reject) {
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    }

    function put(storeName, value) {
        return store(storeName, 'readwrite').then(function (objectStore) {
            return idbReq(objectStore.put(value));
        });
    }

    function get(storeName, key) {
        return store(storeName, 'readonly').then(function (objectStore) {
            return idbReq(objectStore.get(key));
        });
    }

    function del(storeName, key) {
        return store(storeName, 'readwrite').then(function (objectStore) {
            return idbReq(objectStore.delete(key));
        });
    }

    function all(storeName) {
        return store(storeName, 'readonly').then(function (objectStore) {
            return idbReq(objectStore.getAll());
        });
    }

    function stripProduct(product) {
        if (!product || typeof product !== 'object') {
            return product;
        }
        delete product.cost;
        delete product.purchase_price;
        return product;
    }

    function rememberProducts(list) {
        var rows = list || [];
        return rows.reduce(function (chain, product) {
            return chain.then(function () {
                if (!product || product.id == null) {
                    return null;
                }
                return put('products', stripProduct(product));
            });
        }, Promise.resolve());
    }

    function rememberCustomers(list) {
        return (list || []).reduce(function (chain, customer) {
            return chain.then(function () {
                if (!customer || customer.id == null) {
                    return null;
                }
                var copy = {
                    id: customer.id,
                    name: customer.name,
                    phone: customer.phone || '',
                    is_walk_in: !!customer.is_walk_in,
                    credit_limit: customer.credit_limit || 0,
                    credit_used: customer.credit_used || 0,
                    points: customer.points || 0
                };
                return put('customers', copy);
            });
        }, Promise.resolve());
    }

    function saveSnapshot(data) {
        if (!data) {
            return Promise.resolve();
        }
        return rememberProducts(data.products).then(function () {
            return rememberCustomers(data.customers);
        }).then(function () {
            return put('meta', {
                key: 'snapshot',
                branch_id: data.branch_id,
                categories: data.categories || [],
                saved_at: data.saved_at || new Date().toISOString()
            });
        }).then(function () {
            hydrateCustomers(data.customers || []);
        }).catch(function () {});
    }

    function hydrateCustomers(list) {
        var select = document.getElementById('sx-pos-customer');
        if (!select) {
            return;
        }
        list.forEach(function (customer) {
            if (select.querySelector('option[value="' + customer.id + '"]')) {
                return;
            }
            var opt = document.createElement('option');
            opt.value = String(customer.id);
            opt.textContent = customer.name;
            opt.setAttribute('data-walk-in', customer.is_walk_in ? '1' : '0');
            opt.setAttribute('data-points', customer.points || 0);
            opt.setAttribute('data-credit-limit', customer.credit_limit || 0);
            opt.setAttribute('data-credit-used', customer.credit_used || 0);
            select.appendChild(opt);
        });
    }

    function pullSnapshot() {
        var url = meta('sx-offline-snapshot');
        if (!url || !navigator.onLine) {
            return Promise.resolve();
        }
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (res) {
            if (!res.ok) {
                return null;
            }
            return res.json();
        }).then(saveSnapshot).catch(function () {});
    }

    function requestData(settings) {
        var data = settings && settings.data ? settings.data : {};
        if (typeof data === 'string') {
            var params = new URLSearchParams(data);
            var obj = {};
            params.forEach(function (value, key) { obj[key] = value; });
            return obj;
        }
        if (window.jQuery && window.jQuery.isPlainObject && window.jQuery.isPlainObject(data)) {
            return data;
        }
        return data || {};
    }

    function cloneData(data) {
        if (typeof data === 'string') {
            return data;
        }
        return JSON.parse(JSON.stringify(data || {}));
    }

    function isServerId(value) {
        return /^[0-9]+$/.test(String(value || ''));
    }

    function deferred() {
        return window.jQuery.Deferred();
    }

    function asPromise(result) {
        var wait = deferred();
        Promise.resolve(result).then(function (value) {
            wait.resolve(value);
        }).catch(function (error) {
            if (error && error.responseJSON) {
                wait.reject(error);
                return;
            }
            wait.reject({ status: 0, responseJSON: { message: error && error.message ? error.message : 'Offline save failed.' } });
        });
        var promise = wait.promise();
        promise.abort = function () {};
        return promise;
    }

    function networkFailed(xhr) {
        if (!xhr || xhr.status === 0) {
            return true;
        }
        return xhr.status === 503 && xhr.responseJSON && xhr.responseJSON.message === 'Offline';
    }

    function kindFor(path, method) {
        if (method !== 'POST' && method !== 'PUT' && method !== 'DELETE') {
            return '';
        }
        if (/\/pos\/order$/.test(path)) return 'sale';
        if (/\/pos\/hold$/.test(path)) return 'hold';
        if (/\/pos\/customers$/.test(path)) return 'customer';
        if (/\/pos\/offline-local\/[^/]+\/cancel$/.test(path)) return 'cancel_local';
        if (/\/pos\/holds\/[^/]+\/cancel$/.test(path)) return 'cancel_hold';
        return '';
    }

    function readKind(path) {
        if (/\/pos\/catalog$/.test(path)) return 'catalog';
        if (/\/pos\/search$/.test(path)) return 'search';
        if (/\/pos\/holds$/.test(path)) return 'holds';
        if (/\/pos\/offline-local\/[^/]+$/.test(path)) return 'local_hold';
        if (/\/pos\/holds\/\d+$/.test(path)) return 'server_hold';
        return '';
    }

    function localKeyFrom(path) {
        var match = path.match(/\/pos\/offline-local\/([^/]+)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    function productFactor(product, item) {
        var units = (product && product.selling_units) || [];
        var found = null;
        units.forEach(function (unit) {
            if (found) return;
            if (item.product_unit_id && String(unit.id) === String(item.product_unit_id)) found = unit;
            if (item.sell_as && String(unit.key) === String(item.sell_as)) found = unit;
        });
        var factor = found ? parseFloat(found.factor) : 1;
        return factor > 0 ? factor : 1;
    }

    function adjustStock(items, direction) {
        return (items || []).reduce(function (chain, item) {
            return chain.then(function () {
                return get('products', Number(item.product_id) || item.product_id).then(function (product) {
                    if (!product) return null;
                    var delta = (parseFloat(item.quantity) || 0) * productFactor(product, item) * direction;
                    product.qty = Math.max(0, (parseFloat(product.qty) || 0) + delta);
                    return put('products', product);
                });
            });
        }, Promise.resolve());
    }

    function customerLabel() {
        var select = document.getElementById('sx-pos-customer');
        if (!select || select.selectedIndex < 0) return 'Customer';
        return select.options[select.selectedIndex].textContent || 'Customer';
    }

    function money(n) {
        return (Math.round((parseFloat(n) || 0) * 100) / 100).toFixed(2);
    }

    function previewFrom(body, number) {
        var items = (body && body.items) || [];
        return all('products').then(function (products) {
            var byId = {};
            (products || []).forEach(function (product) { byId[String(product.id)] = product; });
            var lines = items.map(function (item) {
                var product = byId[String(item.product_id)];
                var qty = parseFloat(item.quantity) || 0;
                var price = parseFloat(item.unit_price) || (product ? parseFloat(product.price) : 0) || 0;
                return {
                    name: product ? product.name : 'Item',
                    quantity: qty,
                    price: price,
                    total: Math.round(price * qty * 100) / 100
                };
            });
            var total = lines.reduce(function (sum, line) { return sum + line.total; }, 0);
            return {
                number: number,
                customer: customerLabel(),
                company: meta('sx-company-name') || 'Sellix POS',
                lines: lines,
                total: Math.round(total * 100) / 100,
                provisional: true
            };
        });
    }

    function holdLines(body) {
        return all('products').then(function (products) {
            var byId = {};
            (products || []).forEach(function (product) { byId[String(product.id)] = product; });
            return (body.items || []).map(function (item) {
                var product = byId[String(item.product_id)] || {};
                var factor = productFactor(product, item);
                return {
                    id: item.product_id,
                    name: product.name || 'Item',
                    unit_id: item.product_unit_id || null,
                    sell_as: item.sell_as || 'base',
                    unit_name: product.unit_name || '',
                    unit_factor: factor,
                    price: item.unit_price || product.price || 0,
                    wholesale_price: product.wholesale_price || 0,
                    promo_price: product.promo_price || 0,
                    tax_inclusive: !!product.tax_inclusive,
                    tax_rate: product.tax_rate || 0,
                    tax_amount: product.tax_amount || 0,
                    apply_tax: item.apply_tax,
                    manage_stock: !!product.manage_stock,
                    allow_negative_stock: !!product.allow_negative_stock,
                    qty: product.qty || 0,
                    qty_sold: item.quantity
                };
            });
        });
    }

    function syntheticHold(item) {
        return holdLines(item.body).then(function (lines) {
            var total = (item.preview && item.preview.total) || 0;
            return {
                offline: true,
                id: item.client_key,
                number: item.label,
                held_count: null,
                preview: item.preview,
                customer_id: item.body.customer_id,
                due_date: item.body.due_date || null,
                discount_amount: item.body.discount_amount || 0,
                discount_percent: item.body.discount_type === 'percent' ? item.body.discount_value : 0,
                items: lines,
                total: money(total)
            };
        });
    }

    function countPendingHolds() {
        return all('outbox').then(function (rows) {
            return (rows || []).filter(function (row) {
                return row.type === 'hold' && row.status !== 'synced';
            }).length;
        }).catch(function () { return 0; });
    }

    function enqueue(kind, settings, key) {
        var body = cloneData(requestData(settings));
        if (typeof body === 'object' && body) {
            body.offline_key = key;
        }
        if (kind === 'sale' && body && parseFloat(body.loyalty_points) > 0) {
            return Promise.reject({
                status: 422,
                responseJSON: { message: 'Loyalty points need a connection. Take payment without points, then sync.' }
            });
        }
        if (kind === 'sale' && body && body.hold_id && !isServerId(body.hold_id)) {
            return convertHoldToSale(String(body.hold_id), body, settings.url);
        }
        if (kind === 'cancel_local') {
            return cancelLocal(localKeyFrom(pathOf(settings.url)));
        }

        var item = {
            client_key: key,
            type: kind,
            url: settings.url,
            body: body,
            status: 'pending',
            error: '',
            attempts: 0,
            created_at: Date.now(),
            label: 'LOCAL-' + String(key).replace(/-/g, '').slice(0, 8).toUpperCase(),
            customer_name: customerLabel(),
            stock_adjusted: false
        };

        var ready = Promise.resolve(item);
        if (kind === 'customer' && typeof body === 'object') {
            item.customer_name = body.name || 'Customer';
            item.server_stand_in = 'local:' + key;
        }
        if (kind === 'hold' || kind === 'sale') {
            ready = previewFrom(body, item.label).then(function (preview) {
                item.preview = preview;
                item.total_text = money(preview.total);
                return item;
            });
        }
        return ready.then(function () {
            var stock = Promise.resolve();
            if (kind === 'sale') {
                item.stock_adjusted = true;
                stock = adjustStock(body.items, -1);
            }
            return stock.then(function () {
                return put('outbox', item);
            }).then(function () {
                return responseFor(item);
            });
        });
    }

    function convertHoldToSale(holdKey, body, url) {
        return get('outbox', holdKey).then(function (existing) {
            if (!existing || existing.status === 'synced') {
                return get('meta', 'server_ids').then(function (saved) {
                    var mapped = existing && existing.server_id
                        ? existing.server_id
                        : (saved && saved.map && saved.map[holdKey]);
                    if (mapped) body.hold_id = mapped;
                    else delete body.hold_id;
                    body.offline_key = clientKey();
                    return enqueue('sale', { url: url, data: body }, body.offline_key);
                });
            }
            delete body.hold_id;
            body.offline_key = existing.client_key;
            existing.type = 'sale';
            existing.url = url;
            existing.body = body;
            existing.status = 'pending';
            existing.error = '';
            existing.stock_adjusted = true;
            return previewFrom(body, existing.label).then(function (preview) {
                existing.preview = preview;
                existing.total_text = money(preview.total);
                return adjustStock(body.items, -1).then(function () {
                    return put('outbox', existing);
                }).then(function () {
                    return responseFor(existing);
                });
            });
        });
    }

    function cancelLocal(key) {
        return get('outbox', key).then(function (item) {
            if (!item) {
                return { offline: true, held_count: 0 };
            }
            if (item.status === 'synced' && item.server_id) {
                item.type = 'cancel_hold';
                item.url = prefix() + '/pos/holds/' + item.server_id + '/cancel';
                item.body = { offline_key: item.client_key };
                item.status = 'pending';
                return put('outbox', item).then(function () {
                    return { offline: true, held_count: 0 };
                });
            }
            var restore = item.stock_adjusted ? adjustStock(item.body && item.body.items, 1) : Promise.resolve();
            return restore.then(function () {
                return del('outbox', key);
            }).then(function () {
                return countPendingHolds();
            }).then(function (count) {
                return { offline: true, held_count: count };
            });
        });
    }

    function responseFor(item) {
        if (item.type === 'customer') {
            var data = typeof item.body === 'object' ? item.body : {};
            return {
                offline: true,
                id: 'local:' + item.client_key,
                name: data.name || item.customer_name,
                phone: data.phone || '',
                is_walk_in: false,
                credit_limit: 0,
                credit_used: 0,
                points: 0
            };
        }
        if (item.type === 'hold') {
            return countPendingHolds().then(function (count) {
                return {
                    offline: true,
                    id: item.client_key,
                    number: item.label,
                    print_url: '',
                    held_count: count,
                    preview: item.preview
                };
            });
        }
        return {
            offline: true,
            id: item.client_key,
            number: item.label,
            receipt_number: item.label,
            invoice_number: null,
            receipt_url: '',
            preview: item.preview
        };
    }

    function localHoldRows() {
        return all('outbox').then(function (rows) {
            return (rows || []).filter(function (row) {
                return row.type === 'hold' && row.status !== 'synced';
            }).map(function (row) {
                var base = prefix() + '/pos/offline-local/' + encodeURIComponent(row.client_key);
                return {
                    id: row.client_key,
                    number: row.label,
                    customer: row.customer_name || 'Customer',
                    total: row.total_text || '0.00',
                    held_at: 'On this device',
                    url: base,
                    print_url: base + '/print',
                    cancel_url: base + '/cancel',
                    offline: true
                };
            });
        }).catch(function () { return []; });
    }

    function serveRead(settings) {
        var path = pathOf(settings.url);
        var kind = readKind(path);
        var data = requestData(settings);
        if (kind === 'catalog') {
            return get('meta', 'snapshot').then(function (snap) {
                return all('products').then(function (products) {
                    var category = data.category_id || data.category || '';
                    var page = Math.max(1, parseInt(data.page, 10) || 1);
                    var rows = (products || []).filter(function (product) {
                        if (!category) return true;
                        return String(product.category_id) === String(category);
                    }).sort(function (a, b) {
                        return String(a.name).localeCompare(String(b.name));
                    });
                    var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
                    page = Math.min(page, pages);
                    return {
                        categories: (snap && snap.categories) || [],
                        products: rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE),
                        page: page,
                        pages: pages
                    };
                });
            });
        }
        if (kind === 'search') {
            var q = String(data.q || '').toLowerCase();
            return all('products').then(function (products) {
                return (products || []).filter(function (product) {
                    if (!q) return false;
                    var name = String(product.name || '').toLowerCase();
                    var sku = String(product.sku || '').toLowerCase();
                    var barcode = String(product.barcode || '').toLowerCase();
                    return name.indexOf(q) !== -1 || sku.indexOf(q) !== -1 || barcode === q;
                }).slice(0, 15);
            });
        }
        if (kind === 'holds') {
            return get('meta', 'server_holds').then(function (saved) {
                return localHoldRows().then(function (local) {
                    var server = (saved && saved.rows) || [];
                    return local.concat(server);
                });
            });
        }
        if (kind === 'local_hold') {
            return get('outbox', localKeyFrom(path)).then(function (item) {
                if (!item) {
                    throw new Error('That held sale is not on this device.');
                }
                return syntheticHold(item);
            });
        }
        if (kind === 'server_hold' && window.caches) {
            return window.caches.match(settings.url).then(function (cached) {
                if (!cached) {
                    throw new Error('That hold needs a connection.');
                }
                return cached.json();
            });
        }
        return Promise.reject(new Error('This screen needs a connection.'));
    }

    function rememberRead(settings, data) {
        var kind = readKind(pathOf(settings.url));
        if (kind === 'catalog' || kind === 'search') {
            var products = kind === 'catalog' ? (data && data.products) : data;
            rememberProducts(products || []);
            if (kind === 'catalog' && data && data.categories) {
                get('meta', 'snapshot').then(function (snap) {
                    snap = snap || { key: 'snapshot', categories: [] };
                    snap.categories = data.categories;
                    return put('meta', snap);
                }).catch(function () {});
            }
        }
        if (kind === 'holds' && Array.isArray(data)) {
            put('meta', { key: 'server_holds', rows: data.filter(function (row) { return !row.offline; }) }).catch(function () {});
        }
    }

    function stamp(settings) {
        var copy = window.jQuery.extend(true, {}, settings);
        var key = clientKey();
        var data = requestData(copy);
        if (typeof copy.data === 'string') {
            if (copy.data.indexOf('offline_key=') === -1) {
                copy.data += (copy.data ? '&' : '') + 'offline_key=' + encodeURIComponent(key);
            } else {
                var params = new URLSearchParams(copy.data);
                key = params.get('offline_key') || key;
            }
        } else {
            data = cloneData(data);
            if (!data.offline_key) {
                data.offline_key = key;
            } else {
                key = data.offline_key;
            }
            copy.data = data;
        }
        return { settings: copy, key: key };
    }

    function installAjax() {
        if (!window.jQuery || window.jQuery.ajax.__sxOffline) {
            return;
        }
        var original = window.jQuery.ajax;
        function wrapped(url, options) {
            var settings = url;
            if (typeof url === 'string') {
                settings = options || {};
                settings.url = url;
            }
            settings = settings || {};
            var method = String(settings.type || settings.method || 'GET').toUpperCase();
            var path = pathOf(settings.url);
            var read = readKind(path);
            var write = kindFor(path, method);

            if (read) {
                var readWait = deferred();
                var finishRead = function (data) {
                    rememberRead(settings, data);
                    readWait.resolve(data);
                };
                if (!navigator.onLine || read === 'local_hold') {
                    asPromise(serveRead(settings)).done(finishRead).fail(function (xhr) { readWait.reject(xhr); });
                } else {
                    original.call(window.jQuery, settings).done(finishRead).fail(function (xhr) {
                        if (networkFailed(xhr)) {
                            asPromise(serveRead(settings)).done(finishRead).fail(function () { readWait.reject(xhr); });
                            return;
                        }
                        readWait.reject(xhr);
                    });
                }
                var readPromise = readWait.promise();
                readPromise.abort = function () {};
                return readPromise;
            }

            if (!write) {
                return original.apply(window.jQuery, arguments);
            }

            var prepared = stamp(settings);
            var wait = deferred();
            var queued = function () {
                asPromise(enqueue(write, prepared.settings, prepared.key)).done(function (data) {
                    refreshUi();
                    wait.resolve(data);
                    if (navigator.onLine) syncNow();
                }).fail(function (xhr) {
                    wait.reject(xhr);
                });
            };
            if (!navigator.onLine || write === 'cancel_local') {
                queued();
                return wait.promise();
            }
            original.call(window.jQuery, prepared.settings).done(function (data) {
                wait.resolve(data);
            }).fail(function (xhr) {
                if (networkFailed(xhr)) {
                    queued();
                    return;
                }
                wait.reject(xhr);
            });
            var promise = wait.promise();
            promise.abort = function () {};
            return promise;
        }
        wrapped.__sxOffline = true;
        window.jQuery.ajax = wrapped;
    }

    function printPreview(preview) {
        if (!preview) return;
        var lines = (preview.lines || []).map(function (line) {
            return '<tr><td>' + escapeHtml(line.name) + '</td><td>' + money(line.quantity) + '</td><td>' + money(line.total) + '</td></tr>';
        }).join('');
        var html = '<!DOCTYPE html><html><head><title>' + escapeHtml(preview.number || 'Receipt') + '</title>'
            + '<style>body{font-family:Arial,sans-serif;width:280px;margin:12px auto;color:#111}h1{font-size:16px;text-align:center}p,td{font-size:12px}table{width:100%;border-collapse:collapse}td{padding:3px 0}.note{text-align:center;font-weight:700}</style></head><body>'
            + '<h1>' + escapeHtml(preview.company || 'Sellix POS') + '</h1>'
            + '<p class="note">PROVISIONAL — NOT YET SYNCED</p>'
            + '<p>No: ' + escapeHtml(preview.number || '') + '<br>Customer: ' + escapeHtml(preview.customer || '') + '</p>'
            + '<table>' + lines + '</table>'
            + '<p><strong>Total ' + money(preview.total) + '</strong></p>'
            + '<p class="note">This slip is stored on this device until the sale syncs.</p>'
            + '</body></html>';
        var win = window.open('', 'sx-offline-receipt', 'width=380,height=720');
        if (!win) return;
        win.document.open();
        win.document.write(html);
        win.document.close();
        win.focus();
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function refreshCsrf() {
        var url = meta('sx-offline-ping');
        if (!url) return Promise.resolve(csrf());
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (res) {
            if (res.status === 401 || res.status === 419 || res.redirected) {
                throw new Error('auth');
            }
            if (!res.ok) throw new Error('ping');
            return res.json();
        }).then(function (data) {
            if (data && data.csrf) {
                var el = document.querySelector('meta[name="csrf-token"]');
                if (el) el.setAttribute('content', data.csrf);
            }
            return csrf();
        });
    }

    function postOutbox(item, allowRefresh) {
        var headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf()
        };
        var init = { method: 'POST', credentials: 'same-origin', headers: headers };
        if (typeof item.body === 'string') {
            headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            init.body = item.body;
        } else {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(item.body || {});
        }
        return fetch(item.url, init).then(function (res) {
            if (res.status === 419 && allowRefresh) {
                return refreshCsrf().then(function () {
                    return postOutbox(item, false);
                });
            }
            return res.text().then(function (text) {
                var json = {};
                try { json = text ? JSON.parse(text) : {}; } catch (e) { json = {}; }
                return { status: res.status, json: json, redirected: res.redirected };
            });
        });
    }

    function rememberServerId(key, serverId) {
        return get('meta', 'server_ids').then(function (saved) {
            saved = saved || { key: 'server_ids', map: {} };
            saved.map = saved.map || {};
            saved.map[key] = serverId;
            return put('meta', saved);
        }).catch(function () {});
    }

    function adoptCustomer(key, serverId) {
        var localId = 'local:' + key;
        var select = document.getElementById('sx-pos-customer');
        if (select) {
            var opt = select.querySelector('option[value="' + localId + '"]');
            if (opt) opt.value = String(serverId);
        }
        return all('outbox').then(function (rows) {
            return (rows || []).reduce(function (chain, row) {
                return chain.then(function () {
                    if (!row.body || String(row.body.customer_id) !== localId) return null;
                    row.body.customer_id = serverId;
                    return put('outbox', row);
                });
            }, Promise.resolve());
        });
    }

    function linkCustomer(item) {
        var id = item.body && item.body.customer_id;
        if (!id || String(id).indexOf('local:') !== 0) {
            return Promise.resolve(item);
        }
        var key = String(id).slice(6);
        return get('outbox', key).then(function (customer) {
            if (!customer) {
                item.status = 'failed';
                item.error = 'The customer saved on this device is missing.';
                return put('outbox', item).then(function () { return null; });
            }
            if (!customer.server_id) {
                return syncOne(customer).then(function () {
                    return get('outbox', key);
                }).then(function (fresh) {
                    if (!fresh || !fresh.server_id) {
                        item.status = 'failed';
                        item.error = (fresh && fresh.error) || 'The customer has not synced yet.';
                        return put('outbox', item).then(function () { return null; });
                    }
                    item.body.customer_id = fresh.server_id;
                    return put('outbox', item).then(function () { return item; });
                });
            }
            item.body.customer_id = customer.server_id;
            return put('outbox', item).then(function () { return item; });
        });
    }

    function syncOne(item) {
        if (!item || item.status === 'failed') {
            return Promise.resolve(false);
        }
        var prepare = item.type === 'sale' || item.type === 'hold' ? linkCustomer(item) : Promise.resolve(item);
        return prepare.then(function (ready) {
            if (!ready) return false;
            ready.status = 'syncing';
            return put('outbox', ready).then(function () {
                return postOutbox(ready, true);
            }).then(function (result) {
                if (result.status === 401 || result.status === 403 || result.redirected) {
                    ready.status = 'failed';
                    ready.error = 'Sign in again on this till before these sales can sync.';
                    return put('outbox', ready).then(function () { return false; });
                }
                if (result.status === 404 && ready.type === 'cancel_hold') {
                    return del('outbox', ready.client_key).then(function () { return true; });
                }
                if (result.status >= 200 && result.status < 300) {
                    var serverId = result.json && result.json.id;
                    var mapped = serverId ? rememberServerId(ready.client_key, serverId) : Promise.resolve();
                    return mapped.then(function () {
                        if (ready.type === 'customer' && serverId) {
                            return adoptCustomer(ready.client_key, serverId);
                        }
                        return null;
                    }).then(function () {
                        return del('outbox', ready.client_key);
                    }).then(function () { return true; });
                }
                var message = (result.json && result.json.message) || 'The server did not accept this change.';
                ready.attempts = (ready.attempts || 0) + 1;
                if (result.status >= 500 && ready.attempts < 5) {
                    ready.status = 'pending';
                    ready.error = message;
                } else if (result.status === 0) {
                    ready.status = 'pending';
                    ready.error = 'No connection.';
                } else {
                    ready.status = 'failed';
                    ready.error = message;
                }
                return put('outbox', ready).then(function () { return false; });
            });
        }).catch(function () {
            item.status = 'pending';
            item.error = 'No connection.';
            item.attempts = (item.attempts || 0) + 1;
            return put('outbox', item).then(function () { return false; });
        });
    }

    function syncNow() {
        if (syncing || !navigator.onLine || userId() === 'guest') {
            refreshUi();
            return Promise.resolve();
        }
        syncing = true;
        setBanner('syncing', 'Syncing changes saved on this device...');
        return all('outbox').then(function (rows) {
            var items = (rows || []).filter(function (row) { return row.status !== 'failed'; });
            items.sort(function (a, b) {
                var rank = { customer: 0, hold: 1, sale: 2, cancel_hold: 3 };
                return (rank[a.type] || 9) - (rank[b.type] || 9) || (a.created_at - b.created_at);
            });
            return items.reduce(function (chain, item) {
                return chain.then(function (count) {
                    return syncOne(item).then(function (ok) { return count + (ok ? 1 : 0); });
                });
            }, Promise.resolve(0));
        }).then(function (count) {
            syncing = false;
            if (count > 0) {
                setBanner('ok', count === 1 ? '1 change synced.' : count + ' changes synced.');
                pullSnapshot();
                window.setTimeout(refreshUi, 4000);
            } else {
                refreshUi();
            }
        }).catch(function () {
            syncing = false;
            refreshUi();
        });
    }

    function ensureUi() {
        if (document.getElementById('sx-net')) return;
        var bar = document.createElement('div');
        bar.id = 'sx-net';
        bar.className = 'sx-net';
        bar.hidden = true;
        bar.innerHTML = '<span id="sx-net-text"></span><span><button type="button" id="sx-net-retry" hidden>Retry</button> <button type="button" id="sx-net-details">Details</button></span>';
        var panel = document.createElement('div');
        panel.id = 'sx-net-panel';
        panel.className = 'sx-net-panel';
        panel.hidden = true;
        document.body.appendChild(bar);
        document.body.appendChild(panel);
        document.getElementById('sx-net-details').addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) renderPanel();
        });
        document.getElementById('sx-net-retry').addEventListener('click', function () {
            retryFailed();
        });
    }

    function setBanner(mode, text) {
        ensureUi();
        var bar = document.getElementById('sx-net');
        bar.className = 'sx-net is-' + mode;
        bar.hidden = false;
        document.getElementById('sx-net-text').textContent = text;
        document.body.classList.add('sx-offline-on');
    }

    function hideBanner() {
        var bar = document.getElementById('sx-net');
        if (!bar) return;
        bar.hidden = true;
        document.body.classList.remove('sx-offline-on');
        var panel = document.getElementById('sx-net-panel');
        if (panel) panel.hidden = true;
    }

    function refreshUi() {
        ensureUi();
        all('outbox').then(function (rows) {
            rows = rows || [];
            var failed = rows.filter(function (row) { return row.status === 'failed'; });
            var pending = rows.filter(function (row) { return row.status !== 'failed'; });
            var retry = document.getElementById('sx-net-retry');
            if (retry) retry.hidden = failed.length === 0;
            if (!navigator.onLine) {
                var extra = pending.length ? ' ' + pending.length + ' waiting to sync.' : '';
                setBanner('offline', 'Working offline. Saved till sales stay on this device.' + extra);
            } else if (failed.length) {
                setBanner('failed', failed.length + ' change' + (failed.length === 1 ? '' : 's') + ' could not sync.');
            } else if (pending.length) {
                setBanner('syncing', pending.length + ' change' + (pending.length === 1 ? '' : 's') + ' waiting to sync.');
            } else if (!syncing) {
                hideBanner();
            }
            var panel = document.getElementById('sx-net-panel');
            if (panel && !panel.hidden) renderPanel();
        }).catch(function () {
            if (!navigator.onLine) {
                setBanner('offline', 'Working offline. Open a screen you have used on this device.');
            }
        });
    }

    function renderPanel() {
        var panel = document.getElementById('sx-net-panel');
        if (!panel) return;
        all('outbox').then(function (rows) {
            rows = rows || [];
            if (!rows.length) {
                panel.innerHTML = '<h3>Sync</h3><p>Nothing is waiting.</p>';
                return;
            }
            var html = '<h3>On this device</h3>';
            rows.forEach(function (row) {
                html += '<div class="sx-net-row"><div><strong>' + escapeHtml(row.label || row.type) + '</strong><br>'
                    + escapeHtml(row.error || row.status) + '</div><div>';
                if (row.status === 'failed') {
                    html += '<button type="button" data-sx-retry="' + escapeHtml(row.client_key) + '">Retry</button>';
                }
                html += '<button type="button" data-sx-drop="' + escapeHtml(row.client_key) + '">Discard</button></div></div>';
            });
            panel.innerHTML = html;
        }).catch(function () {
            panel.innerHTML = '<h3>Sync</h3><p>Offline storage is not available in this browser.</p>';
        });
    }

    function retryFailed() {
        all('outbox').then(function (rows) {
            return (rows || []).filter(function (row) { return row.status === 'failed'; }).reduce(function (chain, row) {
                return chain.then(function () {
                    row.status = 'pending';
                    row.attempts = 0;
                    row.error = '';
                    return put('outbox', row);
                });
            }, Promise.resolve());
        }).then(syncNow).catch(function () {});
    }

    function discard(key) {
        get('outbox', key).then(function (item) {
            if (!item) return null;
            var restore = item.stock_adjusted ? adjustStock(item.body && item.body.items, 1) : Promise.resolve();
            return restore.then(function () { return del('outbox', key); });
        }).then(refreshUi).catch(function () {});
    }

    document.addEventListener('click', function (event) {
        var retry = event.target.closest('[data-sx-retry]');
        if (retry) {
            var key = retry.getAttribute('data-sx-retry');
            get('outbox', key).then(function (item) {
                if (!item) return null;
                item.status = 'pending';
                item.attempts = 0;
                item.error = '';
                return put('outbox', item);
            }).then(syncNow);
            return;
        }
        var drop = event.target.closest('[data-sx-drop]');
        if (drop) {
            discard(drop.getAttribute('data-sx-drop'));
            return;
        }
        var print = event.target.closest('a.sx-print-hold');
        if (print) {
            var href = print.getAttribute('href') || '';
            if (href.indexOf('/pos/offline-local/') === -1) return;
            event.preventDefault();
            var printKey = localKeyFrom(pathOf(href));
            get('outbox', printKey).then(function (item) {
                if (item && item.preview) printPreview(item.preview);
            }).catch(function () {});
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute) return;
        var action = form.getAttribute('action') || '';
        var method = String(form.getAttribute('method') || 'get').toLowerCase();
        if (action.indexOf('logout') !== -1) {
            if (!navigator.onLine) {
                event.preventDefault();
                setBanner('offline', 'Sign out needs a connection. Sales saved here stay on this device.');
                return;
            }
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_PAGES' });
            }
            return;
        }
        if (navigator.onLine || method === 'get') return;
        event.preventDefault();
        event.stopPropagation();
        setBanner('offline', 'That action needs a connection. The till can still sell from items already saved on this device.');
    }, true);

    window.addEventListener('online', function () {
        syncNow();
    });
    window.addEventListener('offline', refreshUi);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && navigator.onLine) syncNow();
    });

    installAjax();
    ensureUi();
    refreshUi();
    pullSnapshot();
    if (navigator.onLine) {
        window.setTimeout(syncNow, 1500);
    }
    window.setInterval(function () {
        if (navigator.onLine) {
            pullSnapshot();
            syncNow();
        } else {
            refreshUi();
        }
    }, 60000);

    window.SellixOffline = {
        printPreview: printPreview,
        retry: retryFailed,
        sync: syncNow
    };
})();
