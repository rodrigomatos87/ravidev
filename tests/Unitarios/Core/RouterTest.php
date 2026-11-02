<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Core;

use PHPUnit\Framework\TestCase;
use Ravi\Core\JsonResponse;
use Ravi\Core\Request;
use Ravi\Core\Response;
use Ravi\Core\Router;
use Ravi\Tests\Unitarios\Apoio\ControladorFalso;
use Ravi\Tests\Unitarios\Apoio\MiddlewareBloqueador;
use Ravi\Tests\Unitarios\Apoio\MiddlewareLiberador;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        MiddlewareLiberador::$chamadas = 0;
    }

    private function requisicao(string $metodo, string $uri, array $post = [], array $server = []): Request
    {
        return new Request($metodo, $uri, [], $post, $server);
    }

    public function testRotaSimplesDevolveConteudoDoHandler(): void
    {
        $this->router->get('/ola', fn (Request $r) => 'olá mundo');

        $resposta = $this->router->dispatch($this->requisicao('GET', '/ola'));

        $this->assertSame(200, $resposta->getStatusCode());
        $this->assertSame('olá mundo', $resposta->getContent());
    }

    public function testQueryStringNaoAtrapalhaOCasamento(): void
    {
        $this->router->get('/busca', fn (Request $r) => 'q=' . $r->query('q'));

        $resposta = $this->router->dispatch(new Request('GET', '/busca?q=abc', ['q' => 'abc']));

        $this->assertSame('q=abc', $resposta->getContent());
    }

    public function testBarrasNasPontasSaoNormalizadasNoRegistro(): void
    {
        $this->router->get('projetos/', fn () => 'ok');

        $this->assertSame('ok', $this->router->dispatch($this->requisicao('GET', '/projetos'))->getContent());
        $this->assertSame('/projetos', $this->router->getRoutes()[0]['path']);
    }

    public function testParametrosSaoPassadosNaOrdemParaOHandler(): void
    {
        $this->router->get('/projetos/{id}/conversas/{conversa}', fn (Request $r, string $id, string $conversa) => "$id|$conversa");

        $resposta = $this->router->dispatch($this->requisicao('GET', '/projetos/42/conversas/abc-def'));

        $this->assertSame('42|abc-def', $resposta->getContent());
    }

    public function testParametroNaoAtravessaBarra(): void
    {
        $this->router->get('/arquivos/{nome}', fn (Request $r, string $nome) => $nome);

        $resposta = $this->router->dispatch($this->requisicao('GET', '/arquivos/a/b'));

        $this->assertSame(404, $resposta->getStatusCode());
    }

    public function testMetodoErradoDa404(): void
    {
        $this->router->post('/salvar', fn () => 'salvo');

        $this->assertSame(404, $this->router->dispatch($this->requisicao('GET', '/salvar'))->getStatusCode());
    }

    public function testHandlerNoFormatoClasseMetodo(): void
    {
        $this->router->get('/itens/{id}', [ControladorFalso::class, 'mostrar']);
        $this->router->get('/itens', [new ControladorFalso(), 'listar']);

        $this->assertSame('mostrar:7', $this->router->dispatch($this->requisicao('GET', '/itens/7'))->getContent());

        $lista = $this->router->dispatch($this->requisicao('GET', '/itens'));
        $this->assertInstanceOf(JsonResponse::class, $lista);
        $this->assertSame(['itens' => [1, 2, 3]], json_decode($lista->getContent(), true));
    }

    public function testRetornoNuloViraNoContent(): void
    {
        $this->router->delete('/coisa', fn () => null);

        $resposta = $this->router->dispatch($this->requisicao('DELETE', '/coisa'));

        $this->assertSame(204, $resposta->getStatusCode());
        $this->assertSame('', $resposta->getContent());
    }

    public function testRetornoResponseEUsadoDireto(): void
    {
        $this->router->get('/criado', fn () => new Response('feito', 201));

        $this->assertSame(201, $this->router->dispatch($this->requisicao('GET', '/criado'))->getStatusCode());
    }

    public function testGrupoAplicaPrefixoEGruposAninhadosSomam(): void
    {
        $this->router->group(['prefix' => 'admin'], function (Router $r): void {
            $r->get('/usuarios', fn () => 'lista');
            $r->group(['prefix' => '/api/'], function (Router $r): void {
                $r->get('/status', fn () => 'ok');
            });
        });
        $this->router->get('/fora', fn () => 'fora');

        $caminhos = array_column($this->router->getRoutes(), 'path');
        $this->assertSame(['/admin/usuarios', '/admin/api/status', '/fora'], $caminhos);
        $this->assertSame('ok', $this->router->dispatch($this->requisicao('GET', '/admin/api/status'))->getContent());
        $this->assertSame(404, $this->router->dispatch($this->requisicao('GET', '/usuarios'))->getStatusCode());
    }

    public function testGrupoComPrefixoVazioERotaRaiz(): void
    {
        $this->router->group(['prefix' => 'painel'], function (Router $r): void {
            $r->get('/', fn () => 'raiz do painel');
        });

        $this->assertSame('/painel', $this->router->getRoutes()[0]['path']);
        $this->assertSame('raiz do painel', $this->router->dispatch($this->requisicao('GET', '/painel'))->getContent());
    }

    public function testMiddlewareDoGrupoPorAliasBarraARequisicao(): void
    {
        $this->router->registerMiddleware('bloqueia', MiddlewareBloqueador::class);
        $this->router->group(['middleware' => 'bloqueia'], function (Router $r): void {
            $r->get('/secreto', fn () => 'não deveria chegar aqui');
        });
        $this->router->get('/publico', fn () => 'aberto');

        $barrada = $this->router->dispatch($this->requisicao('GET', '/secreto'));
        $this->assertSame(403, $barrada->getStatusCode());
        $this->assertSame('bloqueado', $barrada->getContent());

        $this->assertSame('aberto', $this->router->dispatch($this->requisicao('GET', '/publico'))->getContent());
        $this->assertSame([], $this->router->getRoutes()[1]['middleware']);
    }

    public function testMiddlewaresDeGruposAninhadosSeAcumulamEVoltamAoSair(): void
    {
        $this->router->group(['middleware' => [MiddlewareLiberador::class]], function (Router $r): void {
            $r->group(['middleware' => MiddlewareLiberador::class], function (Router $r): void {
                $r->get('/dentro', fn () => 'ok');
            });
        });
        $this->router->get('/depois', fn () => 'ok');

        $rotas = $this->router->getRoutes();
        $this->assertCount(2, $rotas[0]['middleware']);
        $this->assertSame([], $rotas[1]['middleware']);

        $this->router->dispatch($this->requisicao('GET', '/dentro'));
        $this->assertSame(2, MiddlewareLiberador::$chamadas);
    }

    /**
     * Comportamento ATUAL: a primeira rota registrada que casar vence. Uma rota com parâmetros
     * registrada antes "faz sombra" sobre uma rota literal mais específica registrada depois.
     * Quem registra rotas em src/routes.php precisa pôr as literais ANTES das genéricas.
     */
    public function testRotaComParametroRegistradaAntesFazSombraNaRotaLiteral(): void
    {
        $this->router->get('/workspace/{a}/{b}', fn (Request $r, string $a, string $b) => "generica:$a/$b");
        $this->router->get('/workspace/{a}/files', fn (Request $r, string $a) => "arquivos:$a");

        $resposta = $this->router->dispatch($this->requisicao('GET', '/workspace/proj1/files'));

        $this->assertSame('generica:proj1/files', $resposta->getContent());
    }

    public function testRotaLiteralRegistradaAntesVenceAGenerica(): void
    {
        $this->router->get('/workspace/{a}/files', fn (Request $r, string $a) => "arquivos:$a");
        $this->router->get('/workspace/{a}/{b}', fn (Request $r, string $a, string $b) => "generica:$a/$b");

        $this->assertSame('arquivos:proj1', $this->router->dispatch($this->requisicao('GET', '/workspace/proj1/files'))->getContent());
        $this->assertSame('generica:proj1/outra', $this->router->dispatch($this->requisicao('GET', '/workspace/proj1/outra'))->getContent());
    }

    public function testRegistrarMesmoPadraoDuasVezesSobrescreveAPrimeira(): void
    {
        $this->router->get('/x', fn () => 'primeira');
        $this->router->get('/x', fn () => 'segunda');

        $this->assertCount(1, $this->router->getRoutes());
        $this->assertSame('segunda', $this->router->dispatch($this->requisicao('GET', '/x'))->getContent());
    }

    public function testSem404PersonalizadoDevolvePaginaPadrao(): void
    {
        $resposta = $this->router->dispatch($this->requisicao('GET', '/nao-existe'));

        $this->assertSame(404, $resposta->getStatusCode());
        $this->assertStringContainsString('404', $resposta->getContent());
    }

    public function testFallbackComTextoDevolve404ComEsseConteudo(): void
    {
        $this->router->fallback(fn () => 'página sumiu');

        $resposta = $this->router->dispatch($this->requisicao('GET', '/nao-existe'));

        $this->assertSame(404, $resposta->getStatusCode());
        $this->assertSame('página sumiu', $resposta->getContent());
    }

    public function testFallbackComResponseRespeitaOStatusDela(): void
    {
        $this->router->fallback(fn () => new Response('foi embora', 410));

        $this->assertSame(410, $this->router->dispatch($this->requisicao('GET', '/x'))->getStatusCode());
    }

    public function testMetodoDesconhecidoCaiNo404(): void
    {
        $this->assertSame(404, $this->router->dispatch($this->requisicao('OPTIONS', '/'))->getStatusCode());
    }

    public function testSpoofingDeMetodoViaCampoMethodNoPost(): void
    {
        $this->router->put('/projetos/{id}', fn (Request $r, string $id) => "put:$id");
        $this->router->delete('/projetos/{id}', fn (Request $r, string $id) => "delete:$id");

        $put = $this->router->dispatch($this->requisicao('POST', '/projetos/5', ['_method' => 'put']));
        $delete = $this->router->dispatch($this->requisicao('POST', '/projetos/5', ['_method' => 'DELETE']));

        $this->assertSame('put:5', $put->getContent());
        $this->assertSame('delete:5', $delete->getContent());
    }

    public function testSpoofingSoValeParaPost(): void
    {
        $this->router->delete('/projetos/{id}', fn () => 'apagou');

        $resposta = $this->router->dispatch(new Request('GET', '/projetos/5', ['_method' => 'DELETE']));

        $this->assertSame(404, $resposta->getStatusCode());
    }

    public function testResourceRegistraAsSeteRotasRest(): void
    {
        $this->router->resource('fotos', ControladorFalso::class);

        $rotas = array_map(fn (array $r) => $r['method'] . ' ' . $r['path'], $this->router->getRoutes());
        sort($rotas);

        $this->assertSame([
            'DELETE /fotos/{id}',
            'GET /fotos',
            'GET /fotos/create',
            'GET /fotos/{id}',
            'GET /fotos/{id}/edit',
            'POST /fotos',
            'PUT /fotos/{id}',
        ], $rotas);
    }

    /**
     * Comportamento ATUAL: resource() dentro de um grupo com prefixo duplica o prefixo,
     * porque monta o caminho com currentPrefix e depois get()/post() somam o prefixo de novo.
     */
    public function testResourceDentroDeGrupoDuplicaOPrefixo(): void
    {
        $this->router->group(['prefix' => 'admin'], function (Router $r): void {
            $r->resource('fotos', ControladorFalso::class);
        });

        $caminhos = array_unique(array_column($this->router->getRoutes(), 'path'));

        $this->assertContains('/admin/admin/fotos', $caminhos);
        $this->assertNotContains('/admin/fotos', $caminhos);
    }
}
