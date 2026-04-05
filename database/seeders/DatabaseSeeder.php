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
            SucursalSeeder::class,
            MarcaSeeder::class,
            CategoriaSeeder::class,
        ]);

        User::query()->updateOrCreate(
            ['nombre_usuario' => 'gerente'],
            [
                'nombre' => 'Usuario',
                'apellido' => 'Gerente',
                'documento_identidad' => '10000001',
                'correo_electronico' => 'gerente@puntotecnologico.test',
                'telefono' => '70000001',
                'direccion' => 'Oficina central',
                'contrasena' => 'admin12345',
                'rol' => User::ROL_GERENTE,
                'sucursal' => 'RMA-ADM',
                'activo' => true,
            ],
        );
    }
}
