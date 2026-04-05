<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategoriaSeeder extends Seeder
{
    public function run(): void
    {
        $categorias = [
            'Memorias USB',
            'Teclados',
            'Mouse',
            'Monitores',
            'Laptops',
            'Computadoras',
            'Tarjetas graficas',
            'Procesadores',
            'Placas madre',
            'Discos SSD',
            'Discos HDD',
            'Fuentes de poder',
            'Gabinetes',
            'Auriculares',
            'Impresoras',
        ];

        foreach ($categorias as $categoria) {
            Categoria::query()->updateOrCreate(
                ['nombre' => $categoria],
                [
                    'slug' => Str::slug($categoria),
                    'activa' => true,
                ],
            );
        }
    }
}
