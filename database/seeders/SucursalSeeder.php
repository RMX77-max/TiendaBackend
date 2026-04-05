<?php

namespace Database\Seeders;

use App\Models\Sucursal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SucursalSeeder extends Seeder
{
    public function run(): void
    {
        $sucursales = [
            'GOLD-MI PC',
            'GOLD-PUNTO TECNOLOGICO 2',
            'MEDRANO-OVERCITY 2',
            'MUNDO ELECTRONICO-OVERCITY 1',
            'RMA-ADM',
            'SUPERMALL-MACROCENTER',
            'TECNOCENTER-PUNTO TECNOLOGICO',
        ];

        foreach ($sucursales as $sucursal) {
            Sucursal::query()->updateOrCreate(
                ['nombre' => $sucursal],
                [
                    'codigo' => Str::upper(Str::slug($sucursal, '_')),
                    'activa' => true,
                ],
            );
        }
    }
}
