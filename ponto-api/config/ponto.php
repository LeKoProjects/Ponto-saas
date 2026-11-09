<?php

/*
 * Dados do SaaS (operador LGPD e desenvolvedor do REP-P).
 * Preencha no .env de produção — aparecem na política de privacidade e no AFD.
 */
return [
    'operator' => [
        'name' => env('PONTO_OPERATOR_NAME', 'Ponto SaaS'),
        'cnpj' => env('PONTO_OPERATOR_CNPJ', ''),
        'email' => env('PONTO_OPERATOR_EMAIL', 'privacidade@ponto.test'),
    ],

    'privacy' => [
        'version' => '1.0',
        'effective_at' => '2026-11-09',
    ],

    // Arquivo Fonte de Dados (Portaria 671/2021, Anexo V).
    'afd' => [
        'developer_document' => env('PONTO_DEVELOPER_CNPJ', env('PONTO_OPERATOR_CNPJ', '')), // CNPJ/CPF do desenvolvedor do REP-P
        'inpi_registration' => env('PONTO_INPI_REGISTRATION', ''), // nº de registro do programa no INPI (17 posições)
    ],

    // Certificado ICP-Brasil (A1, .pfx/.p12) do desenvolvedor para assinar o AFD (.p7s).
    // A senha NÃO fica no servidor: é digitada na tela Arquivos fiscais a cada exportação.
    'certificate' => [
        'path' => env('PONTO_CERT_PATH', 'certs/ponto.pfx'), // relativo a storage/app/private
    ],

    // OpenSSL de linha de comando (3.2+ assina em CAdES-BES). No Windows, aponte para o do Git:
    // PONTO_OPENSSL_BIN="C:/Program Files/Git/mingw64/bin/openssl.exe"
    'openssl_bin' => env('PONTO_OPENSSL_BIN', 'openssl'),

    // Token do app: expira se ficar sem uso por N dias (renova sozinho a cada uso).
    'token_idle_days' => (int) env('PONTO_TOKEN_IDLE_DAYS', 30),
];
