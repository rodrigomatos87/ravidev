<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Servicos;

use PHPUnit\Framework\TestCase;
use Ravi\Services\GitHubService;

final class GitHubServiceTest extends TestCase
{
    public function testDescricaoDoRepositorioSaiNumaLinhaSemCaracteresDeControle(): void
    {
        $saida = GitHubService::descricaoRepositorio("Projeto público\n\nSepara o que   publico\tdo privado\r\n");
        $this->assertSame('Projeto público Separa o que publico do privado', $saida);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $saida);
    }

    public function testDescricaoLongaEhCortadaNoLimiteDoGitHub(): void
    {
        $saida = GitHubService::descricaoRepositorio(str_repeat('a', 400));
        $this->assertSame(350, mb_strlen($saida));
        $this->assertStringEndsWith('…', $saida);
    }

    public function testDescricaoVaziaContinuaVazia(): void
    {
        $this->assertSame('', GitHubService::descricaoRepositorio("\n\t "));
    }
}
