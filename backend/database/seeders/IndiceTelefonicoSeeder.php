<?php

namespace Database\Seeders;

use App\Models\Consulta;
use App\Models\ConsultaCondicion;
use App\Models\ConsultaLinea;
use App\Models\Contacto;
use App\Models\ContactoMedio;
use App\Models\Empresa;
use App\Models\EmpresaCampo;
use App\Models\EmpresaRelacion;
use App\Models\Forma;
use App\Models\Impresion;
use App\Models\Localidad;
use App\Models\Material;
use App\Models\Moneda;
use App\Models\Observacion;
use App\Models\Permiso;
use App\Models\RazonSocial;
use App\Models\Rubro;
use App\Models\TipoMedio;
use App\Models\Unidad;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Datos de ejemplo tomados de las fichas reales del Índice Telefónico,
 * para que el sistema se pueda probar con algo parecido a lo que usan.
 */
class IndiceTelefonicoSeeder extends Seeder
{
    private array $u = [];      // usuarios por iniciales
    private array $tm = [];     // tipos de medio por nombre
    private array $un = [];     // unidades por código
    private array $mon = [];    // monedas por nombre

    public function run(): void
    {
        $this->cargarReferencias();

        $silmar = $this->silmar();
        $this->distribuidores();
        $rioTinto = $this->rioTinto();
        $this->otras();

        $this->historialDeSilmar($silmar);
        $this->casoRioTinto($rioTinto);
    }

    // ------------------------------------------------------------- referencias

    private function cargarReferencias(): void
    {
        $usuarios = [
            ['RIC', 'Roberto Cordes', 'roberto@cordes.com', 'Administrador'],
            ['JD', 'Juan Roberti', 'juan@cordes.com', 'Administrador'],
            ['RAC', 'Ricardo A. Cordes', 'ricardo@cordes.com', 'Vendedor'],
            ['ADM', 'Administracion', 'administracion@cordes.com', 'Consulta'],
        ];

        foreach ($usuarios as [$ini, $nombre, $mail, $rol]) {
            $user = User::updateOrCreate(
                ['email' => $mail],
                ['name' => $nombre, 'iniciales' => $ini, 'role' => $rol, 'password' => 'cordes2026', 'activo' => true]
            );

            $this->u[$ini] = $user;

            // Los dueños ven todo; el vendedor ve solo sus empresas y no lee
            // las notas de los demás; administración solo mira.
            Permiso::updateOrCreate(['user_id' => $user->id], match ($rol) {
                'Administrador' => [
                    've_fichas' => 'Todas', 've_importes' => true, 've_notas_de_otros' => true,
                    'puede_modificar' => true, 'puede_imprimir' => true,
                    'puede_archivar' => true, 've_control_cambios' => true,
                ],
                'Vendedor' => [
                    've_fichas' => 'Solo las suyas', 've_importes' => true, 've_notas_de_otros' => false,
                    'puede_modificar' => true, 'puede_imprimir' => true,
                    'puede_archivar' => false, 've_control_cambios' => false,
                ],
                default => [
                    've_fichas' => 'Todas', 've_importes' => true, 've_notas_de_otros' => false,
                    'puede_modificar' => false, 'puede_imprimir' => false,
                    'puede_archivar' => false, 've_control_cambios' => false,
                ],
            });
        }

        $this->tm = TipoMedio::pluck('id', 'nombre')->all();
        $this->un = Unidad::pluck('id', 'codigo')->all();
        $this->mon = Moneda::pluck('id', 'nombre')->all();
    }

    private function localidad(string $nombre): ?Localidad
    {
        return Localidad::where('nombre', $nombre)->with('provincia')->first();
    }

    private function crearEmpresa(array $datos, array $relaciones): Empresa
    {
        $loc = $this->localidad($datos['localidad'] ?? '');

        $empresa = Empresa::updateOrCreate(
            ['nombre' => $datos['nombre']],
            [
                'codigo_indice' => $datos['codigo_indice'] ?? null,
                'codigo_isis' => $datos['codigo_isis'] ?? null,
                'cuit' => $datos['cuit'] ?? null,
                'direccion' => $datos['direccion'] ?? null,
                'localidad_id' => $loc?->id,
                'provincia_id' => $loc?->provincia_id,
                'pais_id' => $loc?->provincia?->pais_id,
                'codigo_postal' => $datos['cp'] ?? null,
                'rubro_id' => isset($datos['rubro']) ? Rubro::where('nombre', $datos['rubro'])->value('id') : null,
                'observacion_general' => $datos['observacion'] ?? null,
                'creada_por' => $this->u['RIC']->id,
            ]
        );

        foreach ($relaciones as $rel => $desde) {
            EmpresaRelacion::updateOrCreate(
                ['empresa_id' => $empresa->id, 'relacion' => $rel],
                ['desde' => $desde]
            );
        }

        return $empresa;
    }

