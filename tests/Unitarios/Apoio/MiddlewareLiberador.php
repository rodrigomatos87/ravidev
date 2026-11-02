<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Apoio;

use Ravi\Core\Request;
use Ravi\Core\Response;

/** Middleware de teste que deixa passar e conta quantas vezes rodou. */
final class MiddlewareLiberador
{
    public static int $chamadas = 0;

    public function handle(Request $request): ?Response
    {
        self::$chamadas++;
        return null;
    }
}
