# Configuração do servidor

Variáveis, certificado e checklist de produção. **Nada disto aparece na interface**: a tela mostra só o estado
(ex.: "Certificado de teste", "Sem certificado digital"), nunca nomes de variáveis, comandos ou erros internos.
Pendências e próximos passos: [ROADMAP.md](ROADMAP.md).

## Variáveis do `ponto-api/.env`

| Variável | Para quê | Exemplo / padrão |
| --- | --- | --- |
| `APP_ENV` / `APP_DEBUG` | Ambiente. **Em produção: `production` e `false`** — com debug ligado, as páginas de erro mostram detalhes internos | `local` / `true` (desenvolvimento) |
| `APP_URL` | Endereço do painel/API (links de e-mail, comprovante) | `http://localhost:8000` |
| `PWA_URL` | Endereço do app do colaborador; o domínio vira o `rp_id` da biometria | `http://localhost:5173` |
| `APP_TIMEZONE` | Fuso da hora do servidor (a marcação usa sempre essa hora) | `America/Bahia` |
| `DB_*` | Postgres | host `127.0.0.1`, porta `5433`, banco/usuário/senha `ponto` |
| `REDIS_*`, `QUEUE_CONNECTION`, `CACHE_STORE` | Fila (e-mails) e cache do contexto da batida | `redis` |
| `MAIL_*` | SMTP. Em desenvolvimento, Mailpit (`127.0.0.1:1025`, caixa em http://localhost:8025) | |
| `PONTO_OPERATOR_NAME` / `_CNPJ` / `_EMAIL` | Operador (LGPD) exibido na política de privacidade | |
| `PONTO_DEVELOPER_CNPJ` | CNPJ do desenvolvedor do REP-P (cabeçalho do AFD) | vazio = campo zerado no AFD |
| `PONTO_INPI_REGISTRATION` | Nº de registro do programa no INPI (17 posições, cabeçalho do AFD) | vazio = campo zerado no AFD |
| `PONTO_CERT_PATH` | Certificado A1 (`.pfx`) em `storage/app/private/` | `certs/ponto.pfx` |
| `PONTO_OPENSSL_BIN` | OpenSSL 3.2+ (assinatura CAdES-BES). No Windows: `C:/Program Files/Git/mingw64/bin/openssl.exe`. No Docker é forçado para `openssl` | `openssl` |
| `PONTO_TOKEN_IDLE_DAYS` | Dias sem uso até o token do app expirar | `30` |
| `SANCTUM_TOKEN_PREFIX` | Prefixo dos tokens (detecção de vazamento) | `ponto_` |
| `PONTO_WEBAUTHN_RP_ID` | Domínio da biometria (sem porta). Vazio = host do `PWA_URL` | |

## Variáveis do `ponto-pwa/.env`

| Variável | Para quê | Padrão |
| --- | --- | --- |
| `VITE_API_URL` | API. Vazio = mesmo endereço do app na porta 8000; aberto pelo IP da rede, troca `localhost` pelo IP sozinho | `http://localhost:8000/api` |
| `VITE_FEATURE_FACIAL` | Mostra a chave de reconhecimento facial (ainda não implementado — deixe `false`) | `false` |

## Certificado digital (AFD)

- **Produção:** e-CNPJ **A1 ICP-Brasil** do desenvolvedor, copiado para `storage/app/private/certs/ponto.pfx`. A senha **não** vai para o `.env`: o RH digita na tela a cada exportação.
- **Desenvolvimento:** `php artisan ponto:certificado-teste` gera um autoassinado (senha `teste123`; outra: `--senha=... --force`). Na tela aparece como "Certificado de teste".
- Assinatura **CAdES-BES** com OpenSSL 3.2+; sem ele, CMS destacado.

## Biometria do celular (WebAuthn)

- Só funciona em **HTTPS** (ou `localhost` no próprio aparelho). Pela rede local (`http://IP`) a opção aparece desabilitada.
- Testar num celular: publique o app com HTTPS (ou túnel HTTPS para `localhost:5173`) e ajuste `PWA_URL` (ou `PONTO_WEBAUTHN_RP_ID`).
- A credencial fica presa ao domínio: mudar o domínio do app exige cadastrar a biometria de novo.

## Checklist de produção

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`
- [ ] HTTPS no painel, na API e no app; `APP_URL` e `PWA_URL` com `https://`
- [ ] Cabeçalho CSP no app (`script-src 'self'`)
- [ ] Certificado ICP-Brasil instalado; `PONTO_DEVELOPER_CNPJ` e `PONTO_INPI_REGISTRATION` preenchidos
- [ ] Papel `app_user` no Postgres (o `REVOKE` em `time_entries`/`audit_logs` é aplicado nas migrations)
- [ ] `chrony` com `a.ntp.br`
- [ ] Backup diário com restore testado
- [ ] SMTP real em `MAIL_*`
- [ ] Nunca rodar `migrate --seed` (usuários de demonstração com senha `password`)

## Solução de problemas (desenvolvimento)

- **Docker Desktop fecha com erro em `dockerInference` ("Não é possível o acesso ao arquivo pelo sistema")**: feche o Docker, renomeie a pasta `%LOCALAPPDATA%\Docker\run` (ex.: `run.old`) e abra de novo.
- **Testes**: sempre no banco `ponto_test`. O `phpunit.xml` força o banco de teste e `tests/TestCase` bloqueia a suíte em qualquer banco que não termine em `_test` (o `RefreshDatabase` apaga o banco).
- **Painel 502 no Docker depois de recriar o `php`**: já resolvido no nginx (resolve o `php` pelo DNS do Docker); se aparecer, `docker compose restart nginx`.
