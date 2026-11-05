<?php

namespace App\Console\Commands;

use App\Models\Forma;
use App\Models\Material;
use App\Models\User;
use App\Services\Cordes\Agente;
use App\Services\Cordes\Cordes;
use App\Services\Cordes\Herramientas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mide cómo anda Cordes IA con los datos REALES de este servidor: cuántos datos hay, qué tan completos están y cuánto tarda
 * cada consulta. Sólo lee y NO muestra datos de clientes (sólo cifras y tiempos): se puede pegar la salida donde haga falta.
 *
 * Se corre en el servidor con: php artisan cordes:diagnostico
 */
class CordesDiagnostico extends Command
{
    protected $signature = 'cordes:diagnostico';

    protected $description = 'Cuántos datos hay, qué tan completos están y cuánto tarda Cordes IA (sólo lee; no muestra datos de clientes)';

    private const LENTO_MS = 1500;

    public function handle(): int
    {
        $usuario = User::query()->where('role', 'Administrador')->orderBy('id')->first() ?? User::query()->orderBy('id')->first();

        if ($usuario === null) {
            $this->error('No hay ningún usuario: no se puede medir.');

            return self::FAILURE;
        }

        $this->line('Base: '.DB::connection()->getDatabaseName());
        $this->datos();
        $this->cobertura();
        $this->ia();
        $this->tiempos($usuario);

        return self::SUCCESS;
    }

    private function datos(): void
    {
        $this->newLine();
        $this->info('DATOS');
        $formas = Forma::query()->where('activo', true)->get();

        $this->table(['Qué', 'Cuántos'], [
            ['Empresas (activas)', DB::table('empresas')->count().' ('.DB::table('empresas')->where('activa', true)->count().')'],
            ['Cotizaciones, pedidos y observaciones', DB::table('consultas')->whereNull('deleted_at')->count()],
            ['Renglones', DB::table('consulta_lineas')->count()],
            ['Comprobantes (facturas y remitos)', DB::table('consulta_comprobantes')->count()],
            ['Materiales activos (con densidad)', Material::query()->where('activo', true)->count().' ('.Material::query()->where('activo', true)->where('densidad', '>', 0)->count().')'],
            ['Alias de materiales', DB::table('material_alias')->count()],
            ['Formas activas (con cálculo de peso)', $formas->count().' ('.$formas->filter(fn (Forma $f) => filled($f->expresion) || $f->usa_cano)->count().')'],
            ['Medidas de caños estándar', DB::table('canos_estandar')->count()],
        ]);
    }

    private function cobertura(): void
    {
        $this->newLine();
        $this->info('QUÉ TAN COMPLETOS ESTÁN LOS RENGLONES (lo que Cordes puede buscar con exactitud)');
        $total = max(1, DB::table('consulta_lineas')->count());
        $pct = fn (int $n) => number_format($n / $total * 100, 1, ',', '.').' %';

        $this->table(['Renglones con…', '%'], [
            ['material enlazado al catálogo', $pct(DB::table('consulta_lineas')->whereNotNull('material_id')->count())],
            ['forma enlazada', $pct(DB::table('consulta_lineas')->whereNotNull('forma_id')->count())],
            ['precio', $pct(DB::table('consulta_lineas')->where('precio_unitario', '>', 0)->count())],
            ['unidad de venta', $pct(DB::table('consulta_lineas')->whereNotNull('unidad_venta_id')->count())],
        ]);
    }

    private function ia(): void
    {
        $this->newLine();
        $this->info('IA');
        $this->line('Credencial cargada: '.(Agente::estaConfigurada() ? 'sí' : 'NO (Cordes contesta sólo con el código)'));
        $this->line('Modelo de Cordes IA: '.(config('services.openai.modelo_cordes') ?: '(el de siempre)').' · de respaldo: '.(config('services.openai.model') ?: '—'));
    }

