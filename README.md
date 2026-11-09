# Ponto SaaS — Controle de ponto para pequenas empresas

MVP de ponto eletrônico **REP-P** (Portaria MTP nº 671/2021) para empresas de 5 a 50 funcionários:
ponto pelo celular em 2 toques, painel "quem está trabalhando agora", espelho mensal pronto para a folha e exportação para o contador.

> ⚠️ **Conformidade:** a arquitetura segue os requisitos da Portaria 671/2021 (marcação imutável, NSR, comprovante, hash encadeado, hora do servidor), mas **antes de vender** é preciso validar com especialista e concluir: registro no INPI, atestado técnico, AFD/AEJ no leiaute oficial e assinatura ICP-Brasil (fase 2).

## Estrutura do monorepo

```
ponto-saas/
├── ponto-api/          Laravel 12 — API REST (Sanctum) + painel RH/gestor (Inertia + Vue 3 + Bootstrap)
├── ponto-pwa/          PWA do funcionário (Vue 3 + Vite PWA)
├── docker/             Dockerfile PHP, nginx, init do Postgres, script de backup
├── docker-compose.yml  nginx, php-fpm, postgres 16, redis, worker (Horizon), scheduler, pwa
└── .github/workflows/  CI: Pint + Pest no Postgres + build da PWA
```

## Stack

| Camada | Escolha |
| --- | --- |
| Backend | Laravel 12, Sanctum (token da PWA), Form Requests, Services, Resources |
| Banco | PostgreSQL 16 — multiempresa em banco único (`company_id` + global scope) |
| Fila/cache | Redis + Horizon (e-mail do comprovante) |
| Painel | Inertia + Vue 3 + Bootstrap 5, paginação de 20, modal de loading em toda requisição, ações via POST |
| PWA | Vue 3 + vite-plugin-pwa, botão único, espelho em calendário, solicitação de ajustes, GPS conforme configuração da empresa |
| PDF | barryvdh/laravel-dompdf |
| Testes | Pest |

## Rodando localmente

Pré-requisitos: PHP 8.2+ (com `pdo_pgsql`), Composer, Node 22, Docker.

```bash
# 1. Postgres e Redis
docker compose up -d postgres redis

# 2. API + painel
cd ponto-api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve            # http://localhost:8000

# 3. PWA (outro terminal)
cd ponto-pwa
cp .env.example .env
npm install
npm run dev                  # http://localhost:5173
```

O Postgres do container fica na porta **5433** do host (para não conflitar com um Postgres local). Altere com `DB_HOST_PORT`.

