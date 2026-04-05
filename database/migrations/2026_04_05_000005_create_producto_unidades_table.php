<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_unidades', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $tabla->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $tabla->string('numero_serie', 150)->nullable()->unique();
            $tabla->string('estado', 40)->default('disponible');
            $tabla->string('observaciones', 255)->nullable();
            $tabla->timestamp('fecha_ingreso')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();

            $tabla->index(['producto_id', 'sucursal_id']);
            $tabla->index(['sucursal_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_unidades');
    }
};
