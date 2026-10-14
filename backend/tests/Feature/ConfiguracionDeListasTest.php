<?php

namespace Tests\Feature;

use App\Models\CondicionHabitual;
use App\Models\Forma;
use App\Models\User;
use App\Services\PlanillaXlsx;
use Database\Seeders\CalculadoraSeeder;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Configuracion (lista de ajustes del cliente, 14-10-2026): las listas, los
 * juegos de condiciones y la planilla de formas, editables por la empresa.
 */
class ConfiguracionDeListasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogosSeeder::class);
        $this->seed(CalculadoraSeeder::class);
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrador']));
    }

    public function test_los_motivos_de_no_cotizar_salen_de_la_tabla(): void
    {
        $motivos = $this->getJson('/api/config/listas')->assertOk()->json('motivos_no_cotiza');

        $this->assertContains('Por el plazo de entrega', array_column($motivos, 'nombre'));
        $this->getJson('/api/catalogos')->assertJsonFragment(['Por el plazo de entrega']);
    }

    public function test_agregar_corregir_apagar_y_borrar_un_item(): void
    {
        $id = $this->postJson('/api/config/listas/caracteristicas', ['nombre' => ' Rectificada h9 '])
            ->assertCreated()->json('id');
        $this->assertContains('Rectificada h9', $this->getJson('/api/catalogos')->json('caracteristicas'));

        // Repetido, aunque cambien las mayusculas: no.
        $this->postJson('/api/config/listas/caracteristicas', ['nombre' => 'RECTIFICADA H9'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');

        $this->putJson("/api/config/listas/caracteristicas/{$id}", ['nombre' => 'Rectificada h8'])->assertOk();
        $this->putJson("/api/config/listas/caracteristicas/{$id}", ['activo' => false])->assertOk();
        $this->assertNotContains('Rectificada h8', $this->getJson('/api/catalogos')->json('caracteristicas'));

        $this->deleteJson("/api/config/listas/caracteristicas/{$id}")->assertNoContent();
        $this->postJson('/api/config/listas/no-existe', ['nombre' => 'x'])->assertNotFound();
    }

    /** Los parrafos de Reventa los escribe la empresa: estaban vacios a proposito. */
    public function test_los_parrafos_de_reventa(): void
    {
        $id = $this->postJson('/api/config/condiciones', [
            'juego' => 'Reventa', 'titulo' => 'Origen', 'texto' => 'Material de reventa, con certificado del fabricante.',
        ])->assertCreated()->json('id');

        $this->assertTrue(CondicionHabitual::find($id)->por_defecto);
        $this->assertSame('Origen', CondicionHabitual::delJuego('Reventa')->first()->titulo);

        $this->postJson('/api/config/condiciones', ['juego' => 'Otro', 'texto' => 'x'])->assertStatus(422);
        $this->putJson("/api/config/condiciones/{$id}", ['texto' => 'Otro texto'])->assertOk()->assertJsonPath('texto', 'Otro texto');
        $this->deleteJson("/api/config/condiciones/{$id}")->assertNoContent();
    }

    public function test_bajar_las_formas_en_excel(): void
    {
        $respuesta = $this->get('/api/formas/planilla')->assertOk();
        $ruta = tempnam(sys_get_temp_dir(), 'f');
        file_put_contents($ruta, $respuesta->getContent());
        $filas = (new PlanillaXlsx)->leer($ruta);

        $this->assertSame(['Nombre', 'Medidas', 'Formula', 'Usa la tabla de caños', 'Como se escribe', 'Orden', 'Activa'], $filas[0]);
        $cano = collect($filas)->first(fn ($f) => $f[0] === 'CAÑO');
        $this->assertSame('Diametro exterior, Espesor de pared, Largo', $cano[1]);
        $this->assertSame('SI', $cano[3]);
    }

    /**
     * Bajarla y subirla sin tocar nada no cambia nada.
     *
     * Paso con la primera version: las formas del Access sin medidas volvian
     * con una lista vacia en vez de nada, y "Como se escribe" con un texto
     * vacio. 234 formas "corregidas" sin que nadie cambiara una celda.
     */
    public function test_ida_y_vuelta_sin_cambios(): void
    {
        Forma::create(['nombre' => 'CUPLA', 'activo' => true]);
        $bajada = tempnam(sys_get_temp_dir(), 'f').'.xlsx';
        file_put_contents($bajada, $this->get('/api/formas/planilla')->getContent());

        $this->post('/api/formas/planilla', ['archivo' => new UploadedFile($bajada, 'formas.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['creadas' => 0, 'actualizadas' => 0]);

        $this->assertNull(Forma::where('nombre', 'CUPLA')->first()->getRawOriginal('campos'));
    }

    /** La planilla como la guarda Excel: textos compartidos y una formula escrita con "=". */
    public function test_subir_la_planilla_crea_y_corrige(): void
    {
        $archivo = $this->planillaDeExcel([
            ['Nombre', 'Medidas', 'Formula', 'Usa la tabla de caños', 'Activa'],
            ['BARRA REDONDA', 'Diametro, Largo', '=PI()*POTENCIA(diameter;2)/4*length/1000', 'NO', 'SI'],
            ['CODO 30°', 'Diametro exterior, Espesor de pared', '', 'SI', 'SI'],
        ]);

        $this->post('/api/formas/planilla', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['creadas' => 1]);

        $codo = Forma::where('nombre', 'CODO 30°')->firstOrFail();
        $this->assertTrue($codo->usa_cano);
        $this->assertSame(['outer', 'wall'], array_column($codo->camposDelCalculo(), 'clave'));
        $this->assertSame('pi*pow(diameter,2)/4*length/1000', str_replace(' ', '', Forma::where('nombre', 'BARRA REDONDA')->value('expresion')));
    }

    /** Todo o nada: con una fila mal no se guarda ninguna, y se dice cual. */
    public function test_una_fila_mal_no_guarda_nada(): void
    {
        $antes = Forma::count();
        $archivo = $this->planillaDeExcel([
            ['Nombre', 'Medidas', 'Formula'],
            ['PIEZA NUEVA', 'Diametro, Largo', 'pi * pow(diameter, 2) / 4 * length / 1000'],
            ['OTRA PIEZA', 'Diametro', 'diameter * width'],
            ['TERCERA', 'Grosor', ''],
        ]);

        $errores = $this->post('/api/formas/planilla', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertStatus(422)->json('errors.archivo');

        $this->assertStringStartsWith('Fila 3 (OTRA PIEZA)', $errores[0]);
        $this->assertStringStartsWith('Fila 4 (TERCERA)', $errores[1]);
        $this->assertStringContainsString('Grosor', $errores[1]);
        $this->assertSame($antes, Forma::count());
    }

    /**
     * Un .xlsx como lo escribe Excel: los textos en sharedStrings —uno partido
     * en tramos con formato— y la formula en <f>.
     */
    private function planillaDeExcel(array $filas): UploadedFile
    {
        $textos = [];
        $hoja = '';

        foreach ($filas as $i => $fila) {
            $hoja .= '<row r="'.($i + 1).'">';
            foreach ($fila as $j => $valor) {
                $ref = chr(65 + $j).($i + 1);
                if (str_starts_with($valor, '=')) {
                    $hoja .= '<c r="'.$ref.'" t="e"><f>'.htmlspecialchars(substr($valor, 1)).'</f><v>#NAME?</v></c>';
                } elseif ($valor !== '') {
                    $textos[] = $valor;
                    $hoja .= '<c r="'.$ref.'" t="s"><v>'.(count($textos) - 1).'</v></c>';
                }
            }
            $hoja .= '</row>';
        }

        // El primer texto, partido en dos tramos como cuando tiene formato.
        $si = array_map(fn ($t, $k) => $k === 0
            ? '<si><r><rPr><b/></rPr><t>Nom</t></r><r><t>bre</t></r></si>'
            : '<si><t>'.htmlspecialchars($t).'</t></si>', $textos, array_keys($textos));

        $ruta = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Formas" sheetId="1" r:id="rId3"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId3" Type="worksheet" Target="/xl/worksheets/hoja7.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', $si).'</sst>');
        $zip->addFromString('xl/worksheets/hoja7.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$hoja.'</sheetData></worksheet>');
        $zip->close();

        return new UploadedFile($ruta, 'formas.xlsx', null, null, true);
    }
}
