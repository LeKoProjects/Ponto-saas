<?php

namespace App\Support;

/**
 * Empresa (tenant) da requisição atual. Preenchida pelo middleware SetTenant
 * a partir do usuário autenticado. Sem tenant (console, jobs, super admin)
 * nenhum filtro é aplicado — use forCompany() explicitamente nesses casos.
 */
class Tenant
{
    private ?int $companyId = null;

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function check(): bool
    {
        return $this->companyId !== null;
    }

    /** Executa um callback no contexto de outra empresa (jobs, comandos). */
    public function run(int $companyId, callable $callback): mixed
    {
        $previous = $this->companyId;
        $this->companyId = $companyId;

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }
}