    private function contacto(Empresa $e, string $nombre, ?string $sector, bool $principal, array $medios): Contacto
    {
        $c = Contacto::updateOrCreate(
            ['empresa_id' => $e->id, 'nombre' => $nombre],
            ['sector' => $sector, 'principal' => $principal]
        );

        foreach ($medios as [$tipo, $valor, $esPrincipal, $nota]) {
            ContactoMedio::updateOrCreate(
                ['contacto_id' => $c->id, 'tipo_medio_id' => $this->tm[$tipo], 'valor' => $valor],
                ['principal' => $esPrincipal, 'nota' => $nota]
            );
        }

        return $c;
    }

    // ---------------------------------------------------------------- empresas

    private function silmar(): Empresa
    {
        $e = $this->crearEmpresa([
            'nombre' => 'METALURGICA SILMAR DE BAIOCCO',
            'codigo_indice' => '7402',
            'codigo_isis' => 'IS-04872',
            'cuit' => '30-71028456-3',
            'direccion' => 'Cid Campeador 375',
            'localidad' => 'Rio Tercero',
            'cp' => '5870',
            'rubro' => 'TITANIO NIMO',
            'observacion' => 'Trabaja para Atanor y Petroquimica Rio Tercero. Compra siempre Hastelloy, Monel y Titanio. Tambien nos vende recortes.',
        ], ['Cliente' => '2019-03-01', 'Proveedor' => '2023-07-14']);

        $this->contacto($e, 'Gabriel Ferreyra', 'Compras', true, [
            ['Telefono', '03571 42-5024', true, 'Linea directa'],
            ['WhatsApp', '03571 15-48960', true, null],
            ['Mail', 'gferreyra@itc.com.ar', true, null],
            ['Mail', 'compras@silmar.com.ar', false, 'Copia a compras'],
        ]);

        $this->contacto($e, 'Marcela Diaz', 'Administracion', false, [
            ['Telefono', '03571 42-5025', true, null],
            ['Mail', 'metsilmaradministracion@itc.com.ar', true, null],
        ]);

        $this->contacto($e, 'Cesar Baiocco', 'Dueño', false, [
            ['WhatsApp', '03571 15-77012', true, 'Solo urgencias'],
            ['Mail', 'cbaiocco@itc.com.ar', true, null],
        ]);

        $cordoba = \App\Models\Provincia::where('nombre', 'Cordoba')->value('id');

        $razones = [
            ['METALURGICA SILMAR DE BAIOCCO', '30-71028456-3', true, 'Local', '270-118904-5'],
            ['BAIOCCO CESAR FRANCISCO', '20-14785236-9', false, 'Convenio multilateral', '904-283746-2'],
            ['SILMAR CONSTRUCCIONES S.R.L.', '30-71455890-2', false, 'Convenio multilateral', '904-771225-8'],
        ];

        foreach ($razones as [$rs, $cuit, $habitual, $iibb, $nro]) {
            RazonSocial::updateOrCreate(
                ['empresa_id' => $e->id, 'razon_social' => $rs],
                [
                    'cuit' => $cuit,
                    'condicion_iva' => 'Resp. Inscripto',
                    'iibb_condicion' => $iibb,
                    'iibb_provincia_sede_id' => $cordoba,
                    'iibb_numero' => $nro,
                    'inicio_actividades' => '2019-03-01',
                    'direccion_fiscal' => 'Cid Campeador 375',
                    'localidad' => 'Rio Tercero',
                    'habitual' => $habitual,
                ]
            );
        }

        $campos = [
            ['Tipo de pago', '50% anticipo, saldo contra entrega', true],
            ['Flete', 'Lo paga el cliente', false],
            ['Como lo retira', 'Lo pasa a buscar con transporte propio', false],
            ['Lista de precios', '5 TA FILA', true],
            ['Moneda habitual', 'DOLAR BILLETE BNA VENDEDOR', true],
            ['Plazo que suele pedir', '30 dias', false],
        ];

        foreach ($campos as $i => [$titulo, $valor, $usar]) {
            EmpresaCampo::updateOrCreate(
                ['empresa_id' => $e->id, 'titulo' => $titulo],
                ['valor' => $valor, 'orden' => $i + 1, 'usar_al_cotizar' => $usar]
            );
        }

        return $e;
    }

