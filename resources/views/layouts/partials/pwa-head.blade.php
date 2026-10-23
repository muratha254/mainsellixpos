<meta name="theme-color" content="#c9a027">
@auth
<meta name="sx-user-id" content="{{ auth()->id() }}">
<meta name="sx-offline-ping" content="{{ route('offline.ping') }}">
@endauth
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ fleet_system_short_name() }}">
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon.png') }}?v=2">
<link rel="apple-touch-icon" href="{{ asset('img/pwa/apple-touch-icon.png') }}?v=2">
<link rel="stylesheet" href="{{ asset('css/pwa.css') }}?v=2">
