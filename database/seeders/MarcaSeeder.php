<?php

namespace Database\Seeders;

use App\Models\Marca;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MarcaSeeder extends Seeder
{
    public function run(): void
    {
        $marcas = [
            'Asus',
            'Logitech',
            'Kingston',
            'HP',
            'Samsung',
            'AMD',
            'Intel',
            'Acer',
            'MSI',
            'Corsair',
            'Gigabyte',
            'Lenovo',
            'Dell',
        ];

        foreach ($marcas as $marca) {
            Marca::query()->updateOrCreate(
                ['nombre' => $marca],
                [
                    'slug' => Str::slug($marca),
                    'activa' => true,
                ],
            );
        }
    }
}