    private function distribuidores(): void
    {
        $datos = [
            ['METALURGICA SALEM S.A.', '7415', 'IS-04915', '30-70884521-7', 'Av. Roca 1220', 'S.M. de Tucuman', '4000', 'TITANIO NIMO', 'Gutierrez/Achaval', 'Compras', '0381 445-2210', 'ventas@salem.com.ar'],
            ['METALURGICA CENTRO S.R.L.', '7428', 'IS-04981', '30-71190043-5', 'Bv. Los Andes 890', 'Cordoba', '5000', null, null, null, null, null],
            ['METALURGICA DEL SUR', '7431', 'IS-05012', '30-70552218-9', 'Ruta 9 Km 285', 'Rosario', '2000', 'ACEROS', 'Jorge Perez', 'Compras', '0341 456-8890', 'jperez@delsur.com.ar'],
            ['METALMECANICA ANDINA', '7440', 'IS-05028', '30-71330876-1', 'Parque Industrial L 12', 'Mendoza', '5500', null, null, null, null, null],
        ];

        foreach ($datos as [$nombre, $ci, $isis, $cuit, $dir, $loc, $cp, $rubro, $cont, $sector, $tel, $mail]) {
            $e = $this->crearEmpresa(compact('nombre', 'cuit', 'dir') + [
                'codigo_indice' => $ci,
                'codigo_isis' => $isis,
                'direccion' => $dir,
                'localidad' => $loc,
                'cp' => $cp,
                'rubro' => $rubro,
            ], ['Cliente' => '2020-01-01']);

            RazonSocial::updateOrCreate(
                ['empresa_id' => $e->id, 'razon_social' => $nombre],
                ['cuit' => $cuit, 'condicion_iva' => 'Resp. Inscripto', 'habitual' => true]
            );

            EmpresaCampo::updateOrCreate(
                ['empresa_id' => $e->id, 'titulo' => 'Tipo de pago'],
                ['valor' => $nombre === 'METALURGICA DEL SUR' ? '30 dias' : 'Contado', 'orden' => 1, 'usar_al_cotizar' => true]
            );

            EmpresaCampo::updateOrCreate(
                ['empresa_id' => $e->id, 'titulo' => 'Lista de precios'],
                ['valor' => $nombre === 'METALURGICA CENTRO S.R.L.' ? '4 TA FILA' : '5 TA FILA', 'orden' => 2, 'usar_al_cotizar' => true]
            );

            if ($cont) {
                $this->contacto($e, $cont, $sector, true, array_values(array_filter([
                    $tel ? ['Telefono', $tel, true, null] : null,
                    $mail ? ['Mail', $mail, true, null] : null,
                ])));
            }
        }
    }

    private function rioTinto(): Empresa
    {
        $e = $this->crearEmpresa([
            'nombre' => 'RIO TINTO ARGENTINA',
            'codigo_indice' => '7433',
            'codigo_isis' => 'IS-05033',
            'cuit' => '30-69874521-4',
            'direccion' => 'Ruta 40 Km 4300',
            'localidad' => 'Salta',
            'cp' => '4400',
            'rubro' => 'MINERIA',
            'observacion' => 'Minera. Tiene credito a 60 dias aprobado. Compras centralizadas en Salta.',
        ], ['Cliente' => '2024-05-22']);

        $this->contacto($e, 'Silvina Aguirre', 'Compras', true, [
            ['Telefono', '0387 431-7700', true, null],
            ['Mail', 'compras.ar@riotinto.com', true, null],
        ]);

        RazonSocial::updateOrCreate(
            ['empresa_id' => $e->id, 'razon_social' => 'RIO TINTO ARGENTINA S.A.'],
            ['cuit' => '30-69874521-4', 'condicion_iva' => 'Resp. Inscripto', 'habitual' => true,
                'iibb_condicion' => 'Convenio multilateral', 'iibb_numero' => '901-556677-3']
        );

        EmpresaCampo::updateOrCreate(
            ['empresa_id' => $e->id, 'titulo' => 'Tipo de pago'],
            ['valor' => 'Credito a 60 dias', 'orden' => 1, 'usar_al_cotizar' => true]
        );

        return $e;
    }

