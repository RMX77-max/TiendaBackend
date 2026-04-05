<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_recepciones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $tabla->foreignId('compra_guia_id')->nullable()->constrained('compra_guias')->nullOnDelete();
            $tabla->date('fecha_recepcion');
            $tabla->string('estado_recepcion', 30)->default('parcial');
            $tabla->text('observaciones')->nullable();
            $tabla->boolean('ingresado_inventario')->default(false);
            $tabla->timestamp('fecha_ingreso_inventario')->nullable();
            $tabla->timestamps();

            $tabla->index(['compra_id', 'fecha_recepcion'], 'compra_recepciones_compra_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_recepciones');
    }
};
