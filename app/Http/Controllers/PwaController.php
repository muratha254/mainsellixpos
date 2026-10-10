<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class PwaController extends Controller
{
    public function manifest(): Response
    {
        $name = fleet_system_name();
        $short = fleet_system_short_name();
        if (mb_strlen($short) > 12) {
            $short = 'Sellix';
        }

        $scope = rtrim(url('/'), '/') . '/';

        $payload = [
            'id' => $scope,
            'name' => $name,
            'short_name' => $short,
            'description' => $name . ' point of sale',
            'start_url' => $scope,
            'scope' => $scope,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#1c252b',
            'theme_color' => '#c9a027',
            'lang' => 'en',
            'icons' => [
                [
                    'src' => asset('img/pwa/icon-192.png') . '?v=2',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('img/pwa/icon-512.png') . '?v=2',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => asset('img/pwa/icon-512.png') . '?v=2',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return response(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function serviceWorker(): Response
    {
        $path = public_path('sw.js');
        abort_unless(is_file($path), 404);

        $stamp = $this->appVersionStamp();
        $body = str_replace('__APP_VERSION__', $stamp, file_get_contents($path));
        $body .= "\n// build: " . $stamp . "\n";

        return response($body, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => $this->serviceWorkerScope(),
        ]);
    }

    public function version()
    {
        return response()->json([
            'version' => $this->appVersionStamp(),
        ], 200, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function appVersionStamp(): string
    {
        $files = array_merge(
            [
                public_path('sw.js'),
                public_path('js/pwa.js'),
                public_path('css/pwa.css'),
                public_path('css/sellix-app.css'),
                public_path('css/fleet-theme.css'),
            ],
            glob(app_path('Http/Controllers/*.php')) ?: [],
            glob(resource_path('views/layouts/*.blade.php')) ?: []
        );
        $times = [];
        foreach ($files as $file) {
            if (is_file($file)) {
                $times[] = filemtime($file);
            }
        }

        return (string) (max($times) ?: time());
    }

    private function serviceWorkerScope(): string
    {
        $path = parse_url(url('/'), PHP_URL_PATH);

        if (! $path || $path === '/') {
            return '/';
        }

        return rtrim($path, '/') . '/';
    }
}
