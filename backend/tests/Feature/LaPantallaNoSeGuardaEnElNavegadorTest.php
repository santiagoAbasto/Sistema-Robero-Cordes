<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Despues de instalar una version, la pantalla nueva tiene que llegar sola.
 *
 * Sin esto el navegador reusaba el index.html viejo durante horas (06-11-2026): la tarjeta de borradores
 * ya contaba lo nuevo pero su enlace seguia llevando a lo viejo, y parecia que el arreglo no andaba.
 */
class LaPantallaNoSeGuardaEnElNavegadorTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carpeta = sys_get_temp_dir().'/cordes-public-'.uniqid();
        mkdir($this->carpeta);
        file_put_contents($this->carpeta.'/index.html', '<!doctype html><div id="root"></div>');
        $this->app->usePublicPath($this->carpeta);
    }

    protected function tearDown(): void
    {
        @unlink($this->carpeta.'/index.html');
        @rmdir($this->carpeta);

        parent::tearDown();
    }

    public function test_la_pantalla_se_pide_de_nuevo_cada_vez(): void
    {
        foreach (['/', '/dashboard', '/empresas/registros'] as $ruta) {
            $r = $this->get($ruta);

            $r->assertOk();
            $this->assertStringContainsString('id="root"', $r->baseResponse->getFile()->getContent());
            $this->assertStringContainsString('no-cache', $r->headers->get('Cache-Control'), "La pantalla de {$ruta} se guarda en el navegador.");
            $this->assertStringNotContainsString('public', $r->headers->get('Cache-Control'), "La pantalla de {$ruta} sale como publica.");
        }
    }
}
