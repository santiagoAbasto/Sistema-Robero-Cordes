<?php

namespace App\Http\Controllers;

use App\Models\CanoEstandar;
use App\Models\CondicionHabitual;
use App\Models\CondicionPago;
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
            'motivos_cambio' => \App\Models\ConsultaLinea::MOTIVOS,
            'tipos_consulta' => ['Cotizacion', 'Pedido', 'Observacion'],
            'estados' => \App\Models\Consulta::ESTADOS,
            'estados_de_cierre' => \App\Models\Consulta::ESTADOS_DE_CIERRE,
            'solicitud_vias' => ['Mail', 'WhatsApp', 'Telefono', 'En persona'],
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
                    'campos', 'expresion', 'usa_cano']),
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
}
