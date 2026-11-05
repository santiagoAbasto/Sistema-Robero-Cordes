<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'iniciales', 'email', 'password', 'role', 'activo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Accessors appended to the model's array / JSON form.
     *
     * @var list<string>
     */
    protected $appends = ['initials'];

    /**
     * Con clave nueva, se cierran las sesiones que tenia abiertas.
     *
     * El token del navegador no vence solo: sin esto, el que quedo logueado con
     * la clave vieja seguia entrando como si nada. (Al dado de baja ya lo
     * frena AppServiceProvider.)
     */
    protected static function booted(): void
    {
        static::updated(function (User $usuario) {
            if ($usuario->wasChanged('password')) {
                $usuario->tokens()->delete();
            }
        });
    }

    /**
     * Initials derived from the user's name, e.g. "Juan Roberti" -> "JR".
     */
    public function getInitialsAttribute(): string
    {
        // Si cargaron las iniciales con las que firman (RIC, JD, RAC), mandan esas.
        if (! empty($this->iniciales)) {
            return $this->iniciales;
        }

        $parts = preg_split('/\s+/', trim($this->name ?? ''));
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: 'U';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function permiso(): HasOne
    {
        return $this->hasOne(Permiso::class);
    }

    /**
     * Si puede tocar cotizaciones.
     *
     * Sale de la pantalla "Quien ve que". Los administradores siempre pueden,
     * como los dueños ven todo siempre: no puede pasar que nadie llegue a una
     * cotizacion por una casilla mal marcada.
     *
     * Sin permiso cargado, puede: es lo que venia haciendo el sistema y sacarle
     * el acceso a todos de golpe seria peor que el problema.
     */
    public function puedeModificarCotizaciones(): bool
    {
        return $this->role === 'Administrador' || ($this->permiso?->puede_modificar ?? true);
    }

    /**
     * Si puede archivar y combinar fichas ("Archiva" en Quién ve qué).
     *
     * Combinar dos fichas mueve cotizaciones y contactos de una a otra y deja
     * archivada a la sobrante: es más que modificar un dato. A diferencia de
     * modificar, SIN permiso cargado no puede: se le da a quien corresponde. Los
     * administradores siempre.
     */
    public function puedeArchivar(): bool
    {
        return $this->role === 'Administrador' || ($this->permiso?->puede_archivar ?? false);
    }

    /**
     * Si ve los importes (precios, totales, lo facturado), según "Quién ve qué".
     *
     * Los administradores siempre. Sin permiso cargado, ve: es lo que viene
     * haciendo el sistema. Lo aplica el buscador único; el resto de las pantallas
     * todavía no lo controlan.
     */
    public function veImportes(): bool
    {
        return $this->role === 'Administrador' || ($this->permiso?->ve_importes ?? true);
    }
}
