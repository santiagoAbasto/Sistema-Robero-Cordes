<?php

namespace Tests\Feature;

use App\Models\Contacto;
use App\Models\Empresa;
use App\Models\TipoMedio;
use App\Models\User;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Los errores de validación se leen en castellano, y dicen de qué campo hablan.
 *
 * Roberto (04-11-2026) agregó un contacto y el cartel decía "validation.max.string":
 * el sistema no tenía carpeta de traducciones y cada error llegaba como la
 * clave sin traducir. Además el cargo de esa persona —"Gestión de Solicitudes de
 * Compras - Depto. Asistencia de Producción..."— pasaba los 60 caracteres que
 * admitía el campo. (Nombres ficticios: el repo es público.)
 */
class MensajesEnCastellanoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
        $this->empresa = Empresa::create(['nombre' => 'Empresa Demo SA']);
    }

    private function alta(array $datos)
    {
        return $this->postJson("/api/empresas/{$this->empresa->id}/contactos", $datos);
    }

    private function mensaje($respuesta): string
    {
        return implode(' ', array_merge(...array_values($respuesta->json('errors') ?? [[]])));
    }

    public function test_un_cargo_largo_de_una_firma_real_se_guarda(): void
    {
        $cargo = 'Gestión de Solicitudes de Compras - Depto. Asistencia de Producción Gerencia Corporativa Planta A-B';

        $this->assertGreaterThan(60, mb_strlen($cargo), 'es justo el caso que antes no entraba');

        $this->alta(['nombre' => 'Contacto Demo', 'cargo' => $cargo])->assertOk();

        $this->assertSame($cargo, Contacto::where('nombre', 'Contacto Demo')->value('cargo'));
    }

    public function test_el_sector_largo_tambien_se_guarda(): void
    {
        $sector = str_repeat('Compras y abastecimiento ', 5); // 125 caracteres

        $this->alta(['nombre' => 'Contacto Demo', 'sector' => trim($sector)])->assertOk();
    }

    public function test_pasado_el_tope_el_error_esta_en_castellano_y_dice_el_campo(): void
    {
        $r = $this->alta(['nombre' => 'Contacto Demo', 'cargo' => str_repeat('a', 151)])->assertStatus(422);

        $texto = $this->mensaje($r);

        $this->assertStringContainsString('El cargo no puede tener más de 150 caracteres', $texto);
        $this->assertStringNotContainsString('validation.', $texto, 'nunca la clave sin traducir');
    }

    public function test_falta_el_nombre(): void
    {
        $r = $this->alta(['cargo' => 'Compras'])->assertStatus(422);

        $this->assertStringContainsString('Falta completar el nombre', $this->mensaje($r));
    }

    public function test_un_dato_de_una_lista_dice_cual_es(): void
    {
        $r = $this->alta([
            'nombre' => 'Contacto Demo',
            'medios' => [
                ['tipo_medio_id' => TipoMedio::where('nombre', 'Mail')->value('id'), 'valor' => 'a@example.com'],
                ['tipo_medio_id' => TipoMedio::where('nombre', 'Mail')->value('id'), 'valor' => str_repeat('x', 130)],
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('dato de contacto 2 no puede tener más de 120 caracteres', $this->mensaje($r));
        $this->assertStringNotContainsString('validation.', $this->mensaje($r));
    }

    public function test_el_sistema_habla_castellano_aunque_el_env_diga_otra_cosa(): void
    {
        // Es lo que pasaba con APP_LOCALE=en: la carpeta lang/ sólo tiene "es".
        $this->assertSame('es', app()->getLocale());
        $this->assertSame('es', app()->getFallbackLocale());
        $this->assertSame('Falta completar el nombre.', __('validation.required', ['attribute' => 'el nombre']));
    }

    public function test_ninguna_clave_de_validacion_queda_sin_traducir(): void
    {
        // Cada regla que usa el sistema tiene su frase: si falta una, __() devuelve la clave.
        foreach (['required', 'string', 'max.string', 'max.numeric', 'max.array', 'max.file', 'min.string', 'min.numeric',
            'email', 'date', 'integer', 'numeric', 'boolean', 'array', 'in', 'exists', 'unique', 'mimes', 'uploaded',
            'required_without', 'required_if', 'confirmed', 'regex', 'after_or_equal', 'before_or_equal', 'distinct'] as $clave) {
            $this->assertNotSame("validation.{$clave}", __("validation.{$clave}"), "falta traducir validation.{$clave}");
        }
    }
}
