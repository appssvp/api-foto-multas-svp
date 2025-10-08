<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Limpiar registros con ticket_id no numérico (los hashes viejos)
        DB::statement("DELETE FROM fotomultas WHERE ticket_id REGEXP '[^0-9]'");
        
        // Cambiar tipo de columna
        Schema::table('fotomultas', function (Blueprint $table) {
            $table->bigInteger('ticket_id')->unsigned()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fotomultas', function (Blueprint $table) {
            $table->string('ticket_id', 50)->change();
        });
    }
};