<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Apoio;

use Ravi\Core\Request;

/** Controlador de teste para handlers no formato [classe, método]. */
final class ControladorFalso
{
    public function mostrar(Request $request, string $id): string
    {
        return 'mostrar:' . $id;
    }

    public function listar(Request $request): array
    {
        return ['itens' => [1, 2, 3]];
    }
}
