<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ControladorCatalogos extends Controller
{
    public function obtenerCatalogosProductos(): JsonResponse
    {
        return response()->json([
            'sucursales' => $this->formatearSucursales(
                Sucursal::query()->where('activa', true)->orderBy('nombre')->get()
            ),
            'marcas' => $this->formatearMarcas(
                Marca::query()->where('activa', true)->orderBy('nombre')->get()
            ),
            'categorias' => $this->formatearCategorias(
                Categoria::query()->where('activa', true)->orderBy('nombre')->get()
            ),
        ]);
    }

    public function registrarMarca(Request $solicitud): JsonResponse
    {
        $datos = $solicitud->validate([
            'nombre' => ['required', 'string', 'max:100', 'unique:marcas,nombre'],
        ]);

        $marca = Marca::query()->create([
            'nombre' => trim($datos['nombre']),
            'slug' => Str::slug($datos['nombre']),
            'activa' => true,
        ]);

        return response()->json([
            'message' => 'Marca registrada correctamente.',
            'marca' => $this->formatearMarca($marca),
        ], 201);
    }

    public function registrarCategoria(Request $solicitud): JsonResponse
    {
        $datos = $solicitud->validate([
            'nombre' => ['required', 'string', 'max:100', 'unique:categorias,nombre'],
        ]);

        $categoria = Categoria::query()->create([
            'nombre' => trim($datos['nombre']),
            'slug' => Str::slug($datos['nombre']),
            'activa' => true,
        ]);

        return response()->json([
            'message' => 'Categoria registrada correctamente.',
            'categoria' => $this->formatearCategoria($categoria),
        ], 201);
    }

    public function listarSucursales(): JsonResponse
    {
        $sucursales = Sucursal::query()
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'sucursales' => $this->formatearSucursales($sucursales),
        ]);
    }

    protected function formatearSucursales(iterable $sucursales): array
    {
        return collect($sucursales)
            ->map(fn (Sucursal $sucursal) => [
                'id' => $sucursal->id,
                'value' => $sucursal->nombre,
                'label' => $sucursal->nombre,
                'codigo' => $sucursal->codigo,
                'activa' => $sucursal->activa,
            ])
            ->values()
            ->all();
    }

    protected function formatearMarcas(iterable $marcas): array
    {
        return collect($marcas)
            ->map(fn (Marca $marca) => $this->formatearMarca($marca))
            ->values()
            ->all();
    }

    protected function formatearMarca(Marca $marca): array
    {
        return [
            'id' => $marca->id,
            'value' => $marca->id,
            'label' => $marca->nombre,
            'nombre' => $marca->nombre,
            'slug' => $marca->slug,
            'activa' => $marca->activa,
        ];
    }

    protected function formatearCategorias(iterable $categorias): array
    {
        return collect($categorias)
            ->map(fn (Categoria $categoria) => $this->formatearCategoria($categoria))
            ->values()
            ->all();
    }

    protected function formatearCategoria(Categoria $categoria): array
    {
        return [
            'id' => $categoria->id,
            'value' => $categoria->id,
            'label' => $categoria->nombre,
            'nombre' => $categoria->nombre,
            'slug' => $categoria->slug,
            'activa' => $categoria->activa,
        ];
    }
}
