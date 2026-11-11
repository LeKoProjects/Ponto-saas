# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## Project Overview

Ponto SaaS — REP-P electronic time-tracking for small businesses (Portaria MTP nº 671/2021). Multi-tenant monorepo with two apps:

- **`ponto-api/`** — Laravel 12 backend: REST API (Sanctum) + RH/admin panel (Inertia + Vue 3 + Bootstrap 5)
- **`ponto-pwa/`** — Employee-facing PWA (Vue 3 + Vite PWA)

## Common Commands

### API (ponto-api/)

```bash
php artisan serve                  # dev server http://localhost:8000
php artisan test                   # run all Pest tests (uses ponto_test DB)
php artisan test --filter=SomeName # run a single test
./vendor/bin/pint                  # lint (Laravel Pint)
npm run dev                        # Vite watch for Inertia/Vue assets
npm run build                      # production build

# Artisan utilities
php artisan ponto:verificar-cadeia  # verify hash chain integrity
php artisan ponto:certificado-teste # generate a test signing certificate
php artisan storage:link            # expose company logos publicly
```

### PWA (ponto-pwa/)

```bash
npm run dev    # http://localhost:5173
npm run build
```

### Infrastructure

```bash
docker compose up -d postgres redis   # just the dependencies
docker compose up -d mailpit          # e-mail testing at http://localhost:8025
php artisan queue:work                # process email queue (receipts, password reset)

# Full Docker setup
docker compose up -d --build
docker compose exec php php artisan migrate --seed
docker compose --profile pwa up -d pwa
```

Postgres runs on host port **5433** (not 5432) to avoid conflicts.

## Architecture

### Multi-tenancy

Single database, all tenant-owned models use `company_id`. The `BelongsToCompany` trait (`app/Models/Concerns/BelongsToCompany.php`) adds a global scope that automatically filters all queries by `Tenant::id()`. The `Tenant` singleton (`app/Support/Tenant.php`) is populated by `SetTenant` middleware from the authenticated user. Console commands and jobs have no tenant set by default — use `Tenant::run($companyId, fn() => ...)` explicitly.

### Immutable Time Entries

`time_entries` are insert-only. The `TimeEntry` model throws `LogicException` on update/delete, and a Postgres trigger (`ponto_prevent_modification`) enforces this at the DB level. Corrections go through `time_adjustments` (pending → approved by RH/owner/team manager), never modifying originals.

### Hash Chain (REP-P compliance)

Each `TimeEntry` stores `sha256(prev_hash | employee_id | nsr | marked_at_iso)`. NSR sequence per company uses `SELECT … FOR UPDATE`. The chain is verified nightly via `ponto:verificar-cadeia`.

### User Roles

`users.role`: `super_admin` (SaaS admin), `owner`, `rh`, `gestor`, `funcionario`. Enforced by `EnsureRole` middleware. `gestor` can only see their own teams.

### Services Layer

Business logic is in `app/Services/`:

- `TimeEntryService` — punch registration, NSR assignment, hash computation
- `TimeCalculationService` — pure function for daily time calculation (normal, overtime, delay, night shift, holiday)
- `MonthlyReportService` — timesheet with adjustments applied
- `AfdService` + `SignatureService` — AFD file generation (layout 003, ISO-8859-1) with CAdES-BES detached signature
- `AuditLogger` — before/after snapshots on every RH/admin action
- `DeviceService` — device approval flow; approving one device revokes all others

### API Conventions

All PWA API calls use `POST` (including reads). Endpoints require `Authorization: Bearer <token>` with ability `ponto`. The `EnsureDeviceApproved` middleware blocks unapproved devices except on `/aparelho` and `/logout`.

### PWA Structure

- `src/api.js` — HTTP client, token stored in `localStorage` as `ponto_token`
- `src/session.js` — employee profile cached locally; `/me` refreshes in background
- `src/device.js` — device UUID (never cleared), device registration
- `src/geo.js` — GPS wrapper
- `src/theme.js` — dark mode / system theme
- Views: `LoginView`, `DeviceGateView`, `PunchView`, `HistoryView`, `SettingsView`

### AFD Export

Painel → **Arquivos fiscais**: generates a `.zip` with the AFD `.txt` and detached `.p7s` signature. The certificate password is entered at export time (never stored). Certificate at `storage/app/private/certs/ponto.pfx` (`PONTO_CERT_PATH`). Uses CAdES-BES if OpenSSL ≥ 3.2, otherwise CMS.

## Test Coverage

Tests are in `ponto-api/tests/` using Pest and require the `ponto_test` database (created by the Docker init script). Key test areas: multi-tenant isolation, NSR sequence, hash chain integrity, DB-level immutability, rate limits, time calculation rules (tolerances, overtime, night shift), timesheet/PDF/CSV generation.

## Demo Users (password: `password`)

| Email | Role |
|---|---|
| `admin@ponto.test` | SaaS super_admin |
| `dono@clinica.test` | Company owner |
| `rh@clinica.test` | RH |
| `ana@clinica.test` | Employee (PWA) |
