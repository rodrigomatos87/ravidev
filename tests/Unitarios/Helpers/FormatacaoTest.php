<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * Funções puras de src/Helpers/formatting.php (e as homônimas de helpers.php, que vencem
 * por serem carregadas antes pelo composer).
 */
final class FormatacaoTest extends TestCase
{
    public function testFormatBytes(): void
    {
        $this->assertSame('0 B', formatBytes(0));
        $this->assertSame('512 B', formatBytes(512));
        $this->assertSame('1.5 KB', formatBytes(1536));
        $this->assertSame('2 MB', formatBytes(2 * 1024 * 1024 + 1));
        $this->assertSame('1.33 GB', formatBytes((int) (1.333 * 1024 ** 3)));
        $this->assertSame('1.3 GB', formatBytes((int) (1.333 * 1024 ** 3), 1));
    }

    /**
     * No limite exato a unidade sobe: 1024 bytes = "1 KB" e 1024 KB = "1 MB".
     */
    public function testFormatBytesNoLimiteExatoSobeDeUnidade(): void
    {
        $this->assertSame('1 KB', formatBytes(1024));
        $this->assertSame('1 MB', formatBytes(1024 * 1024));
    }

    public function testFormatNumberNoPadraoBrasileiro(): void
    {
        $this->assertSame('1.234.567', formatNumber(1234567));
        $this->assertSame('1.234,57', formatNumber(1234.567, 2));
        $this->assertSame('0', formatNumber(0));
    }

    public function testFormatTokens(): void
    {
        $this->assertSame('999', formatTokens(999));
        $this->assertSame('1k', formatTokens(1000));
        $this->assertSame('1.5k', formatTokens(1500));
        $this->assertSame('2.5M', formatTokens(2_500_000));
    }

    public function testCustoEstimadoUsaAsTarifasDaConfiguracao(): void
    {
        $entrada = (float) config('claude.pricing.input_per_mtok');
        $saida = (float) config('claude.pricing.output_per_mtok');

        $this->assertEqualsWithDelta($entrada, estimateApiCost(1_000_000, 0), 1e-9);
        $this->assertEqualsWithDelta($saida, estimateApiCost(0, 1_000_000), 1e-9);
        $this->assertSame(0.0, estimateApiCost(0, 0));
    }

    public function testCustoRealConsideraEscritaELeituraDeCache(): void
    {
        $entrada = (float) config('claude.pricing.input_per_mtok');
        $escrita = (float) config('claude.pricing.cache_write_mult');
        $leitura = (float) config('claude.pricing.cache_read_mult');

        $this->assertEqualsWithDelta($entrada * $escrita, realApiCost(0, 1_000_000, 0, 0), 1e-9);
        $this->assertEqualsWithDelta($entrada * $leitura, realApiCost(0, 0, 1_000_000, 0), 1e-9);
        $this->assertEqualsWithDelta(estimateApiCost(100, 200), realApiCost(100, 0, 0, 200), 1e-12);
    }

    public function testFormatCostNaoSomeComValoresPequenos(): void
    {
        $this->assertSame('$0.00', formatCost(0.0));
        $this->assertSame('$0.00', formatCost(-1.0));
        $this->assertSame('<$0.01', formatCost(0.004));
        $this->assertSame('$1.23', formatCost(1.2345));
        $this->assertSame('$1,234.50', formatCost(1234.5));
    }

    public function testFormatCurrency(): void
    {
        $this->assertSame('R$1,234.50', formatCurrency(1234.5, 'BRL'));
        $this->assertSame('$10.00', formatCurrency(10));
        $this->assertSame('JPY 5.00', formatCurrency(5, 'JPY'));
    }

    public function testFormatDate(): void
    {
        $this->assertSame('-', formatDate(null));
        $this->assertSame('-', formatDate(''));
        $this->assertSame('01/11/2026 14:30', formatDate('2026-11-01 14:30:00'));
        $this->assertSame('2026', formatDate('2026-11-01', 'Y'));
    }

