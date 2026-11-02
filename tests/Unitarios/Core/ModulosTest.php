<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Core;

use PHPUnit\Framework\TestCase;
use Ravi\Core\Modulos;
use Ravi\Core\Request;
use Ravi\Core\Router;
use Ravi\Tests\Unitarios\Apoio\EstadoGlobal;
use Ravi\Tests\Unitarios\Apoio\PastaTemporaria;
use RuntimeException;

final class ModulosTest extends TestCase
{
    use PastaTemporaria;

    private string $raiz;

    protected function setUp(): void
    {
        EstadoGlobal::reiniciarModulos();
        EstadoGlobal::removerAplicacao();
        $this->raiz = $this->novaPasta('modulos');

        $this->escrever($this->raiz . '/alfa/modulo.php', <<<'PHP'
        <?php
        use Ravi\Core\Router;
        return [
            'titulo' => 'Módulo Alfa',
            'namespace' => 'Ravi\\ModulosDeTeste\\Alfa',
            'rotas' => fn (Router $r) => $r->get('/alfa/publico', fn () => 'alfa público'),
            'rotas_auth' => fn (Router $r) => $r->get('/alfa/logado', fn () => 'alfa logado'),
            'rotas_admin' => function (Router $r): void {
                $r->get('/alfa', fn () => 'admin alfa');
                $r->get('/alfa/{id}', fn ($req, string $id) => 'admin alfa ' . $id);
            },
            'menu_admin' => [
                ['url' => '/admin/alfa', 'pagina' => 'admin-alfa', 'titulo' => 'Alfa'],
            ],
            'migrations' => __DIR__ . '/migrations',
            'ganchos' => [
                'teste.soma' => fn (int $a, int $b): int => $a + $b,
                'teste.quebra' => function (): never { throw new \RuntimeException('módulo alfa quebrou'); },
            ],
        ];
        PHP);
        $this->escrever($this->raiz . '/alfa/migrations/001_alfa.sql', 'SELECT 1;');
        $this->escrever($this->raiz . '/alfa/migrations/002_alfa.sql', 'SELECT 2;');
        $this->escrever($this->raiz . '/alfa/migrations/leiame.txt', 'não é migration');
        $this->escrever($this->raiz . '/alfa/src/Servico.php', <<<'PHP'
        <?php
        namespace Ravi\ModulosDeTeste\Alfa;
        final class Servico { public function nome(): string { return 'serviço alfa'; } }
        PHP);

        $this->escrever($this->raiz . '/beta/modulo.php', <<<'PHP'
        <?php
        return [
            'nome' => 'beta-renomeado',
            'menu_admin' => [
                ['url' => '/admin/beta', 'pagina' => 'admin-beta', 'titulo' => 'Beta'],
            ],
            'migrations' => __DIR__ . '/pasta-que-nao-existe',
            'ganchos' => [
                'teste.soma' => fn (int $a, int $b): int => $a * $b,
            ],
        ];
        PHP);

        // Manifesto que não devolve array é ignorado.
        $this->escrever($this->raiz . '/gama/modulo.php', '<?php return "não sou manifesto";');
        // Pasta sem modulo.php também.
        $this->escrever($this->raiz . '/delta/leiame.txt', 'sem manifesto');
    }

    protected function tearDown(): void
    {
        EstadoGlobal::reiniciarModulos();
        EstadoGlobal::removerAplicacao();
        $this->apagarPastas();
    }

    public function testSemPastaDeModulosNadaEhCarregado(): void
    {
        Modulos::carregar($this->raiz . '/nao-existe');

        $this->assertSame([], Modulos::todos());
        $this->assertSame([], Modulos::menuAdmin());
        $this->assertSame([], Modulos::migrations());
        $this->assertSame([], Modulos::gancho('teste.soma', 1, 2));
    }

    public function testCarregaManifestosValidosEIgnoraOsDemais(): void
    {
        Modulos::carregar($this->raiz . '/');

        $todos = Modulos::todos();
        $this->assertSame(['alfa', 'beta-renomeado'], array_keys($todos));
        $this->assertSame($this->raiz . '/alfa', $todos['alfa']['pasta']);
        $this->assertTrue(Modulos::ativo('alfa'));
        $this->assertTrue(Modulos::ativo('beta-renomeado'));
        $this->assertFalse(Modulos::ativo('beta'));
        $this->assertFalse(Modulos::ativo('gama'));
    }

