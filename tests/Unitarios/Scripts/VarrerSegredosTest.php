<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Scripts;

use PHPUnit\Framework\TestCase;
use Ravi\Tests\Unitarios\Apoio\PastaTemporaria;

/**
 * Integração leve com bin/varrer-segredos.sh: roda o script de verdade sobre pastas temporárias.
 *
 * Os padrões falsos são montados por concatenação para que ESTE arquivo não seja acusado
 * quando a varredura rodar sobre o próprio repositório.
 */
final class VarrerSegredosTest extends TestCase
{
    use PastaTemporaria;

    private const SCRIPT = ROOT_PATH . '/bin/varrer-segredos.sh';

    protected function setUp(): void
    {
        if (!is_executable('/bin/bash') && !is_executable('/usr/bin/bash')) {
            $this->markTestSkipped('bash não disponível neste ambiente');
        }
    }

    protected function tearDown(): void
    {
        $this->apagarPastas();
    }

    /** @return array{0: int, 1: string} código de saída e saída padrão */
    private function varrer(string $pasta): array
    {
        $saida = [];
        $codigo = 0;
        exec('bash ' . escapeshellarg(self::SCRIPT) . ' ' . escapeshellarg($pasta) . ' 2>&1', $saida, $codigo);
        return [$codigo, implode("\n", $saida)];
    }

    public function testPastaLimpaSaiComZero(): void
    {
        $pasta = $this->novaPasta('limpa');
        $this->escrever($pasta . '/src/app.php', "<?php\necho 'nada de segredo aqui';\n");
        $this->escrever($pasta . '/README.md', "Projeto de exemplo em http://localhost:8080\n");

        [$codigo, $saida] = $this->varrer($pasta);

        $this->assertSame(0, $codigo, $saida);
        $this->assertStringContainsString('Resumo: 0 bloqueante(s)', $saida);
    }

    public function testChaveDeApiFalsaBloqueiaComSaidaUm(): void
    {
        $pasta = $this->novaPasta('suja');
        $chaveFalsa = 'sk-' . 'ant-' . str_repeat('abcdefghij', 3);
        $this->escrever($pasta . '/config/claude.php', "<?php\nreturn ['key' => '{$chaveFalsa}'];\n");
        // Um segundo arquivo garante que o grep do script imprima o nome do arquivo (com um só
        // arquivo, grep sem -H omite o caminho; ver relatório).
        $this->escrever($pasta . '/config/app.php', "<?php\nreturn [];\n");

        [$codigo, $saida] = $this->varrer($pasta);

        $this->assertSame(1, $codigo, $saida);
        $this->assertStringContainsString('config/claude.php', $saida);
        $this->assertStringContainsString('Resumo: 1 bloqueante(s)', $saida);
        // A saída mascara a chave: só os 6 primeiros caracteres depois do prefixo aparecem.
        $this->assertStringNotContainsString($chaveFalsa, $saida);
        $this->assertStringContainsString('***', $saida);
    }

    public function testChavePrivadaBloqueia(): void
    {
        $pasta = $this->novaPasta('chave');
        $this->escrever($pasta . '/id_teste', '-----' . 'BEGIN OPENSSH ' . "PRIVATE KEY-----\nxxx\n");

        [$codigo] = $this->varrer($pasta);

        $this->assertSame(1, $codigo);
    }

    public function testArquivosDentroDeVendorSaoIgnorados(): void
    {
        $pasta = $this->novaPasta('vendor');
        $chaveFalsa = 'sk-' . 'ant-' . str_repeat('x', 30);
        $this->escrever($pasta . '/vendor/pacote/arquivo.php', $chaveFalsa);

        [$codigo, $saida] = $this->varrer($pasta);

        $this->assertSame(0, $codigo, $saida);
    }

    public function testAvisoNaoBloqueia(): void
    {
        $pasta = $this->novaPasta('aviso');
        $this->escrever($pasta . '/exemplo.env', "DB_PASSWORD='" . 'demo' . "123'\n");

        [$codigo, $saida] = $this->varrer($pasta);

        $this->assertSame(0, $codigo, $saida);
        $this->assertMatchesRegularExpression('/Resumo: 0 bloqueante\(s\), [1-9]\d* aviso\(s\)/', $saida);
    }
}
