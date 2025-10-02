<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('fotomultas', function (Blueprint $table) {
            $table->string('ticket_id', 50)->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('fotomultas', function (Blueprint $table) {
            $table->string('ticket_id', 20)->nullable()->change();
        });
    }
};