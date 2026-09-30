<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ConsultaLinea;
use App\Models\Empresa;
use App\Models\Forma;
use App\Models\Material;
use App\Models\User;
use App\Services\Migracion\EnlazadorDeLineas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BARRA y BARRA RED. / VARILLA son BARRA REDONDA.
 *
 * CORDES lo resolvio el 30-09-2026: "todo lo que dice barra, convertilo en
 * barra redonda, del sistema historico, los 3331". Lo que se cuida es que no
 * quede nada colgando de las formas que se borran, que la descripcion impresa
 * no se toque, y que nada las vuelva a crear.
 */
class BarraEsBarraRedondaTest extends TestCase
{
    use RefreshDatabase;

    private Forma $redonda;

    private Forma $barra;

    private Forma $varilla;

    private Consulta $consulta;

    protected function setUp(): void
    {
        parent::setUp();

        $forma = fn (string $nombre, ?string $origen = null) => Forma::create([
            'nombre' => $nombre,
            'clave' => Forma::claveDesde($nombre),
            'origen_id' => $origen,
            'activo' => true,
        ]);

        $this->redonda = $forma('BARRA REDONDA');
        $this->barra = $forma('BARRA');
        // Viene del Access: su id alli es el 10.
        $this->varilla = $forma('BARRA RED. / VARILLA', '10');

        $this->consulta = Consulta::create([
            'empresa_id' => Empresa::create(['nombre' => 'APEX'])->id,
            'tipo' => 'Cotizacion',
            'fecha' => '2019-05-10',
            'estado' => 'Confirmada',
            'usuario_id' => User::factory()->create()->id,
        ]);
    }

    private function linea(Forma $forma, string $descripcion, ?string $pedido = null): ConsultaLinea
    {
        return ConsultaLinea::create([
            'consulta_id' => $this->consulta->id,
            'orden' => 1,
            'forma_id' => $forma->id,
            'descripcion' => $descripcion,
            'pedido_forma' => $pedido,
            'igual_a_lo_pedido' => $pedido === null,
        ]);
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_09_30_000500_barra_es_barra_redonda.php'))->up();
    }

    /** Las lineas de las dos pasan a BARRA REDONDA. */
    public function test_las_lineas_pasan_a_barra_redonda(): void
    {
        $vieja = $this->linea($this->barra, 'TIT GR1 BARRA 1.60 X 915 MM');
        $varilla = $this->linea($this->varilla, 'AISI 304 VARILLA 6 X 1000');

        $this->migrar();

        $this->assertSame($this->redonda->id, $vieja->fresh()->forma_id);
        $this->assertSame($this->redonda->id, $varilla->fresh()->forma_id);
    }

    /**
     * La descripcion no se toca: es lo que se le imprimio al cliente.
     *
     * Se cambia la forma de la linea, que es como se clasifica. Reescribir
     * "BARRA" en el texto cambiaria una hoja que el cliente ya tiene.
     */
    public function test_la_descripcion_impresa_queda_como_se_mando(): void
    {
        $vieja = $this->linea($this->barra, 'TIT GR1 BARRA 1.60 X 915 MM');

        $this->migrar();

        $this->assertSame('TIT GR1 BARRA 1.60 X 915 MM', $vieja->fresh()->descripcion);
    }

    /** Lo que pidio el cliente se guarda por nombre: tambien se pasa. */
    public function test_lo_pedido_por_nombre_tambien_pasa(): void
    {
        $linea = $this->linea($this->redonda, 'Barra', pedido: 'BARRA RED. / VARILLA');

        $this->migrar();

        $this->assertSame('BARRA REDONDA', $linea->fresh()->pedido_forma);
    }

    /** La forma habitual de un material tampoco queda apuntando a la nada. */
    public function test_la_forma_habitual_de_los_materiales_pasa(): void
    {
        $material = Material::create(['nombre' => 'Titanio Gr1', 'forma_habitual_id' => $this->barra->id]);

        $this->migrar();

        $this->assertSame($this->redonda->id, $material->fresh()->forma_habitual_id);
    }

