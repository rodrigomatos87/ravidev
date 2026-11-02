<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ravi\Core\JsonResponse;
use Ravi\Core\Request;
use Ravi\Middleware\CsrfMiddleware;

/**
 * Não precisa de sessão real: Session lê e grava direto em $_SESSION, que o teste monta à mão.
 *
 * Fica de fora o caminho "token inválido em requisição de navegador (não JSON)": ali o
 * middleware chama header('Location: ...') e exit, o que encerraria o próprio PHPUnit.
 */
final class CsrfMiddlewareTest extends TestCase
{
    private const TOKEN = 'token-csrf-de-teste-0123456789abcdef';

    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $_SESSION = ['_csrf_token' => self::TOKEN];
        $this->middleware = new CsrfMiddleware();
    }

    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    /** Requisição de API (espera JSON), para o middleware responder 419 em vez de redirecionar. */
    private function requisicaoJson(string $metodo, string $caminho, array $post = [], array $server = []): Request
    {
        return new Request($metodo, $caminho, [], $post, ['HTTP_ACCEPT' => 'application/json'] + $server);
    }

    public function testGetGeraTokenNaSessaoQuandoNaoExiste(): void
    {
        $_SESSION = [];

        $this->assertNull($this->middleware->handle(new Request('GET', '/painel')));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $_SESSION['_csrf_token']);
    }

    public function testGetNaoTrocaTokenExistente(): void
    {
        $this->middleware->handle(new Request('GET', '/painel'));

        $this->assertSame(self::TOKEN, $_SESSION['_csrf_token']);
    }

    public static function metodosProtegidos(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    #[DataProvider('metodosProtegidos')]
    public function testMetodoProtegidoSemTokenEhRecusadoCom419(string $metodo): void
    {
        $resposta = $this->middleware->handle($this->requisicaoJson($metodo, '/projetos'));

        $this->assertInstanceOf(JsonResponse::class, $resposta);
        $this->assertSame(419, $resposta->getStatusCode());
        $this->assertStringContainsString('Token CSRF', $resposta->getContent());
    }

    public function testTokenErradoEhRecusado(): void
    {
        $resposta = $this->middleware->handle($this->requisicaoJson('POST', '/projetos', ['_csrf_token' => 'outro']));

        $this->assertSame(419, $resposta?->getStatusCode());
    }

    public function testAjaxSemTokenTambemRecebe419(): void
    {
        $requisicao = new Request('POST', '/projetos', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $this->assertSame(419, $this->middleware->handle($requisicao)?->getStatusCode());
    }

    public function testTokenValidoNoCorpoPassa(): void
    {
        $this->assertNull($this->middleware->handle($this->requisicaoJson('POST', '/projetos', ['_csrf_token' => self::TOKEN])));
    }

    public function testTokenValidoNoCabecalhoPassa(): void
    {
        $requisicao = $this->requisicaoJson('DELETE', '/projetos/1', [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN]);

        $this->assertNull($this->middleware->handle($requisicao));
    }

    public function testTokenVazioNaSessaoENaRequisicaoEhRecusado(): void
    {
        $_SESSION = ['_csrf_token' => ''];

        // Sessão com token vazio: o middleware não regenera (has() é verdadeiro) e nada casa com vazio.
        $resposta = $this->middleware->handle($this->requisicaoJson('POST', '/projetos', ['_csrf_token' => '']));

        $this->assertSame(419, $resposta?->getStatusCode());
    }
}