### Tudo em Docker

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan migrate --seed
docker compose --profile pwa up -d pwa
```

Painel em http://localhost:8080 · PWA em http://localhost:5173.

### Usuários de demonstração (senha `password`)

| E-mail | Perfil | Onde |
| --- | --- | --- |
| `admin@ponto.test` | Admin do SaaS | Painel → Empresas |
| `dono@clinica.test` | Dono da Clínica Bem Estar | Painel |
| `rh@clinica.test` | RH da clínica | Painel |
| `ana@clinica.test` | Funcionária | PWA |
| `dono@restaurante.test` | Dono do Restaurante Sabor Baiano | Painel |

## Testes

```bash
cd ponto-api
php artisan test      # usa o banco ponto_test (criado pelo init do container)
```

Cobrem os critérios de pronto do plano:

- **Sprint 1** — isolamento entre 2 empresas (listagem, URL direta, API, validação de chaves estrangeiras).
- **Sprint 3** — 100 marcações sem NSR duplicado, cadeia de hash íntegra, `UPDATE`/`DELETE` bloqueados no Postgres, detecção de adulteração, rate limit.
- **Sprint 4** — cálculo: normal, tolerância, atraso, hora extra 50%/100%, falta, noturno (22h–5h), dia incompleto.
- **Sprint 5** — espelho, PDF, CSV do contador e tratamento sem alterar a marcação original.

## Regras de conformidade implementadas

| Requisito | Como |
| --- | --- |
| Marcação imutável | Trigger `ponto_prevent_modification` bloqueia UPDATE/DELETE em `time_entries` e `audit_logs`; o model também recusa. Em produção, crie o papel `app_user` e rode as migrations: o `REVOKE` é aplicado automaticamente. |
| Correções | Só como **tratamento** (`time_adjustments`: inclusão, exclusão/desconsideração e alteração), com justificativa e auditoria. O colaborador solicita pela PWA (fica **pendente**); RH, dono ou o gestor da equipe aprova ou rejeita (com motivo) no painel. Lançamentos do RH já entram aprovados. O espelho mostra o original riscado. |
| Localização | Parâmetro da empresa em **Configurações** (dono/RH). Ativo: a batida só é aceita com latitude/longitude. Inativo: o app não pede e o servidor descarta coordenadas (LGPD). |
| NSR por empregador | `companies.next_nsr` com `SELECT … FOR UPDATE` dentro da transação. |
| Hash encadeado | `sha256(prev_hash \| employee_id \| nsr \| marked_at)`; comando `php artisan ponto:verificar-cadeia` roda todo dia às 02:00. |
| Hora | Sempre `now()` do servidor (`America/Bahia`); o horário enviado pelo cliente é ignorado. Na VPS, configure `chrony` com `a.ntp.br`. |
| Comprovante | Na tela da PWA e por e-mail (fila): data, hora, NSR, CNPJ, empregador, trabalhador, CPF e hash. |
| Auditoria | `audit_logs` grava antes/depois de toda ação do RH e do admin. |

## Endpoints da API (PWA)

Todos via `POST`, autenticados com `Authorization: Bearer <token>`.

| Rota | Descrição |
| --- | --- |
| `/api/login` | `email`, `password` → `token` |
| `/api/me` | dados do funcionário, marcações de hoje, próximo tipo, hora do servidor |
| `/api/ponto` | registra marcação, retorna comprovante — limite de 4/min. `latitude`/`longitude` obrigatórios se a empresa exigir localização |
| `/api/comprovante/{id}` | comprovante de uma marcação própria |
| `/api/historico` | espelho do mês (`month=YYYY-MM`) com batidas e solicitações de cada dia |
| `/api/ajustes` | solicita ajuste: `type` (`inclusao`, `desconsideracao`, `alteracao`), `date`/`time`/`time_entry_id` conforme o tipo, `reason` |
| `/api/espelho/pdf` | PDF do espelho do mês (`month=YYYY-MM`) |
| `/api/logout` | revoga o token |

## Roadmap

- [x] Sprint 1 — Base: Docker, Laravel + Postgres, Sanctum, multi-tenant, CI
- [x] Sprint 2 — Cadastros: empresas, usuários, equipes, jornadas, funcionários
- [x] Sprint 3 — Marcação: PWA, NSR, hash, comprovante, GPS opcional
- [x] Sprint 4 — Painel "agora" e cálculo diário
- [x] Sprint 5 — Espelho PDF/CSV, auditoria, tratamentos
- [x] Espelho em calendário na PWA + solicitação de ajuste pelo colaborador com aprovação no painel
- [x] Localização obrigatória configurável pelo RH
- [ ] Sprint 6 — Deploy na VPS, backup testado (restore real), monitoramento, termos e privacidade
- [ ] Fase 2 — Anexo de atestado na solicitação, banco de horas, WhatsApp, cobrança recorrente, AFD/AEJ assinados, INPI e atestado técnico

## Limitações conhecidas do MVP

- Turnos que atravessam a meia-noite são agrupados pelo dia de cada marcação (escalas noturnas entram na fase 3).
- Banco de horas: tabela `hour_bank_ledger` criada, cálculo e lançamentos na fase 2.
- Feriados municipais (ex.: Salvador) devem ser cadastrados por empresa; o seeder traz nacionais e o 2 de Julho (BA).
- Perfis de acesso por coluna `role` (owner, rh, gestor, funcionario, super_admin) — `spatie/laravel-permission` só se surgirem permissões customizadas.
