<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_abonos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $tabla->foreignId('sucursal_id')->constrained('sucursales')->restrictOnDelete();
            $tabla->decimal('tipo_cambio_abono', 14, 6);
            $tabla->string('moneda_referencia', 10)->default('usd');
            $tabla->decimal('abono_usd', 14, 6)->default(0);
            $tabla->decimal('abono_bs', 14, 6)->default(0);
            $tabla->string('comprobante_path', 255)->nullable();
            $tabla->text('observaciones')->nullable();
            $tabla->date('fecha_abono')->nullable();
            $tabla->timestamps();

            $tabla->index(['compra_id', 'sucursal_id'], 'compra_abonos_compra_sucursal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_abonos');
    }
};
