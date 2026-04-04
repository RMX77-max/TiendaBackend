<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens_acceso', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('user_id')->constrained('usuarios')->cascadeOnDelete();
            $tabla->string('nombre', 100);
            $tabla->string('token', 64)->unique();
            $tabla->timestamp('ultimo_uso_en')->nullable();
            $tabla->timestamp('expira_en')->nullable();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens_acceso');
    }
};
