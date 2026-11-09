<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo de aparelho: cada colaborador bate ponto em UM aparelho liberado pelo RH.
 * Nenhum registro é apagado — a tabela é o histórico de aparelhos do colaborador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid');                         // gerado e guardado pelo app no aparelho
            $table->string('name', 160);                  // "Pixel 8 · Android 14 · Chrome 131"
            $table->text('user_agent')->nullable();
            $table->string('status', 20)->default('pendente'); // pendente | aprovado | recusado | revogado
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 200)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'uuid']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('tokenable_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::dropIfExists('devices');
    }
};
