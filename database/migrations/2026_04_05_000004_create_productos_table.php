<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('modelo', 120);
            $tabla->foreignId('marca_id')->constrained('marcas')->cascadeOnDelete();
            $tabla->foreignId('categoria_id')->constrained('categorias')->cascadeOnDelete();
            $tabla->decimal('precio_unitario_usd', 12, 2)->default(0);
            $tabla->decimal('precio_unitario_bs', 12, 2)->default(0);
            $tabla->decimal('precio_mayorista_usd', 12, 2)->default(0);
            $tabla->decimal('precio_mayorista_bs', 12, 2)->default(0);
            $tabla->decimal('costo_usd', 12, 2)->default(0);
            $tabla->decimal('costo_bs', 12, 2)->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
