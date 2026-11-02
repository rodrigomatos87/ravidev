<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Core;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ravi\Core\Validator;

/**
 * Regras que dependem de banco (unique, exists) ficam de fora: não há banco nos testes unitários.
 */
final class ValidatorTest extends TestCase
{
    public static function casosDeRegra(): array
    {
        return [
            'required preenchido' => [['nome' => 'Ana'], ['nome' => 'required'], true],
            'required ausente' => [[], ['nome' => 'required'], false],
            'required string vazia' => [['nome' => ''], ['nome' => 'required'], false],
            'required lista vazia' => [['itens' => []], ['itens' => 'required'], false],
            'required zero passa' => [['qtd' => 0], ['qtd' => 'required'], true],
            'email válido' => [['email' => 'ana@exemplo.com'], ['email' => 'email'], true],
            'email inválido' => [['email' => 'ana@'], ['email' => 'email'], false],
            'min texto ok' => [['senha' => '12345678'], ['senha' => 'min:8'], true],
            'min texto curto' => [['senha' => '1234567'], ['senha' => 'min:8'], false],
            'min conta caracteres multibyte' => [['nome' => 'çãé'], ['nome' => 'min:3'], true],
            'min numérico' => [['idade' => 17], ['idade' => 'min:18'], false],
            'min lista' => [['tags' => ['a']], ['tags' => 'min:2'], false],
            'max texto ok' => [['nome' => 'abc'], ['nome' => 'max:3'], true],
            'max texto longo' => [['nome' => 'abcd'], ['nome' => 'max:3'], false],
            'max numérico' => [['nota' => 11], ['nota' => 'max:10'], false],
            'in permitido' => [['plano' => 'pro'], ['plano' => 'in:free,pro,enterprise'], true],
            'in fora da lista' => [['plano' => 'gold'], ['plano' => 'in:free,pro,enterprise'], false],
            'numeric inteiro' => [['valor' => '42'], ['valor' => 'numeric'], true],
            'numeric decimal' => [['valor' => '3.14'], ['valor' => 'numeric'], true],
            'numeric texto' => [['valor' => '12a'], ['valor' => 'numeric'], false],
            'confirmed confere' => [['senha' => 'x1', 'senha_confirmation' => 'x1'], ['senha' => 'confirmed'], true],
            'confirmed diverge' => [['senha' => 'x1', 'senha_confirmation' => 'x2'], ['senha' => 'confirmed'], false],
            'confirmed sem confirmação' => [['senha' => 'x1'], ['senha' => 'confirmed'], false],
            'regex casa' => [['cep' => '01310-100'], ['cep' => 'regex:/^\d{5}-\d{3}$/'], true],
            'regex não casa' => [['cep' => '01310100'], ['cep' => 'regex:/^\d{5}-\d{3}$/'], false],
            'url válida' => [['site' => 'https://exemplo.com/a'], ['site' => 'url'], true],
            'url inválida' => [['site' => 'exemplo'], ['site' => 'url'], false],
            'integer' => [['n' => '10'], ['n' => 'integer'], true],
            'integer decimal' => [['n' => '1.5'], ['n' => 'integer'], false],
            'date formato padrão' => [['d' => '2026-11-01'], ['d' => 'date'], true],
            'date inexistente' => [['d' => '2026-02-30'], ['d' => 'date'], false],
            'boolean' => [['b' => '1'], ['b' => 'boolean'], true],
            'between' => [['n' => 'abcd'], ['n' => 'between:2,5'], true],
        ];
    }

    #[DataProvider('casosDeRegra')]
    public function testRegra(array $dados, array $regras, bool $esperado): void
    {
        $this->assertSame($esperado, Validator::make($dados, $regras)->passes());
    }

    public function testCampoOpcionalVazioPulaAsDemaisRegras(): void
    {
        $v = Validator::make(['apelido' => ''], ['apelido' => 'min:3|email', 'site' => 'nullable|url']);

        $this->assertTrue($v->passes());
    }

    public function testRegrasEmListaPermitemRegexComBarraVertical(): void
    {
        $regras = ['cor' => ['required', 'regex:/^(azul|verde)$/']];

        $this->assertTrue(Validator::make(['cor' => 'verde'], $regras)->passes());
        $this->assertTrue(Validator::make(['cor' => 'roxo'], $regras)->fails());
    }

    public function testInEhEstritoEComparaComoTexto(): void
    {
        // Comportamento atual: os valores de in: são strings e a comparação é estrita.
        $this->assertTrue(Validator::make(['n' => '1'], ['n' => 'in:1,2'])->passes());
        $this->assertTrue(Validator::make(['n' => 1], ['n' => 'in:1,2'])->fails());
    }

    public function testMensagensDeErroEmPortuguesComCampoEParametro(): void
    {
        $v = Validator::make(['senha' => '123'], ['nome' => 'required', 'senha' => 'min:8']);

        $this->assertTrue($v->fails());
        $this->assertSame(['O campo nome é obrigatório.'], $v->errors()['nome']);
        $this->assertSame('O campo senha deve ter no mínimo 8 caracteres.', $v->firstError('senha'));
        $this->assertSame('O campo nome é obrigatório.', $v->firstError());
        $this->assertNull($v->firstError('inexistente'));
    }

    public function testVariosErrosNoMesmoCampoSaoAcumulados(): void
    {
        $v = Validator::make(['email' => 'x'], ['email' => 'email|min:5']);

        $this->assertCount(2, $v->errors()['email']);
    }

    public function testValidatedDevolveSoOsCamposQuePassaram(): void
    {
        $v = Validator::make(
            ['nome' => 'Ana', 'email' => 'invalido', 'extra' => 'ignorado'],
            ['nome' => 'required', 'email' => 'email']
        );

        $this->assertSame(['nome' => 'Ana'], $v->validated());
    }

    public function testNotacaoDePontoLeCampoAninhado(): void
    {
        $dados = ['endereco' => ['cidade' => 'Curitiba']];

        $this->assertTrue(Validator::make($dados, ['endereco.cidade' => 'required'])->passes());
        $this->assertTrue(Validator::make($dados, ['endereco.uf' => 'required'])->fails());
    }

    public function testRegraDesconhecidaLancaExcecao(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown validation rule: inventada');

        Validator::make(['x' => 'valor'], ['x' => 'inventada']);
    }

    public function testMensagemPersonalizada(): void
    {
        Validator::setMessage('alpha', 'Só letras em :field.');
        try {
            $v = Validator::make(['nome' => 'a1'], ['nome' => 'alpha']);
            $this->assertSame('Só letras em nome.', $v->firstError('nome'));
        } finally {
            Validator::setMessage('alpha', 'O campo :field deve conter apenas letras.');
        }
    }
}
