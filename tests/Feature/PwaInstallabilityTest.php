<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaInstallabilityTest extends TestCase
{
    public function test_manifest_is_public_and_meets_install_requirements(): void
    {
        $response = $this->get(route('pwa.manifest'));

        $response->assertOk();
        $response->assertJsonPath('display', 'standalone');
        $response->assertJsonPath('orientation', 'portrait');
        $this->assertNotEmpty($response->json('name'));
        $this->assertNotEmpty($response->json('start_url'));
        $this->assertNotEmpty($response->json('scope'));

        $icons = collect($response->json('icons'));
        $this->assertTrue($icons->contains(function ($icon) {
            return ($icon['sizes'] ?? '') === '192x192';
        }));
        $this->assertTrue($icons->contains(function ($icon) {
            return ($icon['sizes'] ?? '') === '512x512';
        }));
    }

    public function test_service_worker_is_served_with_scope_header(): void
    {
        $this->get(route('pwa.sw'))
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed')
            ->assertSee('sellix-app-cache-v4', false)
            ->assertSee('SKIP_WAITING', false)
            ->assertDontSee('password', false);
    }

    public function test_pwa_version_endpoint_is_public(): void
    {
        $this->get(route('pwa.version'))
            ->assertOk()
            ->assertJsonStructure(['version']);
    }

    public function test_login_page_links_manifest_and_icons(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee('There are new updates', false)
            ->assertSee('Update now', false);
    }

    public function test_pwa_icons_exist(): void
    {
        $this->assertFileExists(public_path('img/pwa/icon-192.png'));
        $this->assertFileExists(public_path('img/pwa/icon-512.png'));
        $this->assertFileExists(public_path('img/pwa/apple-touch-icon.png'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertFileExists(public_path('sw.js'));
    }
}
