<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_unidades', function (Blueprint $tabla) {
            $tabla->text('observaciones')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('producto_unidades', function (Blueprint $tabla) {
            $tabla->string('observaciones', 255)->nullable()->change();
        });
    }
};
