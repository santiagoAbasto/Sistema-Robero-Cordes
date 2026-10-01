<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmpresaResource;
use App\Models\Contacto;
use App\Services\FirmaDeMail;
use App\Models\ContactoMedio;
use App\Models\Empresa;
use App\Models\EmpresaCampo;
use App\Models\EmpresaEnlace;
use App\Models\EmpresaRelacion;
use App\Models\RazonSocial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Alta y modificación de la ficha.
 *
 * Regla que atraviesa todo: si no tenemos el dato, queda en blanco. Sólo el
 * nombre es imprescindible (y el CUIT en las razones sociales, porque sin eso
 * no se puede facturar). Nada se borra: se archiva.
 */
class EmpresaEscrituraController extends Controller
{
    private const RELACIONES = ['Cliente', 'Proveedor', 'Servicio', 'Empleado', 'Agenda general'];

    public function store(Request $request)
    {
        $datos = $this->validar($request);

        /*
          La empresa puede llegar con su primer contacto.

          "Despues hay que agregar manualmente el contacto... tendria que poder
          tomar los datos que pusimos al cargar la empresa". Al pegar el pie de
          un mail salen la empresa Y la persona que firma: se guardan juntas,
          en la misma operacion, y ese contacto nace como el principal.
        */
        $contacto = $request->validate([
            'contacto' => ['nullable', 'array'],
            'contacto.nombre' => ['required_with:contacto', 'string', 'max:120'],
            'contacto.cargo' => ['nullable', 'string', 'max:60'],
            'contacto.sector' => ['nullable', 'string', 'max:60'],
            'contacto.medios' => ['array'],
            'contacto.medios.*.tipo_medio_id' => ['required', 'exists:tipos_medio,id'],
            'contacto.medios.*.valor' => ['required', 'string', 'max:120'],
        ])['contacto'] ?? null;

        $empresa = DB::transaction(function () use ($datos, $request, $contacto) {
            $empresa = Empresa::create($datos + ['creada_por' => $request->user()->id]);
            $this->sincronizarRelaciones($empresa, $request->input('relaciones', []));

            if (filled($contacto['nombre'] ?? null)) {
                $this->escribirContacto($empresa, null, $contacto + ['principal' => true]);
            }

            return $empresa;
        });

        return (new EmpresaResource($this->recargar($empresa)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Empresa $empresa)
    {
        $datos = $this->validar($request, $empresa);

        DB::transaction(function () use ($empresa, $datos, $request) {
            $empresa->update($datos + ['modificada_por' => $request->user()->id]);

            if ($request->has('relaciones')) {
                $this->sincronizarRelaciones($empresa, $request->input('relaciones', []));
            }
        });

        return new EmpresaResource($this->recargar($empresa));
    }

    /** No se borra: se archiva. Todo el historial queda. */
    public function archivar(Empresa $empresa)
    {
        $empresa->update(['activa' => false]);

        return response()->json(['mensaje' => 'La empresa quedo archivada. No se borro nada.']);
    }

    public function restaurar(Empresa $empresa)
    {
        $empresa->update(['activa' => true]);

        return new EmpresaResource($this->recargar($empresa));
    }

    // ------------------------------------------------------------- contactos

    /**
     * Lee el pie de un mail y propone los datos de la empresa.
     *
     * "Posibilidad de copiar algo —ejemplo pie de mail— para que complete la
     * informacion". Se pega el mail entero, encabezados incluidos, y vuelven
     * los campos ya separados. Nada se guarda: es una propuesta que la
     * persona revisa, igual que las lineas de una cotizacion.
     */
    public function leerFirma(Request $request, FirmaDeMail $firma)
    {
        $datos = $request->validate([
            'texto' => ['required', 'string', 'min:10', 'max:8000'],
        ], [
            'texto.required' => 'Pega el pie del mail y completamos lo que se pueda.',
        ]);

        $leido = $firma->leer($datos['texto']);
        // El tipo de telefono dice que es el telefono: no es un dato mas.
        $cuantos = count(array_filter(
            array_diff_key($leido, ['tipo_telefono' => true]),
            fn ($v) => filled($v),
        ));

        return [
            'datos' => $leido,
            'mensaje' => $cuantos === 0
                ? 'No pudimos reconocer ningun dato. Cargalos a mano.'
                : "{$cuantos} datos reconocidos. Revisalos antes de guardar.",
        ];
    }

    public function guardarContacto(Request $request, Empresa $empresa, ?Contacto $contacto = null)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'sector' => ['nullable', 'string', 'max:60'],
            'cargo' => ['nullable', 'string', 'max:60'],
            'principal' => ['boolean'],
            'observacion' => ['nullable', 'string'],
            'activo' => ['boolean'],
            'medios' => ['array'],
            'medios.*.tipo_medio_id' => ['required', 'exists:tipos_medio,id'],
            'medios.*.valor' => ['required', 'string', 'max:120'],
            'medios.*.principal' => ['boolean'],
            'medios.*.nota' => ['nullable', 'string', 'max:80'],
        ]);

