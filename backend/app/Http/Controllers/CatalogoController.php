<?php

namespace App\Http\Controllers;

use App\Models\CanoEstandar;
use App\Models\CondicionHabitual;
use App\Models\CondicionPago;
use App\Models\Caracteristica;
use App\Models\MotivoCambio;
use App\Models\EmpresaEnlace;
use App\Models\Pais;
use App\Models\Forma;
use App\Models\Localidad;
use App\Models\Material;
use App\Models\Moneda;
use App\Models\Provincia;
use App\Models\Rubro;
use App\Models\TipoMedio;
use App\Models\Unidad;
use App\Models\User;
use App\Services\CalculadoraDePeso;
use Illuminate\Http\Request;

/** Todas las listas que el sistema propone, en una sola llamada. */
class CatalogoController extends Controller
{
    public function index()
    {
        return [
            'relaciones' => \App\Models\EmpresaRelacion::OPCIONES,
            // Viajan como una lista de nombres, igual que antes: el campo es
            // un combo y lo que se escribe queda para la proxima.
            'motivos_cambio' => MotivoCambio::where('activo', true)
                ->orderBy('orden')->pluck('nombre'),
            // Con o sin costura, laminada, la norma: sugerencias que crecen
            // con lo que se escribe, igual que los motivos.
            'caracteristicas' => Caracteristica::where('activo', true)
                ->orderBy('orden')->pluck('nombre'),
            'transportes' => \App\Models\ConsultaLinea::TRANSPORTES,
            // Los motivos por los que no se cotiza un item: se editan en
            // Configuracion > Listas.
            'motivos_no_cotiza' => \App\Models\MotivoNoCotiza::where('activo', true)
                ->orderBy('orden')->pluck('nombre'),
            // Los tipos de comprobante de la compra: se editan en Configuración > Listas.
            'tipos_comprobante' => \App\Models\TipoComprobante::where('activo', true)
                ->orderBy('orden')->pluck('nombre'),
            'tipos_consulta' => ['Cotizacion', 'Pedido', 'Observacion'],
            'estados' => \App\Models\Consulta::ESTADOS,
            'estados_de_cierre' => \App\Models\Consulta::ESTADOS_DE_CIERRE,
            'solicitud_vias' => \App\Models\Consulta::VIAS,
            'condiciones_iva' => ['Resp. Inscripto', 'Monotributo', 'Exento', 'Consumidor Final'],
            'iibb_condiciones' => ['No inscripto', 'Local', 'Convenio multilateral'],
            'vias_envio' => ['Impresora', 'PDF', 'Correo', 'WhatsApp'],
            'ajuste_dif_cambio' => ['A fecha de pago', 'A fecha de factura', 'Otro'],
            'validez_por_defecto' => \App\Models\Consulta::VALIDEZ_POR_DEFECTO,

            // Que puede hacer el sistema segun lo que este configurado.
            'ia_activa' => filled(config('services.openai.key')),
            // Sólo si está activo: la credencial no sale del servidor.
            'direcciones_activas' => filled(config('services.google.places_key')),

            'rubros' => Rubro::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            // Las formas viajan con sus campos y su formula: la pantalla arma
            // la calculadora con eso, sin tener las cuentas escritas adentro.
            'formas' => Forma::where('activo', true)->orderBy('nombre')
                ->get(['id', 'clave', 'nombre', 'medidas_habituales', 'medidas_necesarias',
                    'campos', 'expresion', 'usa_cano'])
                // Cada campo dice si es una medida o un texto, y que ofrece.
                ->each(fn (Forma $f) => $f->campos = $f->camposDelCalculo() ?: null),
            'materiales' => Material::where('activo', true)->orderBy('nombre')
                ->get(['id', 'nombre', 'familia', 'densidad', 'uns', 'w_nr']),
            'canos' => CanoEstandar::where('activo', true)->orderBy('orden')
                ->get(['id', 'nombre', 'schedule', 'diametro_mm', 'pared_mm']),
            'unidades_medida' => CalculadoraDePeso::A_MILIMETROS,
            'unidades_peso' => array_keys(CalculadoraDePeso::DESDE_KILOS),
            'unidades' => Unidad::where('activo', true)->orderBy('orden')
                ->get(['id', 'codigo', 'nombre', 'sirve_para_vender', 'sirve_para_facturar']),
            'condiciones_pago' => CondicionPago::where('activo', true)->orderBy('orden')->get(['id', 'nombre']),
            'tipos_enlace' => EmpresaEnlace::TIPOS,
            'paises' => Pais::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'monedas' => Moneda::where('activo', true)->get(['id', 'nombre', 'moneda_base', 'referencia', 'por_defecto']),
            'moneda_por_defecto' => Moneda::where('por_defecto', true)->value('id'),
            'tipos_medio' => TipoMedio::where('activo', true)->get(['id', 'nombre']),
            'provincias' => Provincia::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'localidades' => Localidad::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'provincia_id']),
            'condiciones_habituales' => CondicionHabitual::where('activo', true)
                ->orderBy('orden')->get(['id', 'titulo', 'juego', 'texto', 'por_defecto']),
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name', 'iniciales', 'role']),
        ];
    }

    /**
     * Busca material por nombre o por cualquiera de las formas en que lo
     * escriben: "hast c276" encuentra HASTELLOY C-276.
     */
    public function materiales(Request $request)
    {
        return Material::buscar($request->query('q'))
            ->where('activo', true)
            ->with('alias:id,material_id,alias')
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre', 'familia', 'densidad']);
    }

    /**
     * Alta de un material desde la cotización, con todos sus datos.
     *
     * Antes un material nuevo nacía sólo con el nombre (densidad en null, sin
     * familia ni forma), y el peso no se calculaba hasta que alguien completara
     * la densidad en otro lado. Ahora se cargan todos los datos de una, así el
     * catálogo queda al día y completo. Si ya existe (mismo nombre, sin
     * distinguir mayúsculas), no se duplica: se devuelve ese y se le completan
     * los datos que tenga vacíos, sin pisar los que ya tiene.
     */
    public function crearMaterial(Request $request)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'familia' => ['nullable', 'string', 'max:60'],
            'forma_habitual_id' => ['nullable', 'exists:formas,id'],
            'densidad' => ['nullable', 'numeric', 'min:0'],
        ]);

        $nombre = trim($datos['nombre']);
        $existente = Material::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->first();

        if ($existente) {
            foreach (['familia', 'forma_habitual_id', 'densidad'] as $campo) {
                if (($datos[$campo] ?? null) !== null && blank($existente->$campo)) {
                    $existente->$campo = $datos[$campo];
                }
            }
            $existente->activo = true;
            $existente->save();

            return response()->json($existente->only(['id', 'nombre', 'familia', 'densidad', 'uns', 'w_nr']), 200);
        }

        $material = Material::create([
            'nombre' => $nombre,
            'familia' => $datos['familia'] ?? null,
            'forma_habitual_id' => $datos['forma_habitual_id'] ?? null,
            'densidad' => $datos['densidad'] ?? null,
            'activo' => true,
        ]);

        return response()->json($material->only(['id', 'nombre', 'familia', 'densidad', 'uns', 'w_nr']), 201);
    }

    /**
     * Completar o corregir los datos de un material ya cargado, desde la misma
     * cotización: si a "Acero 070M55" le falta la densidad, se carga ahí y el
     * peso ya se calcula. Lo que se manda se guarda (es una edición explícita);
     * un campo que no se manda queda como estaba.
     */
    public function actualizarMaterial(Request $request, Material $material)
    {
        $datos = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:80', 'unique:materiales,nombre,'.$material->id],
            'familia' => ['sometimes', 'nullable', 'string', 'max:60'],
            'forma_habitual_id' => ['sometimes', 'nullable', 'exists:formas,id'],
            'densidad' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        if (isset($datos['nombre'])) {
            $datos['nombre'] = trim($datos['nombre']);
        }

        $material->fill($datos)->save();

        return response()->json($material->only(['id', 'nombre', 'familia', 'densidad', 'uns', 'w_nr']));
    }
}
