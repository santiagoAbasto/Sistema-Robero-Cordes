<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CatalogosSeeder::class,
            // Las formulas de peso van despues de las formas: las completa.
            CalculadoraSeeder::class,
            // Los dos juegos de condiciones, importacion y stock. Sin esto una
            // instalacion nueva arranca sin las condiciones que la empresa
            // manda en cada oferta.
            CondicionesSeeder::class,
            IndiceTelefonicoSeeder::class,
        ]);

        /*
          Cada usuario nace con una clave al azar que nadie conoce, y a uno que
          ya existe no se le toca. Antes la clave estaba escrita aca, el codigo
          esta en GitHub y la pantalla de ingreso la traia precargada: con el
          enlace cualquiera entraba como administrador. Para entrar, ponele una:

              php artisan usuarios:clave <correo>

          (con DB_URL delante apunta al servidor en vez de a la base local).
        */
        foreach ([
            ['roberto@cordes.com', 'Roberto Cordes', 'Administrador'],
            ['juan@cordes.com', 'Juan Roberti', 'Ventas'],
        ] as [$mail, $nombre, $rol]) {
            User::firstOrCreate(
                ['email' => $mail],
                ['name' => $nombre, 'role' => $rol, 'password' => Str::password(32)],
            );
        }
    }
}
