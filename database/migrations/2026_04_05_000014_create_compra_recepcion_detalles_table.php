<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_recepcion_detalles', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('recepcion_id')->constrained('compra_recepciones')->cascadeOnDelete();
            $tabla->foreignId('compra_detalle_id')->constrained('compra_detalles')->cascadeOnDelete();
            $tabla->unsignedInteger('cantidad_recibida');
            $tabla->text('observaciones')->nullable();
            $tabla->timestamps();

            $tabla->index(['recepcion_id', 'compra_detalle_id'], 'compra_recep_detalles_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_recepcion_detalles');
    }
};
