<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Apoio;

use Ravi\Core\Application;
use Ravi\Core\Logger;
use Ravi\Core\Modulos;

/**
 * Zera os estados estáticos do núcleo entre testes (Modulos e Application são singletons
 * sem método público de reinício).
 */
final class EstadoGlobal
{
    public static function reiniciarModulos(): void
    {
        $classe = new \ReflectionClass(Modulos::class);
        $classe->setStaticPropertyValue('manifestos', []);
        $classe->setStaticPropertyValue('carregado', false);
    }

    /**
     * Instala uma Application mínima (sem bootstrap: não lê .env nem troca handlers de erro)
     * com o binding 'logger' apontando para um Logger em $pastaLogs.
     */
    public static function instalarAplicacaoComLogger(string $pastaLogs): Logger
    {
        $classe = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $classe->newInstanceWithoutConstructor();
        $classe->setStaticPropertyValue('instance', $app);

        $logger = new Logger($pastaLogs, 'testes');
        $app->bind('logger', fn () => $logger);

        return $logger;
    }

    public static function removerAplicacao(): void
    {
        (new \ReflectionClass(Application::class))->setStaticPropertyValue('instance', null);
    }
}
