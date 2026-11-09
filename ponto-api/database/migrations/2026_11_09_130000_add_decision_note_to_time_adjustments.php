<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_adjustments', function (Blueprint $table) {
            // Observação de quem aprovou/rejeitou (o colaborador vê no app).
            $table->string('decision_note', 300)->nullable()->after('decided_at');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('time_adjustments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropColumn('decision_note');
        });
    }
};
