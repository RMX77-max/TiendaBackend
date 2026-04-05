<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_detalles', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('compra_id')->constrained('compras')->cascadeOnDelete();
            $tabla->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $tabla->string('detalle_texto', 255);
            $tabla->unsignedInteger('cantidad_pedida');
            $tabla->unsignedInteger('cantidad_recibida_acumulada')->default(0);
            $tabla->unsignedInteger('cantidad_pendiente')->default(0);
            $tabla->decimal('tipo_cambio_aplicado', 14, 6);
            $tabla->string('moneda_referencia', 10)->default('usd');
            $tabla->decimal('precio_unitario_usd', 14, 6)->default(0);
            $tabla->decimal('precio_unitario_bs', 14, 6)->default(0);
            $tabla->decimal('subtotal_usd', 14, 6)->default(0);
            $tabla->decimal('subtotal_bs', 14, 6)->default(0);
            $tabla->text('observaciones')->nullable();
            $tabla->string('estado_linea', 30)->default('pendiente');
            $tabla->timestamps();

            $tabla->index(['compra_id', 'estado_linea'], 'compra_detalles_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_detalles');
    }
};
