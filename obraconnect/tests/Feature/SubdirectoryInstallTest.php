<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Instalação numa subpasta (ex.: www.coda.pt/obras/obraconnect). O resto do
 * comportamento em subpasta foi verificado num Apache real (ver docs/07-guia-cpanel.md).
 */
class SubdirectoryInstallTest extends TestCase
{
    private function inSubdirectory(string $path): array
    {
        return [
            'SCRIPT_NAME' => '/obras/obraconnect/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
            'PHP_SELF' => '/obras/obraconnect/index.php',
            'REQUEST_URI' => '/obras/obraconnect'.$path,
        ];
    }

    public function test_spa_receives_the_base_path(): void
    {
        $this->call('GET', '/obras/obraconnect/entrar', server: $this->inSubdirectory('/entrar'))
            ->assertOk()
            ->assertSee('<meta name="app-base" content="/obras/obraconnect">', false)
            ->assertSee('/obras/obraconnect/manifest.webmanifest', false);
    }

    public function test_spa_at_domain_root_has_empty_base_path(): void
    {
        $this->get('/entrar')->assertOk()->assertSee('<meta name="app-base" content="">', false);
    }

    public function test_guest_redirect_keeps_base_path_and_relative_target(): void
    {
        $response = $this->call('GET', '/obras/obraconnect/auth/email/verify/1/abc', server: $this->inSubdirectory('/auth/email/verify/1/abc'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/obras/obraconnect/entrar?redirect=', $location);
        // O destino é relativo à aplicação: a SPA acrescenta o caminho base.
        $this->assertStringContainsString('redirect='.urlencode('/auth/email/verify/1/abc'), $location);
    }
}