    public function testFormatRelativeTime(): void
    {
        $this->assertSame('-', formatRelativeTime(null));
        $this->assertSame('agora mesmo', formatRelativeTime(date('Y-m-d H:i:s')));
        $this->assertSame('5 minutos atrás', formatRelativeTime(date('Y-m-d H:i:s', time() - 5 * 60 - 5)));
        $this->assertSame('1 hora atrás', formatRelativeTime(date('Y-m-d H:i:s', time() - 3600 - 5)));
        $this->assertSame('3 dias atrás', formatRelativeTime(date('Y-m-d H:i:s', time() - 3 * 86400 - 5)));
        $this->assertSame('2 anos atrás', formatRelativeTime(date('Y-m-d H:i:s', time() - 2 * 31536000 - 5)));
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('45s', formatDuration(45));
        $this->assertSame('2m 5s', formatDuration(125));
        $this->assertSame('1h 2m', formatDuration(3725));
    }

    public function testTruncateRespeitaMultibyte(): void
    {
        $this->assertSame('curto', truncate('curto', 10));
        $this->assertSame('ab...', truncate('abcdefghij', 5));
        $this->assertSame('açã…', truncate('açãoé', 4, '…'));
    }

    public function testSlugify(): void
    {
        $this->assertSame('ola-mundo', slugify('  Olá, Mundo!  '));
        $this->assertSame('acao-rapida-2026', slugify('Ação Rápida 2026'));
        $this->assertSame('', slugify('!!!'));
    }

    public function testExcerptRemoveHtmlEEspacos(): void
    {
        $this->assertSame('Título texto', excerpt("<h1>Título</h1>\n\n  <p>texto</p>"));
        $this->assertSame('abcd...', excerpt('<b>abcdefghijkl</b>', 7));
    }

    public function testLinguagemPelaExtensao(): void
    {
        $this->assertSame('php', getLanguageFromExtension('src/Core/Router.php'));
        $this->assertSame('typescript', getLanguageFromExtension('app.TS'));
        $this->assertSame('yaml', getLanguageFromExtension('ci.yml'));
        $this->assertSame('plaintext', getLanguageFromExtension('LEIAME'));
    }

    public function testMaskEmail(): void
    {
        $this->assertSame('j**o@exemplo.com', maskEmail('joao@exemplo.com'));
        $this->assertSame('a***@exemplo.com', maskEmail('ab@exemplo.com'));
        $this->assertSame('sem-arroba', maskEmail('sem-arroba'));
    }

    public function testParseMarkdownEscapaHtmlAntes(): void
    {
        $this->assertSame('', parse_markdown(null));
        $this->assertSame(
            '<strong>a</strong> &lt;script&gt; <code>c</code> <em>i</em>',
            parse_markdown('**a** <script> `c` *i*')
        );
        $this->assertSame("<pre><code>x = 1\ny = 2</code></pre>", parse_markdown("```php\nx = 1\ny = 2\n```"));
        $this->assertSame("linha1<br />\nlinha2", parse_markdown("linha1\nlinha2"));
    }

    public function testParseMarkdownSoAceitaLinkHttp(): void
    {
        $this->assertSame(
            '<a href="https://exemplo.com" target="_blank" rel="noopener">site</a>',
            parse_markdown('[site](https://exemplo.com)')
        );
        $this->assertSame('[x](javascript:alert(1))', parse_markdown('[x](javascript:alert(1))'));
    }

    public function testFormatacaoDeFerramentas(): void
    {
        $this->assertSame('', get_tool_icon('bash_execute'));
        $this->assertSame('File write', format_tool_name('file_write'));
        $this->assertSame('Ferramenta', format_tool_name(null));
        $this->assertSame('{&quot;a&quot;:&quot;&lt;x&gt;&quot;}', format_tool_input(['a' => '<x>']));
        $this->assertSame(1000, mb_strlen(format_tool_input(str_repeat('a', 5000))));
    }
}