    private function otras(): void
    {
        $t = $this->crearEmpresa([
            'nombre' => 'TRANSPORTES DEL CENTRO',
            'codigo_indice' => '6890',
            'cuit' => '30-70118822-6',
            'localidad' => 'Cordoba',
            'observacion' => 'Nos hace los envios a Cordoba y Rio Tercero.',
        ], ['Servicio' => '2022-02-10']);

        $this->contacto($t, 'Hector Roldan', 'Logistica', true, [
            ['WhatsApp', '0351 15-667788', true, null],
        ]);

        $this->crearEmpresa([
            'nombre' => 'ESTUDIO CONTABLE MARTINEZ',
            'localidad' => 'C.A.B.A.',
            'observacion' => 'No es cliente ni proveedor. Lo tenemos en la agenda.',
        ], ['Agenda general' => null]);
    }

    // ------------------------------------------------------- historial de SILMAR

    private function historialDeSilmar(Empresa $e): void
    {
        $gabriel = $e->contactos()->where('nombre', 'Gabriel Ferreyra')->first();
        $marcela = $e->contactos()->where('nombre', 'Marcela Diaz')->first();
        $cesar = $e->contactos()->where('nombre', 'Cesar Baiocco')->first();
        $dolar = $this->mon['DOLAR BILLETE BNA VENDEDOR'];

        // --- una cotización con dos líneas cotizadas distinto a lo pedido -----
        $cot = $this->consulta($e, [
            'tipo' => 'Cotizacion',
            'fecha' => '2026-07-20',
            'contacto_id' => $gabriel?->id,
            'usuario_id' => $this->u['RIC']->id,
            'moneda_id' => $dolar,
            'condicion_pago' => '50% anticipo, saldo contra entrega',
            'lista_precios' => '5 TA FILA',
            'id_sistema' => 'C-2026-0741',
            'nota' => '(stock) bn — le cotice tomando los canos mas largos. Volpor + Forest. Pagina 1 de 3.',
            'solicitud_via' => 'Mail',
            'solicitud_fecha' => '2026-07-19',
            'solicitud_texto' => 'Necesito 6 barras de hastelloy C-276 de 38 x 145, 4 de 316 diametro 65 y 2 caños de niquel 201 de 4" por 2 metros.',
            'estado' => 'Confirmada',
        ]);

        $this->linea($cot, 1, [
            'material' => 'HASTELLOY C-276', 'forma' => 'BARRA REDONDA',
            'dimensiones' => '38.1 X 145 MM', 'diametro_mm' => 38.1,
            'cantidad' => 6, 'unidad' => 'UN', 'precio_unitario' => 148.00,
            'descripcion' => 'HASTELLOY C-276 BAR RED 38.1 X 145MM',
            'igual' => true,
        ]);

        $this->linea($cot, 2, [
            'material' => 'AISI 316TI', 'forma' => 'BARRA',
            'dimensiones' => 'DIA 65 X 145 MM',
            'cantidad' => 4, 'unidad' => 'UN', 'precio_unitario' => 56.58,
            'descripcion' => 'AISI 316TI BARRA DIA 65 X 145MM',
            'igual' => false, 'motivo' => 'Se sugiere otra calidad',
            'pedido' => ['AISI 316', 'BARRA', 'DIA 65 X 145 MM', 4, 'UN'],
        ]);

        $this->linea($cot, 3, [
            'material' => 'NIQUEL 201', 'forma' => 'CAÑO',
            'dimensiones' => '4" SCH 40 X 3000 MM',
            'cantidad' => 2, 'unidad' => 'UN', 'precio_unitario' => 1980.40,
            'descripcion' => 'NIQUEL 201 CANO 4" SCH 40 X 3000 MM',
            'igual' => false, 'motivo' => 'Largo comercial',
            'pedido' => ['NIQUEL 201', 'CAÑO', '4" SCH 40 X 2000 MM', 2, 'UN'],
        ]);

        // --- la línea que se cotiza por metro y se factura por kilo ----------
        $this->linea($cot, 4, [
            'material' => 'TITANIO GR2', 'forma' => 'BARRA REDONDA',
            'dimensiones' => '50.0 MM', 'diametro_mm' => 50.0,
            'cantidad' => 3, 'unidad' => 'MT', 'unidad_factura' => 'KG',
            'factor' => 10.1300, 'precio_por_kilo' => 48.00,
            'descripcion' => 'TITANIO GR2 BARRA REDONDA 50.0 MM — se factura por kilo',
            'igual' => true,
        ]);

        $this->condiciones($cot, ['1RA FILA X 1.10', 'Plazo de entrega: segun disponibilidad', 'Precios en Dolares Estadounidenses, mas IVA']);

        $this->observaciones($cot, [
            ['2026-07-20 09:40', 'RIC', 'Pidio que se lo entreguemos en la planta de Atanor. Confirmar con el transporte.'],
            ['2026-07-24 10:12', 'JD', 'Llamo Gabriel: le parecio caro el 316TI. Le ofrecimos el largo de 73MM.'],
            ['2026-08-01 16:25', 'RIC', 'Quedo en confirmar la semana que viene. Volver a llamar el lunes.'],
        ]);

        Impresion::updateOrCreate(
            ['consulta_id' => $cot->id, 'via' => 'Correo'],
            [
                'fecha' => '2026-07-20 12:15', 'usuario_id' => $this->u['RIC']->id,
                'nombre_en_pdf' => $e->nombre, 'contacto_id' => $gabriel?->id,
                'telefono' => '03571 42-5024', 'mail' => 'gferreyra@itc.com.ar',
            ]
        );

        Impresion::updateOrCreate(
            ['consulta_id' => $cot->id, 'via' => 'WhatsApp'],
            [
                'fecha' => '2026-07-20 12:18', 'usuario_id' => $this->u['RIC']->id,
                'nombre_en_pdf' => $e->nombre, 'contacto_id' => $marcela?->id,
                'telefono' => '03571 42-5025', 'mail' => 'metsilmaradministracion@itc.com.ar',
            ]
        );

        // --- una cotización vieja que terminó en venta -----------------------
        $vieja = $this->consulta($e, [
            'tipo' => 'Cotizacion', 'fecha' => '2020-12-02',
            'contacto_id' => $gabriel?->id, 'usuario_id' => $this->u['JD']->id,
            'moneda_id' => $dolar, 'tipo_cambio' => 78.20, 'nro_factura' => '22763',
            'nota' => 'stock', 'estado' => 'Vendida',
        ]);

        $this->linea($vieja, 1, [
            'material' => 'HASTELLOY C-276', 'forma' => 'BARRA REDONDA',
            'dimensiones' => '38.1 X 145 MM', 'diametro_mm' => 38.1,
            'cantidad' => 2, 'unidad' => 'C/U', 'precio_unitario' => 142.00,
            'descripcion' => '2 C/U HASTELLOY C-276 BAR RED 38.1 X 145MM C/U(+IVA)', 'igual' => true,
        ]);

        $this->linea($vieja, 2, [
            'material' => 'HASTELLOY C-276', 'forma' => 'BARRA REDONDA',
            'dimensiones' => '44.45 X 73 MM', 'diametro_mm' => 44.45,
            'cantidad' => 2, 'unidad' => 'C/U', 'precio_unitario' => 100.00,
            'descripcion' => '2 C/U HASTELLOY C-276 BAR RED 44.45 X 73MM C/U(+IVA)', 'igual' => true,
        ]);

        // --- un pedido: venta de material que ya está en stock ---------------
        $pedido = $this->consulta($e, [
            'tipo' => 'Pedido', 'fecha' => '2026-05-12',
            'contacto_id' => $gabriel?->id, 'usuario_id' => $this->u['RIC']->id,
            'moneda_id' => $dolar, 'estado' => 'Vendida',
        ]);

        $this->linea($pedido, 1, [
            'material' => 'NIQUEL 201', 'forma' => 'BARRA', 'dimensiones' => '38.1 X 110 MM',
            'cantidad' => 4, 'unidad' => 'UN', 'precio_unitario' => 206.00,
            'descripcion' => '4 UN NIQUEL 201 BARRA 38.1 X 110 MM', 'igual' => true,
            'stock' => ['Deposito Palpa', 'H-88421'],
        ]);

        $this->linea($pedido, 2, [
            'material' => 'TITANIO GR2', 'forma' => 'BARRA', 'dimensiones' => '50.0 X 125 MM',
            'cantidad' => 2, 'unidad' => 'UN', 'precio_unitario' => 165.00,
            'descripcion' => '2 UN TIT GR2 BARRA 50.0 X 125 MM', 'igual' => true,
            'stock' => ['Deposito Palpa', 'T-22190'],
        ]);

        $this->observaciones($pedido, [
            ['2026-05-12 11:00', 'RIC', 'Lo retira el jueves. Avisar cuando este preparado.'],
        ]);

        // --- una observación suelta -----------------------------------------
        $this->consulta($e, [
            'tipo' => 'Observacion', 'fecha' => '2026-04-02',
            'contacto_id' => $cesar?->id, 'usuario_id' => $this->u['JD']->id,
            'texto' => 'Llamo Cesar por el tema de los recortes que nos quiere vender. Pasar a verlo el mes que viene.',
            'estado' => 'Confirmada',
        ]);
    }

