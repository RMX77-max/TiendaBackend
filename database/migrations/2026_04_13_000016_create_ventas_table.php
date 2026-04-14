<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->string('nro_venta', 50)->unique();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('caja_id')->constrained('cajas')->cascadeOnDelete();
            $table->string('estado', 30)->default('confirmada');
            $table->string('moneda', 10);
            $table->decimal('tipo_cambio', 10, 2)->nullable();
            $table->string('tipo_item', 100)->nullable();
            $table->string('cliente_nombre', 150)->nullable();
            $table->string('cliente_telefono', 40)->nullable();
            $table->date('cliente_fecha_nacimiento')->nullable();
            $table->decimal('subtotal_usd', 14, 2)->default(0);
            $table->decimal('subtotal_bs', 14, 2)->default(0);
            $table->decimal('total_usd', 14, 2)->default(0);
            $table->decimal('total_bs', 14, 2)->default(0);
            $table->string('observaciones', 255)->nullable();
            $table->dateTime('fecha_venta');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
