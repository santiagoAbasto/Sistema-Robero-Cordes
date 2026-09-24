<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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

        User::updateOrCreate(
            ['email' => 'roberto@cordes.com'],
            [
                'name' => 'Roberto Cordes',
                'role' => 'Administrador',
                'password' => 'cordes2026',
            ]
        );

        User::updateOrCreate(
            ['email' => 'juan@cordes.com'],
            [
                'name' => 'Juan Roberti',
                'role' => 'Ventas',
                'password' => 'cordes2026',
            ]
        );
    }
}
