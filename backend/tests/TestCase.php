<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba sale a internet: lo que no esta simulado con
        // Http::fake falla en vez de llamar a OpenAI o a Google de verdad.
        Http::preventStrayRequests();
    }
}
