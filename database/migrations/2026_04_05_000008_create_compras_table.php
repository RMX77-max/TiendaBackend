<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $tabla->date('fecha_pedido');
            $tabla->string('tipo_compra', 30);
            $tabla->string('nro_pedido', 80)->unique();
            $tabla->decimal('tipo_cambio_general', 14, 6)->default(9);
            $tabla->unsignedInteger('tiempo_entrega_dias')->default(0);
            $tabla->string('estado', 40)->default('borrador');
            $tabla->decimal('total_productos_usd', 14, 4)->default(0);
            $tabla->decimal('total_productos_bs', 14, 4)->default(0);
            $tabla->decimal('total_abonos_usd', 14, 4)->default(0);
            $tabla->decimal('total_abonos_bs', 14, 4)->default(0);
            $tabla->decimal('saldo_pendiente_usd', 14, 4)->default(0);
            $tabla->decimal('saldo_pendiente_bs', 14, 4)->default(0);
            $tabla->decimal('total_guias_usd', 14, 4)->default(0);
            $tabla->decimal('total_guias_bs', 14, 4)->default(0);
            $tabla->decimal('devolucion_pendiente_usd', 14, 4)->default(0);
            $tabla->decimal('devolucion_pendiente_bs', 14, 4)->default(0);
            $tabla->text('observaciones')->nullable();
            $tabla->timestamp('fecha_cierre')->nullable();
            $tabla->boolean('cerrado_forzado')->default(false);
            $tabla->timestamps();

            $tabla->index(['estado', 'fecha_pedido'], 'compras_estado_fecha_idx');
            $tabla->index(['tipo_compra', 'fecha_pedido'], 'compras_tipo_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
