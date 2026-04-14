<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('tipo_movimiento', 20);
            $table->decimal('monto', 14, 2);
            $table->string('moneda', 10);
            $table->decimal('tipo_cambio', 10, 2)->nullable();
            $table->string('concepto', 50);
            $table->string('referencia_tipo', 50)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->string('detalle', 255)->nullable();
            $table->dateTime('fecha_movimiento');
            $table->decimal('saldo_resultante', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['caja_id', 'fecha_movimiento']);
            $table->index(['referencia_tipo', 'referencia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};