    /** Las dos se borran: "esta duplicada en las formas" era que aparecia dos veces. */
    public function test_las_formas_repetidas_se_borran(): void
    {
        $this->migrar();

        $this->assertNull(Forma::find($this->barra->id));
        $this->assertNull(Forma::find($this->varilla->id));
        $this->assertNotNull(Forma::find($this->redonda->id));
    }

    /**
     * El id del Access pasa a BARRA REDONDA.
     *
     * El importador busca las formas por ese id. Si se quedaba sin dueño, una
     * reimportacion volvia a crear BARRA RED. / VARILLA.
     */
    public function test_el_id_del_access_pasa_a_la_que_queda(): void
    {
        $this->migrar();

        $this->assertSame('10', $this->redonda->fresh()->origen_id);
        // Y el nombre no cambia: el importador nunca lo pisa.
        $this->assertSame('BARRA REDONDA', $this->redonda->fresh()->nombre);
    }

    /** Correrla dos veces no rompe nada. */
    public function test_correrla_de_nuevo_no_hace_nada(): void
    {
        $linea = $this->linea($this->barra, 'TIT GR1 BARRA 1.60 X 915 MM');

        $this->migrar();
        $this->migrar();

        $this->assertSame($this->redonda->id, $linea->fresh()->forma_id);
        $this->assertSame(1, DB::table('formas')->where('nombre', 'like', 'BARRA%')->count());
    }

    /**
     * Una reimportacion vuelve a enlazar "BARRA" con BARRA REDONDA.
     *
     * El enlazador exige todas las palabras del nombre, y las descripciones
     * viejas dicen "BARRA" sin "redonda". Sin el otro nombre, esas lineas
     * quedaban sin forma.
     */
    public function test_el_enlazador_reconoce_barra_sola_como_redonda(): void
    {
        /*
          Un catalogo del tamaño del de verdad. El enlazador pesa cada palabra
          por lo rara que es: con tres formas que dicen todas "barra", esa
          palabra no pesa nada y no enlaza nunca. En produccion hay unas 250.
        */
        foreach (['CHAPA', 'TUBO', 'CAÑO', 'DISCO', 'ALAMBRE', 'PLANCHUELA', 'ESFERA', 'PERFIL',
            'BRIDA', 'ANILLO', 'FLEJE', 'MALLA', 'ARANDELA', 'BULON', 'TUERCA', 'TORNILLO', 'BUJE',
            'PLACA', 'LAMINA', 'CINTA', 'ANGULO', 'VIGA', 'POLVO', 'LINGOTE', 'GRANALLA', 'PASTILLA',
            'ELECTRODO', 'CRISOL', 'CODO', 'BARRA HEXAGONAL'] as $nombre) {
            Forma::create(['nombre' => $nombre, 'clave' => Forma::claveDesde($nombre), 'activo' => true]);
        }

        $this->migrar();

        $formas = new EnlazadorDeLineas(Forma::nombresParaEnlazar(), material: false, exigencia: 0.42);

        $this->assertSame($this->redonda->id, $formas->elegir('TIT GR1 BARRA 1.60 X 915 MM'));
        // Y la hexagonal sigue siendo hexagonal: dice las dos palabras.
        $this->assertSame(
            Forma::where('nombre', 'BARRA HEXAGONAL')->value('id'),
            $formas->elegir('AISI 304 BARRA HEXAGONAL 19 X 3000'),
        );
    }

    /** "BARRA" es otro nombre de BARRA REDONDA, no una forma aparte. */
    public function test_barra_es_otro_nombre_de_la_redonda(): void
    {
        $this->migrar();

        $this->assertContains([$this->redonda->id, 'BARRA'], Forma::nombresParaEnlazar());
    }
}
