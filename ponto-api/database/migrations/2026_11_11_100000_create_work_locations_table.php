<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerca virtual: locais onde o ponto pode ser batido (matriz, filiais, obra...).
 * A restrição só vale quando companies.geofence_enabled = true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('geofence_enabled')->default(false)->after('require_location');
        });

        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('radius_m')->default(150);
            $table->timestamps();
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_locations');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('geofence_enabled'));
    }
};
