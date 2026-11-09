<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 14)->unique();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('plan', 20)->default('essencial'); // essencial | profissional | empresa
            $table->string('status', 20)->default('active');  // active | blocked
            $table->string('timezone', 64)->default('America/Bahia');
            $table->unsignedBigInteger('next_nsr')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
