<?php

namespace App\Models\Concerns;

use App\Models\HistorialCambio;
use Illuminate\Support\Facades\Auth;

/**
 * Control de cambios: se anota solo.
 *
 * Cada modelo que use este trait deja registrado qué campo cambió, quién lo
 * cambió, cuándo, y qué decía antes. Nadie tiene que acordarse de registrar
 * nada: pasa al guardar.
 */
trait RegistraCambios
{
    /** Campos que el sistema maneja solo y no aportan al historial. */
    private const CAMPOS_INTERNOS = [
        'updated_at', 'created_at', 'creada_por', 'modificada_por',
        'remember_token', 'password', 'orden', 'vence_el',
    ];

    public static function bootRegistraCambios(): void
    {
        static::created(function ($modelo) {
            $modelo->anotarCambio('Alta', null, null, $modelo->resumenParaHistorial());
        });

        static::updated(function ($modelo) {
            foreach ($modelo->getChanges() as $campo => $nuevo) {
                // Contabilidad interna: no le dice nada a quien lee el historial.
                if (in_array($campo, self::CAMPOS_INTERNOS, true)) {
                    continue;
                }

                $anterior = $modelo->getOriginal($campo);

                // Archivar no es lo mismo que modificar: se anota aparte.
                $accion = in_array($campo, ['activa', 'activo'], true) && ! $nuevo
                    ? 'Archivado'
                    : 'Modificacion';

                $modelo->anotarCambio($accion, $campo, $anterior, $nuevo);
            }
        });
    }

    /** Qué mostrar en el historial cuando se crea el registro. */
    public function resumenParaHistorial(): string
    {
        foreach (['nombre', 'razon_social', 'relacion', 'titulo', 'descripcion', 'texto', 'valor'] as $campo) {
            if (! empty($this->{$campo})) {
                return (string) $this->{$campo};
            }
        }

        return 'Se creo el registro';
    }

    /**
     * Sobre qué ficha se está anotando el cambio.
     * Las tablas que cuelgan de una empresa lo apuntan a la empresa, para que
     * el historial de la ficha muestre también lo que pasó con sus contactos.
     */
    public function empresaDelHistorial(): ?int
    {
        return $this->empresa_id ?? null;
    }

    public function anotarCambio(string $accion, ?string $campo, mixed $anterior, mixed $nuevo): void
    {
        HistorialCambio::create([
            'tabla' => $this->getTable(),
            'registro_id' => $this->getKey(),
            'empresa_id' => $this->empresaDelHistorial(),
            'campo' => $campo,
            'valor_anterior' => $this->paraTexto($anterior),
            'valor_nuevo' => $this->paraTexto($nuevo),
            'accion' => $accion,
            'usuario_id' => Auth::id(),
            'fecha' => now(),
        ]);
    }

    private function paraTexto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? 'Si' : 'No';
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        return mb_substr((string) $valor, 0, 500);
    }
}
