<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_cuotas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $tabla->unsignedInteger('nro_cuota');
            $tabla->date('fecha_vencimiento');
            $tabla->decimal('tipo_cambio_referencia', 14, 6);
            $tabla->decimal('monto_usd', 14, 6)->default(0);
            $tabla->decimal('monto_bs', 14, 6)->default(0);
            $tabla->decimal('saldo_pendiente_usd', 14, 6)->default(0);
            $tabla->decimal('saldo_pendiente_bs', 14, 6)->default(0);
            $tabla->string('estado', 30)->default('pendiente');
            $tabla->text('observaciones')->nullable();
            $tabla->timestamps();

            $tabla->unique(['compra_id', 'nro_cuota'], 'compra_cuotas_unica_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_cuotas');
    }
};
