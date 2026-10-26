(function () {
    var cfg = window.SellixDemo;
    if (!cfg || !cfg.demo) {
        return;
    }

    var token = cfg.csrf;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        token = meta.getAttribute('content') || token;
    }

    var backdrop = document.getElementById('sx-tour-backdrop');
    var welcome = document.getElementById('sx-tour-welcome');
    var panel = document.getElementById('sx-tour-panel');
    var launcher = document.getElementById('sx-tour-launcher');

    function post(body) {
        return fetch(cfg.actUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    var message = (data.errors && data.errors.tour && data.errors.tour[0]) || data.message || 'The tour could not move on yet.';
                    var error = new Error(message);
                    error.payload = data;
                    throw error;
                }
                return data;
            });
        });
    }

    function clearHighlight() {
        document.querySelectorAll('.sx-tour-target').forEach(function (node) {
            node.classList.remove('sx-tour-target');
        });
    }

    function placeHighlight(selector) {
        clearHighlight();
        if (!selector) {
            return false;
        }
        var node = document.querySelector(selector);
        if (!node) {
            return false;
        }
        node.classList.add('sx-tour-target');
        if (node.scrollIntoView) {
            node.scrollIntoView({ block: 'center', inline: 'nearest' });
        }
        return true;
    }

    function setText(id, value) {
        var node = document.getElementById(id);
        if (node) {
            node.textContent = value || '';
        }
    }

    function render(data) {
        cfg.state = data;
        var finished = !!data.finished;
        var guided = data.mode === 'guided' && !data.paused && !finished;
        if (welcome) {
            welcome.hidden = !!data.mode;
        }
        if (backdrop) {
            backdrop.hidden = !!data.mode;
        }
        launcher.hidden = !data.mode;
        if (finished) {
            launcher.textContent = 'Restart guided tour';
            launcher.setAttribute('data-demo-action', 'restart');
        } else if (data.paused || data.mode === 'explore') {
            launcher.textContent = data.mode === 'explore' && !(data.step && data.step.index > 1) ? 'Start guided tour' : 'Resume tour';
            launcher.setAttribute('data-demo-action', 'resume');
        } else {
            launcher.hidden = true;
        }

        if (!guided && !finished) {
            panel.hidden = true;
            clearHighlight();
            return;
        }

        panel.hidden = false;
        var step = data.step;
        if (finished || !step) {
            setText('sx-tour-kicker', 'Tour complete');
            setText('sx-tour-title', "You're Ready to Explore!");
            setText('sx-tour-body', "Congratulations! You've completed the guided tour. You've learned how to set up products, manage stock, record purchases, process sales and review your business reports. You can continue exploring the demo or restart the tour.");
            document.getElementById('sx-tour-links').innerHTML = '';
            document.getElementById('sx-tour-missing').hidden = true;
            document.getElementById('sx-tour-open').hidden = true;
            document.getElementById('sx-tour-standard').hidden = true;
            document.getElementById('sx-tour-done').hidden = false;
            var contact = document.getElementById('sx-tour-contact');
            if (data.contact && (data.contact.email || data.contact.website)) {
                contact.hidden = false;
                contact.textContent = 'Contact sales';
                contact.href = data.contact.email ? ('mailto:' + data.contact.email) : data.contact.website;
            } else {
                contact.hidden = true;
            }
            clearHighlight();
            return;
        }

        document.getElementById('sx-tour-standard').hidden = false;
        document.getElementById('sx-tour-done').hidden = true;
        setText('sx-tour-kicker', 'Step ' + step.index + ' of ' + step.total);
        setText('sx-tour-title', step.title);
        setText('sx-tour-body', step.body);
        var links = document.getElementById('sx-tour-links');
        links.innerHTML = '';
        (step.links || []).forEach(function (link) {
            var anchor = document.createElement('a');
            anchor.href = link.url;
            anchor.textContent = link.label;
            links.appendChild(anchor);
        });
        var found = placeHighlight(step.anchor);
        var here = window.location.pathname === new URL(step.url, window.location.origin).pathname;
        document.getElementById('sx-tour-missing').hidden = found;
        var open = document.getElementById('sx-tour-open');
        open.hidden = here;
        open.href = step.url;
        setText('sx-tour-error', '');
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-demo-action]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var action = trigger.getAttribute('data-demo-action');
        var body = { action: action, route: cfg.page || null };
        if (trigger.getAttribute('data-mode')) {
            body.mode = trigger.getAttribute('data-mode');
        }
        post(body).then(render).catch(function (error) {
            setText('sx-tour-error', error.message);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && cfg.state && cfg.state.mode === 'guided' && !cfg.state.paused) {
            post({ action: 'exit', route: cfg.page || null }).then(render);
        }
    });

    render(cfg.state);
    if (cfg.page && cfg.state && cfg.state.mode === 'guided' && !cfg.state.paused && !cfg.state.finished) {
        post({ action: 'seen', route: cfg.page }).then(render).catch(function () {});
    }
})();
