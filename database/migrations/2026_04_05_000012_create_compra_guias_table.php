<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_guias', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $tabla->string('foto_path', 255)->nullable();
            $tabla->date('fecha_registro');
            $tabla->decimal('monto_bs', 14, 4)->default(0);
            $tabla->string('estado', 30)->default('en_transito');
            $tabla->boolean('pagado')->default(false);
            $tabla->text('observaciones')->nullable();
            $tabla->timestamps();

            $tabla->index(['compra_id', 'estado'], 'compra_guias_compra_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_guias');
    }
};
