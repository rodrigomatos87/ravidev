<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Core;

use PHPUnit\Framework\TestCase;
use Ravi\Core\Modulos;
use Ravi\Core\View;
use Ravi\Tests\Unitarios\Apoio\EstadoGlobal;
use Ravi\Tests\Unitarios\Apoio\PastaTemporaria;
use RuntimeException;

final class ViewTest extends TestCase
{
    use PastaTemporaria;

    private string $views;
    private View $view;

    protected function setUp(): void
    {
        EstadoGlobal::reiniciarModulos();
        $this->views = $this->novaPasta('views');
        $this->view = new View($this->views . '/');
    }

    protected function tearDown(): void
    {
        EstadoGlobal::reiniciarModulos();
        $this->apagarPastas();
    }

    public function testRenderSimpleComVariaveis(): void
    {
        $this->escrever($this->views . '/ola.php', 'Olá, <?= $nome ?>!');

        $this->assertSame('Olá, Ana!', $this->view->render('ola', ['nome' => 'Ana']));
    }

    public function testNotacaoDePontoViraSubpasta(): void
    {
        $this->escrever($this->views . '/projetos/lista.php', 'lista de projetos');

        $this->assertSame('lista de projetos', $this->view->render('projetos.lista'));
        $this->assertTrue($this->view->exists('projetos.lista'));
        $this->assertFalse($this->view->exists('projetos.nada'));
    }

    public function testViewInexistenteLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View not found: sumida');

        $this->view->render('sumida');
    }

    public function testDadosCompartilhadosEDadosLocaisTemPrioridade(): void
    {
        $this->escrever($this->views . '/v.php', '<?= $app ?>|<?= $titulo ?>');
        $this->view->share('app', 'Ravi');
        $this->view->share('titulo', 'compartilhado');

        $this->assertSame('Ravi|local', $this->view->render('v', ['titulo' => 'local']));
    }

    public function testLayoutRecebeConteudoDaView(): void
    {
        $this->escrever($this->views . '/layouts/base.php', '<main><?= $content ?></main><title><?= $titulo ?></title>');
        $this->escrever($this->views . '/pagina.php', '<?php $this->layout(\'layouts.base\', [\'titulo\' => \'Página\']); ?>corpo');

        $this->assertSame('<main>corpo</main><title>Página</title>', $this->view->render('pagina'));
    }

    public function testExtendsTambemDefineLayoutESoValeUmaVez(): void
    {
        $this->escrever($this->views . '/base.php', '[<?= $content ?>]');
        $this->escrever($this->views . '/filha.php', '<?php $this->extends(\'base\'); ?>x');
        $this->escrever($this->views . '/solta.php', 'y');

        $this->assertSame('[x]', $this->view->render('filha'));
        $this->assertSame('y', $this->view->render('solta'));
    }

    public function testSecaoDefinidaNaViewApareceNoLayout(): void
    {
        $this->escrever(
            $this->views . '/base.php',
            '<head><?= $this->yield(\'estilos\', \'sem estilos\') ?></head><body><?= $content ?></body><?= $this->yield(\'rodape\', \'padrão\') ?>'
        );
        $this->escrever(
            $this->views . '/pagina.php',
            '<?php $this->extends(\'base\'); $this->section(\'estilos\'); ?><link href="a.css"><?php $this->endSection(); ?>conteúdo'
        );

        $this->assertSame(
            '<head><link href="a.css"></head><body>conteúdo</body>padrão',
            $this->view->render('pagina')
        );
    }

    public function testEndSectionSemSecaoAbertaLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);

        $this->view->endSection();
    }

    public function testErroDentroDaViewNaoDeixaBufferAberto(): void
    {
        $this->escrever($this->views . '/quebrada.php', 'antes<?php throw new \RuntimeException("falhou"); ?>');
        $nivel = ob_get_level();

        try {
            $this->view->render('quebrada');
            $this->fail('Deveria ter lançado exceção');
        } catch (RuntimeException $e) {
            $this->assertSame('falhou', $e->getMessage());
        }

        $this->assertSame($nivel, ob_get_level());
    }

    public function testIncludeDePartial(): void
    {
        $this->escrever($this->views . '/partials/item.php', '<li><?= $this->e($rotulo) ?></li>');
        $this->escrever($this->views . '/lista.php', '<ul><?php $this->include(\'partials.item\', [\'rotulo\' => \'a&b\']); ?></ul>');

        $this->assertSame('<ul><li>a&amp;b</li></ul>', $this->view->render('lista'));
    }

    public function testEscapeDoMetodoEDoHelperGlobal(): void
    {
        $perigoso = '<script>alert("x")</script> & \'aspas\'';
        $esperado = '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;aspas&#039;';

        $this->assertSame($esperado, $this->view->e($perigoso));
        $this->assertSame($esperado, e($perigoso));
        $this->assertSame('', e(null));
        // double_encode desligado: entidade já escapada não é escapada de novo.
        $this->assertSame('&amp;', e('&amp;'));
    }

    public function testViewDeModuloComDoisPontosDuplos(): void
    {
        $modulos = $this->novaPasta('modulos');
        $this->escrever($modulos . '/exemplo/modulo.php', "<?php return ['views' => __DIR__ . '/views'];");
        $this->escrever($modulos . '/exemplo/views/admin/painel.php', 'painel do módulo: <?= $n ?>');
        Modulos::carregar($modulos);

        $this->assertSame('painel do módulo: 3', $this->view->render('exemplo::admin.painel', ['n' => 3]));
        $this->assertTrue($this->view->exists('exemplo::admin.painel'));
    }

    public function testViewDeModuloAusenteCaiNaPastaPrincipal(): void
    {
        // Sem o módulo carregado, 'fantasma::x' vira caminho comum e não existe.
        $this->assertFalse($this->view->exists('fantasma::x'));

        $this->expectException(RuntimeException::class);
        $this->view->render('fantasma::x');
    }

    public function testJsonSemEscaparBarrasNemAcentos(): void
    {
        $this->assertSame('{"url":"https://a/b","nome":"ação"}', $this->view->json(['url' => 'https://a/b', 'nome' => 'ação']));
    }
}
