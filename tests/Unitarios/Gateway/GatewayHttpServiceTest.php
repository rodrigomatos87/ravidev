<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Gateway;

use PHPUnit\Framework\TestCase;
use Ravi\Gateway\GatewayHttpService;

/**
 * O bloco de identidade é o que faz a assinatura Max liberar Sonnet/Opus/Fable na Messages
 * API (sem ele, 429 imediato). Estes testes garantem que ele entra sempre como PRIMEIRO
 * bloco do `system`, sem perder nem duplicar o que o cliente mandou.
 */
final class GatewayHttpServiceTest extends TestCase
{
    public function testSemSystemGanhaSoAIdentidade(): void
    {
        $corpo = GatewayHttpService::comIdentidade(['model' => 'claude-opus-5-5', 'messages' => []]);

        self::assertSame([['type' => 'text', 'text' => GatewayHttpService::IDENTIDADE]], $corpo['system']);
    }

    public function testSystemEmStringViraBlocoDepoisDaIdentidade(): void
    {
        $corpo = GatewayHttpService::comIdentidade(['system' => 'Responda em uma palavra.']);

        self::assertCount(2, $corpo['system']);
        self::assertSame(GatewayHttpService::IDENTIDADE, $corpo['system'][0]['text']);
        self::assertSame(['type' => 'text', 'text' => 'Responda em uma palavra.'], $corpo['system'][1]);
    }

    public function testBlocosDoClientePreservamCacheControl(): void
    {
        $bloco = ['type' => 'text', 'text' => 'Regras da casa', 'cache_control' => ['type' => 'ephemeral']];
        $corpo = GatewayHttpService::comIdentidade(['system' => [$bloco]]);

        self::assertSame(GatewayHttpService::IDENTIDADE, $corpo['system'][0]['text']);
        self::assertSame($bloco, $corpo['system'][1]);
    }

    public function testNaoDuplicaQuandoOClienteJaComecaComAIdentidade(): void
    {
        $system = [
            ['type' => 'text', 'text' => GatewayHttpService::IDENTIDADE . "\n\nVocê é o copiloto."],
            ['type' => 'text', 'text' => 'Mais contexto'],
        ];
        $corpo = GatewayHttpService::comIdentidade(['system' => $system]);

        self::assertSame($system, $corpo['system']);
    }

    public function testSystemVazioNaoGeraBlocoVazio(): void
    {
        $corpo = GatewayHttpService::comIdentidade(['system' => '']);

        self::assertCount(1, $corpo['system']);
    }
}
