<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmpresaResource;
use App\Models\Contacto;
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

        $empresa = DB::transaction(function () use ($datos, $request) {
            $empresa = Empresa::create($datos + ['creada_por' => $request->user()->id]);
            $this->sincronizarRelaciones($empresa, $request->input('relaciones', []));

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

        $contacto = DB::transaction(function () use ($empresa, $contacto, $datos) {
            $contacto = $contacto?->exists
                ? tap($contacto)->update(collect($datos)->except('medios')->all())
                : $empresa->contactos()->create(collect($datos)->except('medios')->all());

            // Un solo principal por empresa.
            if (! empty($datos['principal'])) {
                $empresa->contactos()->where('id', '!=', $contacto->id)->update(['principal' => false]);
            }

            if (array_key_exists('medios', $datos)) {
                $this->sincronizarMedios($contacto, $datos['medios']);
            }

            return $contacto;
        });

        return response()->json(['id' => $contacto->id, 'mensaje' => 'Contacto guardado.']);
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
            'nombre' => [
                'required', 'string', 'max:150',
                Rule::unique('empresas', 'nombre')->ignore($empresa?->id),
            ],
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
        $contacto->medios()->delete();

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
