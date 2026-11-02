<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Apoio;

use Ravi\Core\Request;
use Ravi\Core\Response;

/** Middleware de teste que sempre barra a requisição com 403. */
final class MiddlewareBloqueador
{
    public function handle(Request $request): ?Response
    {
        return new Response('bloqueado', 403);
    }
}
