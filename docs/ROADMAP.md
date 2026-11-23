# Roadmap e pendências

Tudo o que está **planejado, pendente ou é próximo passo** fica aqui — **nunca na interface** do painel ou do app.
Na tela aparece só o que já funciona. Configuração do servidor: [CONFIGURACAO.md](CONFIGURACAO.md).

Legenda: 🔴 bloqueia a venda/uso com valor legal · 🟡 importante · 🟢 melhoria

## 1. Conformidade (Portaria MTP nº 671/2021)

| Item | Situação | Prioridade |
| --- | --- | --- |
| Validação da arquitetura com especialista em REP-P | Pendente | 🔴 |
| Registro do programa no **INPI** e preencher `PONTO_INPI_REGISTRATION` | Pendente — sem ele o cabeçalho do AFD sai com o campo zerado | 🔴 |
| **Atestado técnico** e termo de responsabilidade do REP-P | Pendente | 🔴 |
| Certificado **ICP-Brasil** (e-CNPJ A1) do desenvolvedor + `PONTO_DEVELOPER_CNPJ` | Pendente — hoje há só certificado de teste (sem validade jurídica) | 🔴 |
| AFD: registros **tipo 2** (empresa), **4** (ajuste de relógio), **5** (empregado) e **6** (eventos sensíveis) com NSR próprio | Pendente — hoje: tipos 1, 7 e 9 | 🔴 |
| AFD: confirmar a fórmula do hash do registro tipo 7 com o especialista | Pendente | 🔴 |
| **AEJ** (Arquivo Eletrônico de Jornada): jornada contratual, marcações tratadas, ausências e banco de horas | Pendente | 🔴 |
| **Comprovante em PDF assinado** (PAdES) a cada marcação | Pendente — hoje: comprovante na tela e por e-mail | 🟡 |
| Confirmar se **exigir GPS** e a **cerca virtual** são compatíveis com "não restringir a marcação" para cada caso (equipes externas não devem ficar presas a um local) | Pendente | 🟡 |
| Revisão jurídica da **política de privacidade** (texto-base) e **termos de uso** | Pendente | 🟡 |

## 2. Produto

| Item | Situação | Prioridade |
| --- | --- | --- |
| **Reconhecimento facial** na batida | Planejado. A chave existe no app, mas fica **escondida** (`VITE_FEATURE_FACIAL=false`); quando ligada, é exclusiva com a biometria. Decidir entre selfie comparada com foto cadastrada (dado sensível na LGPD, exige consentimento e guarda de fotos) ou desbloqueio facial do próprio celular (WebAuthn, sem foto) | 🟡 |
| Biometria do celular: teste em **celular real com HTTPS** | Pendente — implementada e testada com autenticador simulado; precisa de domínio HTTPS | 🟡 |
| Opção de a **empresa exigir** biometria de todos | Planejado — hoje cada colaborador liga a sua | 🟢 |
| Biometria **só digital** (sem PIN/padrão do celular) | Só com app nativo (Capacitor + BiometricPrompt) | 🟢 |
| **Banco de horas** (tabela `hour_bank_ledger` já criada) | Planejado | 🟡 |
| Anexo de **atestado** na solicitação de ajuste | Planejado | 🟡 |
| **Tolerância configurável do intervalo** no relatório (hoje regra estrita da CLT: 57 min = curto) | Planejado | 🟢 |
| Mensagem do limite de batidas com **contagem regressiva** / limite configurável (hoje 4 por minuto) | Planejado | 🟢 |
| **Escalas noturnas**: turnos que atravessam a meia-noite (hoje agrupados pelo dia de cada marcação) | Planejado | 🟡 |
| Cadastro de **feriados municipais** pelo painel (hoje: seeder com nacionais e 2 de Julho/BA) | Planejado | 🟢 |
| Nome comercial **PontoFácil** no painel e no app (hoje "Ponto SaaS") | A decidir | 🟢 |
| Relatório "fora do local" na demonstração: seeder com batidas com coordenadas e locais cadastrados | Planejado | 🟢 |
| Notificações por **WhatsApp** | Planejado | 🟢 |
| **Cobrança recorrente** dos planos | Planejado | 🟡 |
| **App Android** na Play Store: Bubblewrap (TWA) — exige HTTPS e Digital Asset Links; Capacitor se precisar de recursos nativos | Planejado | 🟢 |
| Permissões customizadas (`spatie/laravel-permission`) se os 5 perfis fixos não bastarem | Só se necessário | 🟢 |

## 3. Infraestrutura e operação

| Item | Situação | Prioridade |
| --- | --- | --- |
| Deploy na VPS com **HTTPS** (painel, API e app) | Pendente | 🔴 |
| Produção com `APP_DEBUG=false` e `APP_ENV=production` (com debug ligado, erros mostram detalhes internos) | Pendente | 🔴 |
| **Backup** diário testado com restore real (`docker/backup/pg_backup.sh`) | Pendente | 🔴 |
| Monitoramento e alertas (cadeia de hash, fila, erros) | Pendente | 🟡 |
| Cabeçalho **CSP** restrito no app (`script-src 'self'`) — o token fica no `localStorage` | Pendente | 🟡 |
| Relógio do servidor sincronizado (`chrony` com `a.ntp.br`) | Pendente | 🟡 |
| Papel `app_user` no Postgres (o `REVOKE` de UPDATE/DELETE em `time_entries` é aplicado nas migrations) | Pendente | 🟡 |
| Banco de desenvolvimento na **porta 5432 com senha 12345678** (pedido em aberto; hoje: container na 5433, usuário/senha `ponto`) | Aguardando confirmar se a senha é do usuário `postgres` do Postgres do Windows | 🟢 |

## Feito

Base (Docker, Laravel + Postgres, Sanctum, multiempresa, CI) · cadastros · marcação pela PWA com NSR, hash e comprovante · painel "agora" e cálculo diário ·
espelho PDF/CSV, auditoria e tratamentos · espelho em calendário e ajustes pelo colaborador · localização obrigatória configurável · cerca virtual ·
um aparelho por colaborador liberado pelo RH · política de privacidade · logo por empresa · esqueci minha senha · endurecimento do token ·
AFD com assinatura CAdES (senha digitada na exportação) · layout de computador e modo escuro · biometria do celular (WebAuthn) ·
acesso pela rede local · relatórios do RH (intervalo, fora do local, a conferir, resumo, atrasos, extras, ajustes).
