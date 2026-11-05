<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El buscador del indice ordena por RELEVANCIA, no solo alfabetico.
 *
 * Buscar una empresa por su nombre costaba horrores: el buscador matchea
 * tambien el material de las lineas de cotizacion, y al ordenar alfabetico
 * cualquier cotizacion con ese texto quedaba antes que la empresa cuyo nombre
 * EMPIEZA con lo buscado, a varias paginas de distancia. (Nombres ficticios: el
 * repo es publico.)
 */
class BuscadorDeEmpresasTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_empresa_cuyo_nombre_coincide_va_primero(): void
    {
        $usuario = User::factory()->create(['role' => 'Administrador']);
        Sanctum::actingAs($usuario);

        // Alfabeticamente primera, pero solo matchea por una linea de cotizacion.
        $acme = Empresa::create(['nombre' => 'ACME METALURGICA']);
        $consulta = Consulta::create([
            'empresa_id' => $acme->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2026-11-01',
            'estado' => 'Confirmada',
            'usuario_id' => $usuario->id,
        ]);
        ConsultaLinea::create([
            'consulta_id' => $consulta->id,
            'orden' => 1,
            'descripcion' => 'BARRA ZORZAL 20MM',
        ]);

        // Matchea por el nombre, pero en el medio (contiene "zor").
        Empresa::create(['nombre' => 'BAZOR SRL']);

        // Matchea por el nombre y lo EMPIEZA: tiene que ir primera.
        Empresa::create(['nombre' => 'ZORBA FUNDICIONES']);

        $datos = $this->getJson('/api/empresas?buscar=zor')
            ->assertOk()
            ->json('data');

        $nombres = array_column($datos, 'nombre');

        // Las tres matchean; el orden por relevancia: empieza con > contiene > por una linea.
        $this->assertSame('ZORBA FUNDICIONES', $nombres[0], 'la que empieza con lo buscado tiene que ir primera');
        $this->assertContains('ACME METALURGICA', $nombres, 'la que matchea por la cotizacion igual aparece');
        $this->assertGreaterThan(
            array_search('BAZOR SRL', $nombres, true),
            array_search('ACME METALURGICA', $nombres, true),
            'la que sólo matchea por una línea de cotización va después de la que coincide por nombre',
        );
    }

    /**
     * Las empresas que cerraron se ven igual (con su historia), pero SIEMPRE al
     * final: aunque les toque antes por el abecedario o coincidan mejor.
     */
    public function test_las_inactivas_van_al_final_aunque_toquen_antes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        Empresa::create(['nombre' => 'AAA CERRADA SA', 'activa' => false]);
        Empresa::create(['nombre' => 'MMM ACTIVA SA']);
        Empresa::create(['nombre' => 'ZZZ ACTIVA SA']);

        $nombres = array_column($this->getJson('/api/empresas?con_inactivas=1')->assertOk()->json('data'), 'nombre');

        $this->assertSame(['MMM ACTIVA SA', 'ZZZ ACTIVA SA', 'AAA CERRADA SA'], $nombres);
    }

    public function test_con_texto_la_inactiva_que_coincide_mejor_igual_va_al_final(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        // La cerrada EMPIEZA con lo buscado (mejor coincidencia); la activa solo lo contiene.
        Empresa::create(['nombre' => 'ZORBA CERRADA', 'activa' => false]);
        Empresa::create(['nombre' => 'BAZOR ACTIVA']);

        $datos = $this->getJson('/api/empresas?buscar=zor&con_inactivas=1')->assertOk()->json('data');

        $this->assertSame(['BAZOR ACTIVA', 'ZORBA CERRADA'], array_column($datos, 'nombre'));
        $this->assertFalse($datos[1]['activa']);
    }

    public function test_sin_pedirlas_las_inactivas_siguen_sin_aparecer(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        Empresa::create(['nombre' => 'AAA CERRADA SA', 'activa' => false]);
        Empresa::create(['nombre' => 'BBB ACTIVA SA']);

        $nombres = array_column($this->getJson('/api/empresas')->assertOk()->json('data'), 'nombre');

        $this->assertSame(['BBB ACTIVA SA'], $nombres);
    }

    /**
     * Las últimas empresas que se abrieron (⌘K y menú del Índice) se consultan por número para ver cómo están HOY.
     *
     * Una empresa se puede dar de baja después de haberla abierto: lo que se guardó al visitarla ya no vale. Con las
     * inactivas pedidas, vuelven marcadas; las que no se pidieron por número no vienen.
     */
    public function test_se_puede_pedir_un_grupo_de_empresas_por_numero_con_su_estado_de_hoy(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));

        $activa = Empresa::create(['nombre' => 'ALFA SA']);
        $cerrada = Empresa::create(['nombre' => 'BETA CERRADA SA', 'activa' => false]);
        $otra = Empresa::create(['nombre' => 'GAMMA SA']);

        $datos = $this->getJson("/api/empresas?con_inactivas=1&ids={$activa->id},{$cerrada->id}")->assertOk()->json('data');

        $this->assertSame(['ALFA SA', 'BETA CERRADA SA'], array_column($datos, 'nombre'));
        $this->assertSame([true, false], array_column($datos, 'activa'));
        $this->assertNotContains($otra->id, array_column($datos, 'id'));

        // Sin pedir las inactivas, la cerrada no viene: es lo que esconde el listado común.
        $this->assertSame(['ALFA SA'], array_column($this->getJson("/api/empresas?ids={$activa->id},{$cerrada->id}")->json('data'), 'nombre'));

        // Números que no son números no rompen nada ni traen otras empresas.
        $this->getJson('/api/empresas?con_inactivas=1&ids=abc,,-1,0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/empresas?con_inactivas=1&ids=0')->assertOk()->assertJsonCount(0, 'data');
    }
}