    private function tiempos(User $usuario): void
    {
        $this->newLine();
        $this->info('TIEMPOS (milisegundos; la primera vez es sin memoria, la segunda con memoria)');

        // El material y la forma más cotizados: una prueba realista sin nombrar clientes.
        $material = DB::table('consulta_lineas as l')->join('materiales as m', 'm.id', '=', 'l.material_id')->where('m.densidad', '>', 0)
            ->selectRaw('m.nombre, count(*) n')->groupBy('m.nombre')->orderByDesc('n')->limit(1)->value('nombre') ?? 'Monel';
        $herr = new Herramientas($usuario);
        $filas = [];

        $medir = function (string $nombre, callable $f) use (&$filas): void {
            $t1 = $this->ms($f);
            $t2 = $this->ms($f);
            $filas[] = [$nombre, $t1, $t2, max($t1, $t2) > self::LENTO_MS ? 'LENTO' : 'ok'];
        };

        $medir('Buscar empresas (texto corto)', fn () => $herr->ejecutar('buscar_empresas', ['texto' => 'sa']));
        $medir('Buscar renglones de un material', fn () => $herr->ejecutar('buscar', ['vista' => 'renglones', 'filtros' => ['material' => $material]]));
        $medir('Contar cotizaciones por mes', fn () => $herr->ejecutar('agrupar', ['vista' => 'consultas', 'por' => 'mes', 'filtros' => []]));
        $medir('Contar empresas por provincia', fn () => $herr->ejecutar('agrupar', ['vista' => 'empresas', 'por' => 'provincia', 'filtros' => []]));
        $medir('Precios de un material', fn () => $herr->ejecutar('precios', ['filtros' => ['material' => $material]]));
        $medir('Evolución del precio por año', fn () => $herr->ejecutar('evolucion_de_precios', ['filtros' => ['material' => $material]]));
        $medir('Tabla de caños (2", SCH 40)', fn () => $herr->ejecutar('canos', ['nominal' => '2', 'schedule' => '40']));

        // Preguntas completas, con el código solo (sin IA): lo que se siente al usarlo.
        $clave = config('services.openai.key');
        config(['services.openai.key' => '']);

        foreach ([
            'Peso de una barra' => "cuánto pesa una barra de {$material} de 38,1 x 145 mm",
            'Peso de un caño' => "cuánto pesa un caño de 2 pulgadas sch 40 de {$material} de 6 metros",
            'Precio de un material' => "a cuánto cotizamos {$material}",
            'Evolución del precio' => "cómo viene el precio de {$material} por año",
            'Ranking de materiales' => 'ranking de materiales este año',
            'Cotizaciones por mes' => 'cuántas cotizaciones hicimos por mes este año',
            'Comparar dos piezas' => "comparame lo que pesa una barra de {$material} de 40 contra una de 20 mm con un largo de un metro",
            'Sumar dos ítems' => "cuánto pesan 4 barras de {$material} de 38,1 x 145 y 2 chapas de {$material} de 3 x 1000 x 2000",
            'Peso y precio juntos' => "cuánto pesa una barra de {$material} de 20 mm de diámetro y 1 metro y cuánto cuesta",
            'Cuántas piezas salen' => 'cuántas piezas de 150 x 150 salen de una chapa de 1000 x 2000',
            'Una frase que no se entiende' => 'asdf qwer zxcv',
        ] as $nombre => $pregunta) {
            $medir("Pregunta: {$nombre}", fn () => (new Cordes($usuario))->responder($pregunta));
        }

        config(['services.openai.key' => $clave]);

        $this->table(['Qué', '1ª vez', '2ª vez', 'Estado'], $filas);

        $lentos = array_filter($filas, fn ($f) => $f[3] === 'LENTO');
        $lentos === []
            ? $this->info('Todo por debajo de '.self::LENTO_MS.' ms.')
            : $this->warn(count($lentos).' consulta(s) lentas: pasame esta salida para afinarlas.');
    }

    private function ms(callable $f): int
    {
        $t = microtime(true);

        try {
            $f();
        } catch (\Throwable $e) {
            $this->warn('Falló una medición: '.class_basename($e));
        }

        return (int) round((microtime(true) - $t) * 1000);
    }
}
