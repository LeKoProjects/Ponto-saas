<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Trava de segurança: os testes usam RefreshDatabase, que APAGA o banco.
     * A verificação fica na criação da aplicação, que acontece ANTES do
     * RefreshDatabase rodar. Só aceita banco cujo nome termina em "_test"
     * (ex.: ponto_test), mesmo que Docker, CI ou .env apontem para outro.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $database = (string) $config->get('database.connections.'.$config->get('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Testes bloqueados: o banco \"{$database}\" não é de teste (precisa terminar em _test). Nada foi apagado.");
        }

        return $app;
    }
}
