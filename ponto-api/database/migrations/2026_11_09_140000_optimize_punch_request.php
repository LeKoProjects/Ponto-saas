<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Otimização da batida:
 * - companies.last_hash: último hash da cadeia junto do next_nsr (lidos no
 *   mesmo SELECT … FOR UPDATE), sem consultar time_entries a cada batida;
 * - time_entries.request_id: UUID gerado pelo app a cada toque. Se a rede
 *   cair e o app reenviar, a mesma batida é devolvida em vez de duplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('last_hash', 64)->default(str_repeat('0', 64))->after('next_nsr');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->after('device');
            $table->unique(['company_id', 'request_id']);
        });

        // Backfill: último hash de cada empresa.
        DB::statement(<<<'SQL'
            UPDATE companies c SET last_hash = t.hash
            FROM (
                SELECT DISTINCT ON (company_id) company_id, hash
                FROM time_entries
                ORDER BY company_id, nsr DESC
            ) t
            WHERE t.company_id = c.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'request_id']);
            $table->dropColumn('request_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('last_hash');
        });
    }
};
