<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Definido pelo RH: quando ativo, a batida só é aceita com GPS;
            // quando inativo, a localização nem é coletada (LGPD).
            $table->boolean('require_location')->default(false)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('require_location');
        });
    }
};
