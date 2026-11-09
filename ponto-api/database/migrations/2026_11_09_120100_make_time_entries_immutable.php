<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Portaria MTP 671/2021: marcações não podem ser editadas nem excluídas.
 * Correções acontecem apenas como tratamento (time_adjustments), preservando o original.
 *
 * O trigger vale para qualquer usuário do banco (inclusive o dono da tabela).
 * Se existir o papel "app_user" (usuário da aplicação em produção), também
 * revogamos UPDATE/DELETE como segunda camada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ponto_prevent_modification() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Tabela % aceita somente INSERT (Portaria 671/2021)', TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER time_entries_immutable
                BEFORE UPDATE OR DELETE ON time_entries
                FOR EACH ROW EXECUTE FUNCTION ponto_prevent_modification();

            CREATE TRIGGER audit_logs_immutable
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION ponto_prevent_modification();

            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_user') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON time_entries, audit_logs FROM app_user;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS time_entries_immutable ON time_entries;
            DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs;
        SQL);
    }
};