    // -------------------------------------------- el caso del 12/08: RIO TINTO

    private function casoRioTinto(Empresa $rioTinto): void
    {
        $contacto = $rioTinto->contactos()->first();
        $dolar = $this->mon['DOLAR BILLETE BNA VENDEDOR'];

        $original = $this->consulta($rioTinto, [
            'tipo' => 'Cotizacion', 'fecha' => '2026-08-12',
            'contacto_id' => $contacto?->id, 'usuario_id' => $this->u['RIC']->id,
            'moneda_id' => $dolar, 'tipo_cambio' => 1412.00,
            'condicion_pago' => 'Credito a 60 dias',
            'ajuste_dif_cambio' => true, 'ajuste_dif_cambio_detalle' => 'A fecha de pago',
            'id_sistema' => 'C-2026-0812', 'estado' => 'Confirmada',
        ]);

        $lineas = [
            ['HASTELLOY C-276', 'BARRA REDONDA', '38.1 X 145 MM', 6, 142.00, 'HASTELLOY C-276 BAR RED 38.1 X 145MM'],
            ['AISI 316TI', 'BARRA', 'DIA 65 X 145 MM', 4, 56.58, 'AISI 316TI BARRA DIA 65 X 145MM'],
            ['NIQUEL 201', 'CAÑO', '4" SCH 40 X 3000 MM', 2, 1980.40, 'NIQUEL 201 CANO 4" SCH 40 X 3000 MM'],
            ['TITANIO GR2', 'BARRA', '50.0 X 125 MM', 3, 165.00, 'TITANIO GR2 BARRA 50.0 X 125 MM'],
        ];

        foreach ($lineas as $i => [$mat, $forma, $dim, $cant, $precio, $desc]) {
            $this->linea($original, $i + 1, [
                'material' => $mat, 'forma' => $forma, 'dimensiones' => $dim,
                'cantidad' => $cant, 'unidad' => 'UN', 'precio_unitario' => $precio,
                'descripcion' => $desc, 'igual' => true,
            ]);
        }

        $this->condiciones($original, ['1RA FILA X 1.10', 'Precios en Dolares Estadounidenses, mas IVA']);

        // Los cinco borradores copiados. Las líneas y los precios se copian;
        // la condición de pago sale de la ficha de cada empresa.
        $destinos = [
            'METALURGICA SILMAR DE BAIOCCO' => ['ajustes' => ['precio' => 148.00], 'estado' => 'Borrador'],
            'METALURGICA SALEM S.A.' => ['estado' => 'Borrador'],
            'METALURGICA CENTRO S.R.L.' => ['estado' => 'Borrador'],
            'METALURGICA DEL SUR' => ['estado' => 'Borrador'],
            'METALMECANICA ANDINA' => ['parcial' => true, 'estado' => 'Borrador'],
        ];

        foreach ($destinos as $nombre => $opciones) {
            $destino = Empresa::where('nombre', $nombre)->with('contactos', 'campos')->first();

            if (! $destino) {
                continue;
            }

            $borrador = $this->consulta($destino, [
                'tipo' => 'Cotizacion', 'fecha' => '2026-08-12',
                'contacto_id' => $destino->contactoPrincipal()?->id,
                'usuario_id' => $this->u['RIC']->id,
                'moneda_id' => $dolar, 'tipo_cambio' => 1412.00,
                'condicion_pago' => $destino->campos->firstWhere('titulo', 'Tipo de pago')?->valor,
                'lista_precios' => $destino->campos->firstWhere('titulo', 'Lista de precios')?->valor,
                'estado' => $opciones['estado'],
                'copiada_de_id' => $original->id,
            ]);

            foreach ($lineas as $i => [$mat, $forma, $dim, $cant, $precio, $desc]) {
                // La quinta quedó parcial: se le quitaron dos líneas.
                $quitada = ! empty($opciones['parcial']) && $i >= 2;

                $this->linea($borrador, $i + 1, [
                    'material' => $mat, 'forma' => $forma, 'dimensiones' => $dim,
                    'cantidad' => $cant, 'unidad' => 'UN',
                    'precio_unitario' => ($i === 0 && isset($opciones['ajustes']['precio']))
                        ? $opciones['ajustes']['precio']
                        : $precio,
                    'descripcion' => $desc, 'igual' => true, 'quitada' => $quitada,
                ]);
            }
        }
    }