    public function testCarregarSoValeUmaVez(): void
    {
        Modulos::carregar($this->raiz);
        Modulos::carregar($this->novaPasta('outra'));

        $this->assertCount(2, Modulos::todos());
    }

    public function testAutoloadDoNamespaceDoModulo(): void
    {
        Modulos::carregar($this->raiz);

        $classe = 'Ravi\\ModulosDeTeste\\Alfa\\Servico';
        $this->assertTrue(class_exists($classe));
        $this->assertSame('serviço alfa', (new $classe())->nome());
        $this->assertFalse(class_exists('Ravi\\ModulosDeTeste\\Alfa\\NaoExiste'));
    }

    public function testRotasAdminSaoRegistradasDentroDoGrupoDoRouter(): void
    {
        Modulos::carregar($this->raiz);
        $router = new Router();

        $router->group(['prefix' => 'admin'], function (Router $r): void {
            Modulos::rotasAdmin($r);
        });

        $this->assertSame(['/admin/alfa', '/admin/alfa/{id}'], array_column($router->getRoutes(), 'path'));
        $this->assertSame('admin alfa 9', $router->dispatch(new Request('GET', '/admin/alfa/9'))->getContent());
    }

    public function testRotasPublicasEAutenticadasFicamSeparadas(): void
    {
        Modulos::carregar($this->raiz);
        $publico = new Router();
        $logado = new Router();

        Modulos::rotas($publico);
        Modulos::rotasAuth($logado);

        $this->assertSame(['/alfa/publico'], array_column($publico->getRoutes(), 'path'));
        $this->assertSame(['/alfa/logado'], array_column($logado->getRoutes(), 'path'));
    }

    public function testMenuAdminNaOrdemDeCarga(): void
    {
        Modulos::carregar($this->raiz);

        $this->assertSame(['/admin/alfa', '/admin/beta'], array_column(Modulos::menuAdmin(), 'url'));
    }

    public function testMigrationsSoSql(): void
    {
        Modulos::carregar($this->raiz);

        $this->assertSame(
            [$this->raiz . '/alfa/migrations/001_alfa.sql', $this->raiz . '/alfa/migrations/002_alfa.sql'],
            Modulos::migrations()
        );
    }

    public function testViewsDeModuloSemPastaDeViewsEhNulo(): void
    {
        Modulos::carregar($this->raiz);

        $this->assertNull(Modulos::viewsDe('alfa'));
        $this->assertNull(Modulos::viewsDe('inexistente'));
    }

    public function testGanchoDevolveUmRetornoPorModulo(): void
    {
        Modulos::carregar($this->raiz);

        $this->assertSame(['alfa' => 5, 'beta-renomeado' => 6], Modulos::gancho('teste.soma', 2, 3));
        $this->assertSame([], Modulos::gancho('gancho.que.ninguem.implementa'));
    }

    public function testGanchoQueLancaExcecaoNaoDerrubaERegistraNoLog(): void
    {
        $pastaLogs = $this->novaPasta('logs');
        EstadoGlobal::instalarAplicacaoComLogger($pastaLogs);
        Modulos::carregar($this->raiz);

        $retornos = Modulos::gancho('teste.quebra');

        $this->assertSame([], $retornos);
        $log = implode('', array_map('file_get_contents', glob($pastaLogs . '/*.log') ?: []));
        $this->assertStringContainsString('Modulo alfa falhou no gancho teste.quebra: módulo alfa quebrou', $log);
    }

    /**
     * Sem a Application iniciada (CLI, scripts de bin/, testes), logger() lança exceção. O gancho
     * precisa engolir também essa falha de log e cair no error_log, sem derrubar o fluxo principal.
     */
    public function testSemAplicacaoIniciadaOGanchoNaoDerrubaOFluxo(): void
    {
        Modulos::carregar($this->raiz);

        $retornos = Modulos::gancho('teste.quebra');

        $this->assertIsArray($retornos);
        $this->assertArrayNotHasKey('quebrado', $retornos);
    }
}
