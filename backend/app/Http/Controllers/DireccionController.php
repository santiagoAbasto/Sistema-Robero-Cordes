<?php

namespace App\Http\Controllers;

use App\Models\Localidad;
use App\Models\Pais;
use App\Models\Provincia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Predictivos de dirección.
 *
 * Se escribe la calle y el sistema propone direcciones; al elegir una,
 * completa localidad, provincia, país y código postal.
 *
 * Usa la Places API (New) de Google. La vieja ya no se habilita en proyectos
 * nuevos, así que no tiene sentido sostener las dos.
 *
 * La consulta va por el servidor a propósito: así la credencial de Google
 * no viaja al navegador y no la puede usar cualquiera.
 */
class DireccionController extends Controller
{
    private const IDIOMA = 'es';

    private const PAIS = 'AR';   // Se busca dentro de Argentina.

    private const BASE = 'https://places.googleapis.com/v1';

    public function estaConfigurado(): bool
    {
        return filled(config('services.google.places_key'));
    }

    /** Las direcciones que se proponen mientras se escribe. */
    public function sugerencias(Request $request)
    {
        $datos = $request->validate([
            'q' => ['required', 'string', 'min:3'],
            's' => ['nullable', 'string', 'max:64'],
        ]);

        if (! $this->estaConfigurado()) {
            return response()->json(['activo' => false, 'sugerencias' => []]);
        }

        try {
            $respuesta = $this->aGoogle()->post(self::BASE.'/places:autocomplete', array_filter([
                'input' => $datos['q'],
                'languageCode' => self::IDIOMA,
                'includedRegionCodes' => [self::PAIS],
                // Agrupa lo tipeado con el detalle que viene después: Google lo
                // cobra como una sola búsqueda en vez de tecla por tecla.
                'sessionToken' => $datos['s'] ?? null,
            ]));

            if ($respuesta->failed()) {
                Log::warning('Places autocomplete '.$respuesta->status().': '.$respuesta->body());

                return response()->json(['activo' => true, 'sugerencias' => []]);
            }

            $sugerencias = collect($respuesta->json('suggestions') ?? [])
                // Las "queryPrediction" son búsquedas sueltas, no direcciones.
                ->filter(fn ($s) => filled(data_get($s, 'placePrediction.placeId')))
                ->take(6)
                ->map(fn ($s) => [
                    'id' => data_get($s, 'placePrediction.placeId'),
                    'texto' => data_get($s, 'placePrediction.text.text', ''),
                    'calle' => data_get($s, 'placePrediction.structuredFormat.mainText.text'),
                    'resto' => data_get($s, 'placePrediction.structuredFormat.secondaryText.text'),
                ])
                ->values();

            return response()->json(['activo' => true, 'sugerencias' => $sugerencias]);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron traer direcciones: '.$e->getMessage());

            return response()->json(['activo' => true, 'sugerencias' => []]);
        }
    }

    /**
     * Los datos de la dirección elegida, ya calzados con nuestras listas de
     * provincias y localidades. Lo que no encontramos queda en blanco.
     */
    public function detalle(Request $request)
    {
        $datos = $request->validate([
            // El id va en la URL: se acota a los caracteres que usa Google.
            'id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            's' => ['nullable', 'string', 'max:64'],
        ]);

        if (! $this->estaConfigurado()) {
            return response()->json(['message' => 'Los predictivos no estan configurados.'], 422);
        }

        try {
            $respuesta = $this
                // Sin la máscara Google cobra la ficha completa del lugar.
                ->aGoogle(['X-Goog-FieldMask' => 'addressComponents,formattedAddress'])
                ->get(self::BASE.'/places/'.$datos['id'], array_filter([
                    'languageCode' => self::IDIOMA,
                    'sessionToken' => $datos['s'] ?? null,
                ]));

            if ($respuesta->failed()) {
                Log::warning('Places details '.$respuesta->status().': '.$respuesta->body());

                return response()->json(['message' => 'No pudimos traer los datos de esa direccion.'], 502);
            }

            $componentes = collect($respuesta->json('addressComponents') ?? []);

            $buscar = fn (string $tipo) => $componentes
                ->first(fn ($c) => in_array($tipo, $c['types'] ?? [], true));

            $calle = $buscar('route')['longText'] ?? null;
            $numero = $buscar('street_number')['longText'] ?? null;
            $localidad = $buscar('locality')['longText']
                ?? $buscar('administrative_area_level_2')['longText']
                ?? null;
            $provincia = $buscar('administrative_area_level_1')['longText'] ?? null;
            $pais = $buscar('country')['longText'] ?? null;
            $cp = $buscar('postal_code')['longText'] ?? null;

            // Se buscan en nuestras listas; lo que no está queda en blanco y se
            // elige a mano. No creamos provincias ni localidades solas.
            $provinciaId = $provincia
                ? Provincia::where('nombre', 'like', $provincia)->value('id')
                : null;

            return response()->json([
                'direccion' => trim(implode(' ', array_filter([$calle, $numero]))) ?: null,
                'codigo_postal' => $cp,
                'provincia_id' => $provinciaId,
                'provincia_nombre' => $provincia,
                'localidad_id' => $this->buscarLocalidad($localidad, $provinciaId),
                'localidad_nombre' => $localidad,
                'pais_id' => $pais ? Pais::where('nombre', 'like', $pais)->value('id') : null,
                'pais_nombre' => $pais,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo traer el detalle de la direccion: '.$e->getMessage());

            return response()->json(['message' => 'No pudimos traer los datos de esa direccion.'], 502);
        }
    }

    /**
     * La localidad de nuestra lista, buscada dentro de la provincia que ya
     * calzó. Sin eso "Córdoba" o "Santa Fe" podrían pegarle a la ciudad
     * equivocada, porque varias provincias tienen una ciudad con su nombre.
     */
    private function buscarLocalidad(?string $nombre, ?int $provinciaId): ?int
    {
        if (! $nombre) {
            return null;
        }

        // Google llama "Buenos Aires" a la localidad de cualquier direccion de
        // Capital. Nosotros la tenemos como C.A.B.A.
        if ($provinciaId === $this->idCaba() && mb_strtolower($nombre) === 'buenos aires') {
            $nombre = 'C.A.B.A.';
        }

        return Localidad::where('nombre', 'like', $nombre)
            ->when($provinciaId, fn ($q) => $q->where('provincia_id', $provinciaId))
            ->value('id');
    }

    private function idCaba(): ?int
    {
        return Provincia::where('nombre', 'Ciudad Autónoma de Buenos Aires')->value('id');
    }

    /** @param  array<string, string>  $encabezados */
    private function aGoogle(array $encabezados = []): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(10)->withHeaders(array_merge(
            ['X-Goog-Api-Key' => config('services.google.places_key')],
            $encabezados,
        ));
    }
}
