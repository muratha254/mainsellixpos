(function () {
    var DISMISS_KEY = 'sx-pwa-dismissed';
    var VERSION_KEY = 'sx-pwa-app-version';
    var LATER_KEY = 'sx-pwa-update-later';
    var script = document.currentScript;
    var swUrl = script && script.getAttribute('data-sw-url');
    var versionUrl = script && script.getAttribute('data-version-url');
    var deferredPrompt = null;
    var waitingWorker = null;
    var latestVersion = null;
    var reloading = false;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: minimal-ui)').matches
            || window.navigator.standalone === true;
    }

    function isIos() {
        var ua = window.navigator.userAgent || '';
        var iOS = /iPad|iPhone|iPod/.test(ua);
        var iPadOs = window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1;
        return iOS || iPadOs;
    }

    function wasDismissed() {
        return window.localStorage.getItem(DISMISS_KEY) === '1';
    }

    function dismiss() {
        window.localStorage.setItem(DISMISS_KEY, '1');
        hideInstall();
        var hint = document.getElementById('sx-pwa-ios-hint');
        if (hint) {
            hint.hidden = true;
        }
    }

    function hideInstall() {
        document.querySelectorAll('[data-pwa-install]').forEach(function (el) {
            el.hidden = true;
        });
    }

    function showInstall() {
        if (isStandalone() || wasDismissed()) {
            return;
        }
        document.querySelectorAll('[data-pwa-install]').forEach(function (el) {
            el.hidden = false;
        });
    }

    function updateBanner() {
        return document.getElementById('sx-pwa-update');
    }

    function showUpdate() {
        var snoozed = null;
        try { snoozed = sessionStorage.getItem(LATER_KEY); } catch (e) {}
        if (snoozed && (!latestVersion || snoozed === latestVersion || snoozed === '1')) {
            return;
        }
        var banner = updateBanner();
        if (banner) {
            banner.hidden = false;
        }
    }

    function hideUpdate() {
        var banner = updateBanner();
        if (banner) {
            banner.hidden = true;
        }
    }

    function applyUpdate() {
        hideUpdate();
        if (latestVersion) {
            try { localStorage.setItem(VERSION_KEY, latestVersion); } catch (e) {}
        }
        try { sessionStorage.removeItem(LATER_KEY); } catch (e) {}
        var done = function () {
            if (reloading) {
                return;
            }
            reloading = true;
            window.location.reload();
        };
        if (waitingWorker) {
            waitingWorker.postMessage({ type: 'SKIP_WAITING' });
            setTimeout(done, 400);
            return;
        }
        if ('caches' in window) {
            caches.keys().then(function (keys) {
                return Promise.all(keys.map(function (key) { return caches.delete(key); }));
            }).then(done).catch(done);
            return;
        }
        done();
    }

    function snoozeUpdate() {
        try { sessionStorage.setItem(LATER_KEY, latestVersion || '1'); } catch (e) {}
        hideUpdate();
    }

    function noteWaiting(worker) {
        if (!worker) {
            return;
        }
        waitingWorker = worker;
        showUpdate();
    }

    function checkAppVersion() {
        if (!versionUrl) {
            return;
        }
        fetch(versionUrl, { cache: 'no-store', credentials: 'same-origin' }).then(function (res) {
            if (!res.ok) {
                return null;
            }
            return res.json();
        }).then(function (data) {
            if (!data || !data.version) {
                return;
            }
            latestVersion = String(data.version);
            var known = null;
            try { known = localStorage.getItem(VERSION_KEY); } catch (e) {}
            if (!known) {
                try { localStorage.setItem(VERSION_KEY, latestVersion); } catch (e) {}
                return;
            }
            if (known !== latestVersion) {
                showUpdate();
            }
        }).catch(function () {});
    }

    function registerWorker() {
        if (!('serviceWorker' in navigator) || !swUrl) {
            return;
        }
        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            return;
        }
        navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' }).then(function (reg) {
            if (reg.waiting && navigator.serviceWorker.controller) {
                noteWaiting(reg.waiting);
            }
            reg.addEventListener('updatefound', function () {
                var worker = reg.installing;
                if (!worker) {
                    return;
                }
                worker.addEventListener('statechange', function () {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        noteWaiting(worker);
                    }
                });
            });
            if (typeof reg.update === 'function') {
                reg.update();
            }
            setInterval(function () {
                if (typeof reg.update === 'function') {
                    reg.update();
                }
                checkAppVersion();
            }, 15 * 60 * 1000);
        }).catch(function () {
            // Keep the existing app usable if the worker cannot register (HTTP LAN, etc).
        });
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-pwa-install]');
        if (btn) {
            event.preventDefault();
            if (!deferredPrompt) {
                return;
            }
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                deferredPrompt = null;
                if (choice.outcome === 'accepted') {
                    hideInstall();
                } else {
                    dismiss();
                }
            });
            return;
        }
        if (event.target.closest('[data-pwa-update]')) {
            event.preventDefault();
            applyUpdate();
            return;
        }
        if (event.target.closest('[data-pwa-update-later]')) {
            event.preventDefault();
            snoozeUpdate();
        }
    });

    var iosClose = document.getElementById('sx-pwa-ios-close');
    if (iosClose) {
        iosClose.addEventListener('click', dismiss);
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        if (isStandalone() || wasDismissed()) {
            return;
        }
        deferredPrompt = event;
        showInstall();
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        hideInstall();
        dismiss();
    });

    navigator.serviceWorker && navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (reloading) {
            return;
        }
        reloading = true;
        window.location.reload();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            checkAppVersion();
            if (navigator.serviceWorker && navigator.serviceWorker.getRegistration) {
                navigator.serviceWorker.getRegistration().then(function (reg) {
                    if (reg && typeof reg.update === 'function') {
                        reg.update();
                    }
                });
            }
        }
    });

    window.addEventListener('pageshow', function () {
        checkAppVersion();
    });

    if (isStandalone()) {
        hideInstall();
    } else if (isIos() && !wasDismissed()) {
        var hint = document.getElementById('sx-pwa-ios-hint');
        if (hint) {
            hint.hidden = false;
        }
    }

    checkAppVersion();
    registerWorker();
})();