        [$contacto, $yaEstaba, $nuevos] = DB::transaction(
            fn () => $this->escribirContacto($empresa, $contacto, $datos),
        );

        return response()->json([
            'id' => $contacto->id,
            'ya_estaba' => $yaEstaba,
            'mensaje' => match (true) {
                ! $yaEstaba => 'Contacto guardado.',
                $nuevos > 0 => "{$contacto->nombre} ya estaba: "
                    .($nuevos === 1 ? 'se le sumo 1 dato nuevo' : "se le sumaron {$nuevos} datos nuevos")
                    .'. Lo que ya tenia no se repitio.',
                default => "{$contacto->nombre} ya estaba con esos mismos datos: no se repitio nada.",
            },
        ]);
    }

    /**
     * Crea el contacto, lo modifica, o le suma lo nuevo si ya estaba.
     *
     * "Si la empresa existe pero solo queremos agregar un nuevo contacto" y
     * "solo guardo datos nuevos, no repetidos": pegar dos veces la firma de
     * Pedro Quiroga no puede dejar dos Pedro Quiroga con el mismo celular.
     * Si ya hay alguien con ese nombre, se le suman los telefonos y mails que
     * no tenia y se completa lo que estaba vacio. Lo escrito no se pisa.
     *
     * @return array{0: Contacto, 1: bool, 2: int} el contacto, si ya estaba, y cuantos datos se sumaron
     */
    private function escribirContacto(Empresa $empresa, ?Contacto $contacto, array $datos): array
    {
        if ($contacto?->exists) {
            $contacto->update(collect($datos)->except('medios')->all());
            $this->unSoloPrincipal($empresa, $contacto, $datos);

            if (array_key_exists('medios', $datos)) {
                $this->sincronizarMedios($contacto, $datos['medios']);
            }

            return [$contacto, false, 0];
        }

        $existente = $this->mismaPersona($empresa, $datos['nombre']);

        if ($existente) {
            $completados = 0;

            foreach (['sector', 'cargo', 'observacion'] as $campo) {
                if (blank($existente->{$campo}) && filled($datos[$campo] ?? null)) {
                    $existente->{$campo} = $datos[$campo];
                    $completados++;
                }
            }

            $existente->save();

            return [$existente, true, $completados + $this->sumarMedios($existente, $datos['medios'] ?? [])];
        }

        $nuevo = $empresa->contactos()->create(collect($datos)->except('medios')->all());
        $this->unSoloPrincipal($empresa, $nuevo, $datos);
        $this->sincronizarMedios($nuevo, $datos['medios'] ?? []);

        return [$nuevo, false, 0];
    }

    /** Un solo principal por empresa. */
    private function unSoloPrincipal(Empresa $empresa, Contacto $contacto, array $datos): void
    {
        if (! empty($datos['principal'])) {
            $empresa->contactos()->where('id', '!=', $contacto->id)->update(['principal' => false]);
        }
    }

    /**
     * El contacto activo de la empresa con ese mismo nombre, si hay.
     *
     * Sin mayusculas, acentos ni espacios de mas: "Pedro A. Quiroga" y
     * "pedro a.  quiroga" son la misma persona. Nombres distintos no se
     * juntan aunque se parezcan: dos Juan en la misma empresa pasa.
     */
    private function mismaPersona(Empresa $empresa, string $nombre): ?Contacto
    {
        $plano = fn (?string $t) => preg_replace('/\s+/', ' ', trim(strtr(mb_strtolower((string) $t), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ])));

        return $empresa->contactos()->where('activo', true)->get()
            ->first(fn (Contacto $c) => $plano($c->nombre) === $plano($nombre));
    }

    /**
     * Le suma los medios que no tenia. Devuelve cuantos sumo.
     *
     * El mismo numero escrito distinto es el mismo: "(011) 4812-3365",
     * "+54 11 4812-3365" y "1148123365" no se repiten.
     */
    private function sumarMedios(Contacto $contacto, array $medios): int
    {
        $plano = function (string $valor): string {
            $valor = mb_strtolower(trim($valor));

            if (str_contains($valor, '@')) {
                return $valor;
            }

            // Solo los digitos, sin el 54 del pais —con el 9 de los celulares—
            // ni el 0 de larga distancia.
            return preg_replace('/^(549?)?0?/', '', preg_replace('/\D/', '', $valor) ?? '') ?? '';
        };

        $tiene = $contacto->medios()->where('activo', true)->pluck('valor')->map($plano)->all();
        $sumados = 0;

        foreach ($medios as $medio) {
            $valor = trim((string) ($medio['valor'] ?? ''));

            if ($valor === '' || in_array($plano($valor), $tiene, true)) {
                continue;
            }

            ContactoMedio::create([
                'contacto_id' => $contacto->id,
                'tipo_medio_id' => $medio['tipo_medio_id'],
                'valor' => $valor,
                // El que ya tenia sigue siendo el principal.
                'principal' => false,
                'nota' => $medio['nota'] ?? null,
            ]);

            $tiene[] = $plano($valor);
            $sumados++;
        }

        return $sumados;
    }

    public function archivarContacto(Contacto $contacto)
    {
        // Los que se van no se borran: las cotizaciones viejas siguen
        // mostrando a quién se le cotizó en ese momento.
        $contacto->update(['activo' => false]);

        return response()->json(['mensaje' => 'El contacto quedo desactivado.']);
    }

    // -------------------------------------------------------- razones sociales

    public function guardarRazonSocial(Request $request, Empresa $empresa, ?RazonSocial $razon = null)
    {
        $datos = $request->validate([
            'razon_social' => ['required', 'string', 'max:150'],
            'cuit' => ['required', 'string', 'max:13'],
            'condicion_iva' => ['nullable', Rule::in(['Resp. Inscripto', 'Monotributo', 'Exento', 'Consumidor Final'])],
            'iibb_condicion' => ['nullable', Rule::in(['No inscripto', 'Local', 'Convenio multilateral'])],
            'iibb_provincia_sede_id' => ['nullable', 'exists:provincias,id'],
            'iibb_numero' => ['nullable', 'string', 'max:20'],
            'inicio_actividades' => ['nullable', 'date'],
            'direccion_fiscal' => ['nullable', 'string', 'max:150'],
            'localidad' => ['nullable', 'string', 'max:80'],
            'habitual' => ['boolean'],
            'activa' => ['boolean'],
        ]);

        $razon = DB::transaction(function () use ($empresa, $razon, $datos) {
            $razon = $razon?->exists
                ? tap($razon)->update($datos)
                : $empresa->razonesSociales()->create($datos);

            // Una sola habitual por empresa.
            if (! empty($datos['habitual'])) {
                $empresa->razonesSociales()->where('id', '!=', $razon->id)->update(['habitual' => false]);
            }

            return $razon;
        });

        return response()->json(['id' => $razon->id, 'mensaje' => 'Razon social guardada.']);
    }

    public function archivarRazonSocial(RazonSocial $razon)
    {
        // Cuando cambian de razón social no se pisa la anterior: se desactiva,
        // así las facturas viejas siguen teniendo sentido.
        $razon->update(['activa' => false]);

        return response()->json(['mensaje' => 'La razon social quedo desactivada.']);
    }

    // ----------------------------------------------------------- enlaces

    public function guardarEnlace(Request $request, Empresa $empresa, ?EmpresaEnlace $enlace = null)
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(EmpresaEnlace::TIPOS)],
            'url' => ['required', 'string', 'max:500'],
            'etiqueta' => ['nullable', 'string', 'max:80'],
        ], [
            'url.required' => 'Falta la direccion. Se puede pegar tal cual, con o sin https.',
        ]);

        if (! $enlace?->exists) {
            $datos['orden'] = ($empresa->enlaces()->max('orden') ?? 0) + 1;
        }

        $enlace = $enlace?->exists
            ? tap($enlace)->update($datos)
            : $empresa->enlaces()->create($datos);

        return response()->json(['id' => $enlace->id, 'mensaje' => 'Enlace guardado.']);
    }

    public function borrarEnlace(EmpresaEnlace $enlace)
    {
        $enlace->anotarCambio('Archivado', 'url', $enlace->url, null);
        $enlace->delete();

        return response()->json(['mensaje' => 'Enlace quitado.']);
    }

    // ----------------------------------------------------- campos de la ficha

    public function guardarCampo(Request $request, Empresa $empresa, ?EmpresaCampo $campo = null)
    {
        $datos = $request->validate([
            'titulo' => ['required', 'string', 'max:60'],
            'valor' => ['nullable', 'string'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'usar_al_cotizar' => ['boolean'],
        ]);

        // El orden sólo se calcula al crear: modificar un campo no lo mueve de lugar.
        if (! $campo?->exists) {
            $datos['orden'] ??= ($empresa->campos()->max('orden') ?? 0) + 1;
        } else {
            unset($datos['orden']);
        }

        $campo = $campo?->exists
            ? tap($campo)->update($datos)
            : $empresa->campos()->create($datos);

        return response()->json(['id' => $campo->id, 'mensaje' => 'Campo guardado.']);
    }

    public function borrarCampo(EmpresaCampo $campo)
    {
        // Los campos propios sí se borran: no son historial, son configuración.
        $campo->anotarCambio('Archivado', 'titulo', $campo->titulo, null);
        $campo->delete();

        return response()->json(['mensaje' => 'Campo quitado.']);
    }

    // ------------------------------------------------------------- privadas

    private function validar(Request $request, ?Empresa $empresa = null): array
    {
        return $request->validate([
            /*
              Solo se controla si el nombre cambia. El indice viejo trae 66
              nombres repetidos en 173 fichas, y comparar siempre hacia que
              ninguna de esas se pudiera guardar, aunque solo se le tocara la
              observacion. Lo que no se puede es ponerle a una el nombre de otra.
            */
            'nombre' => array_filter([
                'required', 'string', 'max:150',
                $request->input('nombre') !== $empresa?->nombre
                    ? Rule::unique('empresas', 'nombre')->ignore($empresa?->id)
                    : null,
            ]),
            'codigo_indice' => ['nullable', 'string', 'max:20'],
            'codigo_isis' => ['nullable', 'string', 'max:20'],
            'cuit' => ['nullable', 'string', 'max:13'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'localidad_id' => ['nullable', 'exists:localidades,id'],
            'provincia_id' => ['nullable', 'exists:provincias,id'],
            'pais_id' => ['nullable', 'exists:paises,id'],
            'codigo_postal' => ['nullable', 'string', 'max:12'],
            'rubro_id' => ['nullable', 'exists:rubros,id'],
            'observacion_general' => ['nullable', 'string'],
            'visible_para' => ['nullable', Rule::in(['Todos', 'Solo los dueños', 'Un grupo'])],
        ], [
            'nombre.required' => 'El nombre de la empresa es lo unico que no puede quedar vacio.',
            'nombre.unique' => 'Ya existe una empresa con ese nombre.',
        ]);
    }

    /** Deja marcadas exactamente las relaciones que vinieron; el resto se desactiva. */
    private function sincronizarRelaciones(Empresa $empresa, array $relaciones): void
    {
        $validas = array_values(array_intersect($relaciones, self::RELACIONES));

        foreach (self::RELACIONES as $nombre) {
            $existente = EmpresaRelacion::firstOrNew([
                'empresa_id' => $empresa->id,
                'relacion' => $nombre,
            ]);

            $marcada = in_array($nombre, $validas, true);

            // No creamos filas para relaciones que nunca tuvo.
            if (! $existente->exists && ! $marcada) {
                continue;
            }

            $existente->activa = $marcada;
            $existente->desde ??= now()->toDateString();
            $existente->save();
        }
    }

    private function sincronizarMedios(Contacto $contacto, array $medios): void
    {
        // Solo los activos, que son los que se ven y se editan. Los dados de
        // baja no llegan a la pantalla: borrarlos aca los perdia de verdad.
        $contacto->medios()->where('activo', true)->delete();

        foreach ($medios as $medio) {
            if (trim((string) ($medio['valor'] ?? '')) === '') {
                continue;
            }

            ContactoMedio::create([
                'contacto_id' => $contacto->id,
                'tipo_medio_id' => $medio['tipo_medio_id'],
                'valor' => $medio['valor'],
                'principal' => $medio['principal'] ?? false,
                'nota' => $medio['nota'] ?? null,
            ]);
        }
    }

    private function recargar(Empresa $empresa): Empresa
    {
        return $empresa->fresh([
            'relaciones', 'contactos.medios.tipoMedio', 'razonesSociales.provinciaSede',
            'campos', 'localidad', 'provincia', 'pais', 'rubro', 'consultas.lineas',
        ]);
    }
}