    // ----------------------------------------------------------------- helpers

    private function consulta(Empresa $e, array $datos): Consulta
    {
        $consulta = Consulta::updateOrCreate(
            [
                'empresa_id' => $e->id,
                'tipo' => $datos['tipo'],
                'fecha' => $datos['fecha'],
                'usuario_id' => $datos['usuario_id'],
            ],
            $datos + ['validez_dias' => Consulta::VALIDEZ_POR_DEFECTO]
        );

        $consulta->fecha = Carbon::parse($datos['fecha']);
        $consulta->recalcularVencimiento();
        $consulta->save();

        return $consulta;
    }

    private function linea(Consulta $c, int $orden, array $d): void
    {
        $linea = new ConsultaLinea([
            'consulta_id' => $c->id,
            'orden' => $orden,
            'material_id' => isset($d['material']) ? Material::where('nombre', $d['material'])->value('id') : null,
            'forma_id' => isset($d['forma']) ? Forma::where('nombre', $d['forma'])->value('id') : null,
            'dimensiones' => $d['dimensiones'] ?? null,
            'diametro_mm' => $d['diametro_mm'] ?? null,
            'descripcion' => $d['descripcion'],
            'cantidad' => $d['cantidad'] ?? null,
            'unidad_venta_id' => isset($d['unidad']) ? $this->un[$d['unidad']] : null,
            'unidad_factura_id' => isset($d['unidad_factura']) ? $this->un[$d['unidad_factura']] : null,
            'factor_conversion' => $d['factor'] ?? null,
            'precio_unitario' => $d['precio_unitario'] ?? null,
            'precio_por_kilo' => $d['precio_por_kilo'] ?? null,
            'igual_a_lo_pedido' => $d['igual'] ?? true,
            'motivo_cambio' => $d['motivo'] ?? null,
            'quitada' => $d['quitada'] ?? false,
        ]);

        // Lo que había pedido el cliente, cuando no coincide con lo cotizado.
        if (! empty($d['pedido'])) {
            [$mat, $forma, $dim, $cant, $unidad] = $d['pedido'];
            $linea->pedido_material = $mat;
            $linea->pedido_forma = $forma;
            $linea->pedido_dimensiones = $dim;
            $linea->cantidad_pedida = $cant;
            $linea->unidad_pedida_id = $this->un[$unidad];
        }

        if (! empty($d['stock'])) {
            [$deposito, $colada] = $d['stock'];
            $linea->desde_stock = true;
            $linea->deposito = $deposito;
            $linea->colada = $colada;
        }

        $linea->recalcular();

        ConsultaLinea::updateOrCreate(
            ['consulta_id' => $c->id, 'orden' => $orden],
            $linea->attributesToArray()
        );
    }

    private function condiciones(Consulta $c, array $textos): void
    {
        foreach ($textos as $i => $texto) {
            ConsultaCondicion::updateOrCreate(
                ['consulta_id' => $c->id, 'texto' => $texto],
                ['orden' => $i + 1, 'origen' => 'Manual']
            );
        }

        // La línea de validez la arma el sistema con el número de validez_dias.
        ConsultaCondicion::updateOrCreate(
            ['consulta_id' => $c->id, 'origen' => 'Automatica'],
            ['texto' => "Validez de la oferta: {$c->validez_dias} dias", 'orden' => count($textos) + 1]
        );
    }

    private function observaciones(Consulta $c, array $filas): void
    {
        foreach ($filas as $i => [$fecha, $iniciales, $texto]) {
            Observacion::updateOrCreate(
                ['consulta_id' => $c->id, 'numero' => $i + 1],
                ['fecha' => $fecha, 'usuario_id' => $this->u[$iniciales]->id, 'texto' => $texto]
            );
        }
    }
}
