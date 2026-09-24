<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    public function permiso(): \Illuminate\Database\Eloquent\Relations\HasOne
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
}
