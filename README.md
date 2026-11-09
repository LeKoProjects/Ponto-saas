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

```bash
# E-mails (comprovante, esqueci minha senha) caem no Mailpit: http://localhost:8025
docker compose up -d mailpit
php artisan queue:work            # os e-mails saem pela fila

# Certificado de TESTE para assinar o AFD (sem valor legal). Senha na exportação: teste123
php artisan ponto:certificado-teste
php artisan storage:link          # logo das empresas
```

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
| Um aparelho por colaborador | O app gera um ID próprio do aparelho (mantido após sair). No 1º login nele, o pedido vai ao RH (**Aparelhos** no painel). Liberar um aparelho desconecta os outros (tokens apagados). Pendente → só `/api/aparelho` e `/api/logout` respondem. Nada é excluído: `devices` + auditoria guardam o histórico, visível também no cadastro do funcionário. |
| Localização | Parâmetro da empresa em **Configurações** (dono/RH). Ativo: a batida só é aceita com latitude/longitude. Inativo: o app não pede e o servidor descarta coordenadas (LGPD). |
| NSR por empregador | `companies.next_nsr` com `SELECT … FOR UPDATE` dentro da transação. |
| Hash encadeado | `sha256(prev_hash \| employee_id \| nsr \| marked_at)`; comando `php artisan ponto:verificar-cadeia` roda todo dia às 02:00. |
| Hora | Sempre `now()` do servidor (`America/Bahia`); o horário enviado pelo cliente é ignorado. Na VPS, configure `chrony` com `a.ntp.br`. |
| Comprovante | Na tela da PWA e por e-mail (fila): data, hora, NSR, CNPJ, empregador, trabalhador, CPF e hash. |
| Auditoria | `audit_logs` grava antes/depois de toda ação do RH e do admin. |

## Segurança do acesso

| Item | Como |
| --- | --- |
| Token do app | Sanctum, prefixo `ponto_`, permissão `ponto` (só tokens do login do app valem na API), um por aparelho liberado |
| Expiração | Por inatividade: sem uso por `PONTO_TOKEN_IDLE_DAYS` (30) dias o app volta ao login; cada uso renova o prazo. `ponto:limpar-tokens` roda às 03:00 |
| Revogação imediata | Sair, RH desconectar/liberar outro aparelho, colaborador desativado e troca de senha apagam os tokens |
| Esqueci minha senha | Painel (`/esqueci-senha`) e app (`/api/esqueci-senha`). Link de 60 min, uso único, enviado pela fila; resposta igual exista ou não o e-mail; limite por IP e por e-mail. A troca derruba os tokens, mas o aparelho continua liberado |
| Armazenamento no app | O token fica no `localStorage`. Em produção, sirva a PWA com HTTPS e cabeçalho CSP restrito (`script-src 'self'`) |

## Arquivos fiscais (Portaria 671/2021)

Painel → **Arquivos fiscais** (dono/RH): baixa um `.zip` com o **AFD** (`.txt`, leiaute 003, ISO-8859-1, CR+LF) e a **assinatura destacada `.p7s`**.

- Registros: cabeçalho (tipo 1), marcações do REP-P (tipo 7, com hash SHA-256 encadeado) e trailer (tipo 9), mais a linha `ASSINATURA_DIGITAL_EM_ARQUIVO_P7S`.
- Assinatura **CAdES-BES** quando o OpenSSL do servidor é 3.2+ (imagem Docker), senão CMS destacado. Certificado A1 em `storage/app/private/certs/ponto.pfx` (`PONTO_CERT_PATH`). A **senha do certificado é digitada na tela a cada exportação** (POST, não vai na URL, não é salva nem registrada); senha errada fica na auditoria e há limite de 10 tentativas por minuto.
- Para valor legal: certificado **ICP-Brasil** (e-CNPJ A1) do desenvolvedor, `PONTO_DEVELOPER_CNPJ` e `PONTO_INPI_REGISTRATION` preenchidos.
- Pendente (validar com o especialista): registros tipo 2, 4, 5 e 6 com NSR próprio, **AEJ** e comprovante em PDF assinado (PAdES).

## Endpoints da API (PWA)

Todos via `POST`, autenticados com `Authorization: Bearer <token>`.

| Rota | Descrição |
| --- | --- |
| `/api/esqueci-senha` | `email` → envia o link de nova senha (sem autenticação) |
| `/api/login` | `email`, `password`, `device_id` (UUID do aparelho), `device_name` → `token` + `device` (status do vínculo) |
| `/api/aparelho` | status do aparelho do token (o app consulta enquanto aguarda o RH) |
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
- [x] Política de privacidade (LGPD), logo por empresa, esqueci minha senha, endurecimento do token
- [x] AFD com assinatura CAdES (.p7s)
- [ ] Sprint 6 — Deploy na VPS, backup testado (restore real), monitoramento, termos de uso
- [ ] Fase 2 — Anexo de atestado na solicitação, banco de horas, WhatsApp, cobrança recorrente, AEJ, registros 2/4/5/6 do AFD, comprovante PAdES, INPI e atestado técnico

## Limitações conhecidas do MVP

- Turnos que atravessam a meia-noite são agrupados pelo dia de cada marcação (escalas noturnas entram na fase 3).
- Banco de horas: tabela `hour_bank_ledger` criada, cálculo e lançamentos na fase 2.
- Feriados municipais (ex.: Salvador) devem ser cadastrados por empresa; o seeder traz nacionais e o 2 de Julho (BA).
- Perfis de acesso por coluna `role` (owner, rh, gestor, funcionario, super_admin) — `spatie/laravel-permission` só se surgirem permissões customizadas.
