<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('modelo_snapshot', 150)->nullable();
            $table->string('marca_snapshot', 100)->nullable();
            $table->string('categoria_snapshot', 100)->nullable();
            $table->text('series_snapshot')->nullable();
            $table->integer('cantidad');
            $table->decimal('precio_lista_usd', 14, 2)->default(0);
            $table->decimal('precio_lista_bs', 14, 2)->default(0);
            $table->decimal('precio_mayorista_usd', 14, 2)->default(0);
            $table->decimal('precio_mayorista_bs', 14, 2)->default(0);
            $table->decimal('precio_unitario_usd', 14, 2)->default(0);
            $table->decimal('precio_unitario_bs', 14, 2)->default(0);
            $table->decimal('subtotal_usd', 14, 2)->default(0);
            $table->decimal('subtotal_bs', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_detalles');
    }
};
