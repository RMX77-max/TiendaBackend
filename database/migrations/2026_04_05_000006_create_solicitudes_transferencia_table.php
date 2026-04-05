<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_transferencia', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $tabla->foreignId('usuario_solicitante_id')->constrained('usuarios')->cascadeOnDelete();
            $tabla->foreignId('sucursal_solicitante_id')->constrained('sucursales')->restrictOnDelete();
            $tabla->foreignId('sucursal_origen_id')->constrained('sucursales')->restrictOnDelete();
            $tabla->unsignedInteger('cantidad_solicitada');
            $tabla->unsignedInteger('cantidad_aprobada')->nullable();
            $tabla->text('detalle_solicitud')->nullable();
            $tabla->text('detalle_respuesta')->nullable();
            $tabla->string('estado', 40)->default('pendiente');
            $tabla->foreignId('usuario_revisor_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestamp('fecha_respuesta')->nullable();
            $tabla->timestamps();

            $tabla->index(['estado', 'sucursal_origen_id'], 'sol_trans_estado_origen_idx');
            $tabla->index(['sucursal_solicitante_id', 'created_at'], 'sol_trans_sucursal_fecha_idx');
            $tabla->index(['producto_id', 'estado'], 'sol_trans_producto_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_transferencia');
    }
};
