<div class="sx-pwa-ios-hint" id="sx-pwa-ios-hint" hidden>
    <button type="button" class="sx-pwa-ios-close" id="sx-pwa-ios-close" aria-label="Dismiss">&times;</button>
    <strong>Install Sellix POS</strong>
    <span>Tap Share → Add to Home Screen</span>
</div>
<div class="sx-pwa-update" id="sx-pwa-update" hidden>
    <div class="sx-pwa-update-copy">
        <strong>There are new updates</strong>
        <span>Update now to use the latest version of the app.</span>
    </div>
    <div class="sx-pwa-update-actions">
        <button type="button" class="sx-pwa-update-btn" data-pwa-update>Update now</button>
        <button type="button" class="sx-pwa-update-later" data-pwa-update-later>Later</button>
    </div>
</div>
<script src="{{ asset('js/sellix-offline.js') }}?v=1"></script>
<script src="{{ asset('js/pwa.js') }}?v=2" data-sw-url="{{ asset('sw.js') }}" data-version-url="{{ route('pwa.version') }}"></script>
