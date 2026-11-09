<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->time('start_time');                          // início previsto da jornada
            $table->time('end_time');                            // fim previsto
            $table->unsignedSmallInteger('daily_minutes');       // jornada diária (minutos)
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->json('weekdays');                            // ISO 1 (seg) … 7 (dom)
            $table->unsignedSmallInteger('tolerance_minutes')->default(10); // CLT art. 58 §1º
            $table->timestamps();
            $table->index('company_id');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('company_id');
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('cpf', 11);
            $table->string('registration', 30)->nullable(); // matrícula
            $table->date('admission_date');
            $table->foreignId('work_schedule_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'cpf']);
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('nsr');
            $table->timestampTz('marked_at');   // sempre hora do servidor
            $table->string('type', 20);         // entrada | intervalo_inicio | intervalo_fim | saida | extra
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('device')->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'nsr']);
            $table->index(['employee_id', 'marked_at']);
        });

        Schema::create('time_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('time_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('type', 20);                 // inclusao | desconsideracao
            $table->timestampTz('proposed_at')->nullable(); // horário incluído (tipo inclusao)
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status', 20)->default('pendente'); // pendente | aprovado | rejeitado
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'date']);
        });

        Schema::create('hour_bank_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->integer('minutes'); // + crédito / - débito
            $table->string('origin', 30); // calculo | compensacao | ajuste
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'date']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete(); // null = vale para todas
            $table->date('date');
            $table->string('name');
            $table->string('scope', 20)->default('nacional'); // nacional | estadual | municipal | empresa
            $table->timestamps();
            $table->index('date');
        });
    }

    public function down(): void
    {
        foreach (['holidays', 'audit_logs', 'hour_bank_ledger', 'time_adjustments', 'time_entries', 'employees', 'teams', 'work_schedules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
