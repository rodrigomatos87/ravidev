<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Core;

use PHPUnit\Framework\TestCase;
use Ravi\Core\Encryption;
use RuntimeException;

/**
 * A chave é sempre passada explicitamente no construtor: o teste não depende de como
 * a classe resolve a chave a partir do ambiente (ENCRYPTION_KEY / APP_KEY).
 */
final class EncryptionTest extends TestCase
{
    // Chaves com mais de 32 bytes: valem tanto na regra antiga quanto na exigência de tamanho mínimo.
    private const CHAVE_TEXTO = 'chave-de-teste-que-nao-e-segredo-nenhum-0123456789';
    private const OUTRA_CHAVE = 'outra-chave-de-teste-tambem-sem-segredo-9876543210';

    public function testCifraEDecifraIdaEVolta(): void
    {
        $cripto = new Encryption(self::CHAVE_TEXTO);
        $original = "texto com acentuação, emoji 🚀 e\nquebra de linha";

        $cifrado = $cripto->encrypt($original);

        $this->assertNotSame($original, $cifrado);
        $this->assertStringNotContainsString('acentuação', $cifrado);
        $this->assertSame($original, $cripto->decrypt($cifrado));
    }

    public function testTextoVazioTambemFazIdaEVolta(): void
    {
        $cripto = new Encryption(self::CHAVE_TEXTO);

        $this->assertSame('', $cripto->decrypt($cripto->encrypt('')));
    }

    public function testCadaCifragemUsaNonceDiferente(): void
    {
        $cripto = new Encryption(self::CHAVE_TEXTO);

        $this->assertNotSame($cripto->encrypt('igual'), $cripto->encrypt('igual'));
    }

    public function testChaveEmBase64DeTrintaEDoisBytes(): void
    {
        $chave = Encryption::generateKey();
        $this->assertStringStartsWith('base64:', $chave);
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen(base64_decode(substr($chave, 7))));

        $cripto = new Encryption($chave);
        $this->assertSame('segredo', $cripto->decrypt($cripto->encrypt('segredo')));
    }

    public function testMesmaChaveEmOutraInstanciaDecifra(): void
    {
        $cifrado = (new Encryption(self::CHAVE_TEXTO))->encrypt('compartilhado');

        $this->assertSame('compartilhado', (new Encryption(self::CHAVE_TEXTO))->decrypt($cifrado));
    }

    public function testChaveDiferenteNaoDecifra(): void
    {
        $cifrado = (new Encryption(self::CHAVE_TEXTO))->encrypt('segredo');
        $outra = new Encryption(self::OUTRA_CHAVE);

        $this->expectException(RuntimeException::class);
        $outra->decrypt($cifrado);
    }

    public function testChaveDeTrintaEDoisBytesCrusEhUsadaDireto(): void
    {
        $crua = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $cifrado = (new Encryption($crua))->encrypt('dado');

        // A mesma chave em base64 precisa decifrar: as duas formas resultam nos mesmos 32 bytes.
        $this->assertSame('dado', (new Encryption('base64:' . base64_encode($crua)))->decrypt($cifrado));
    }

    public function testTextoCifradoAdulteradoFalha(): void
    {
        $cripto = new Encryption(self::CHAVE_TEXTO);
        $bruto = base64_decode($cripto->encrypt('valor original'));

        // Inverte um bit no último byte (área do texto cifrado, depois do nonce).
        $ultimo = strlen($bruto) - 1;
        $bruto[$ultimo] = chr(ord($bruto[$ultimo]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $cripto->decrypt(base64_encode($bruto));
    }

    public function testNonceAdulteradoFalha(): void
    {
        $cripto = new Encryption(self::CHAVE_TEXTO);
        $bruto = base64_decode($cripto->encrypt('valor original'));
        $bruto[0] = chr(ord($bruto[0]) ^ 0xFF);

        $this->expectException(RuntimeException::class);
        $cripto->decrypt(base64_encode($bruto));
    }

    public function testBase64InvalidoFalha(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid encrypted data');

        (new Encryption(self::CHAVE_TEXTO))->decrypt('isto não é base64 !!!');
    }

    public function testDadoCurtoDemaisFalha(): void
    {
        $this->expectException(RuntimeException::class);

        (new Encryption(self::CHAVE_TEXTO))->decrypt(base64_encode('curto'));
    }

    public function testSerializacaoIdaEVolta(): void
    {
        $cripto = new Encryption(self::CHAVE_TEXTO);
        $dados = ['usuario' => 7, 'permissoes' => ['ler', 'escrever'], 'ativo' => true];

        $this->assertSame($dados, $cripto->decryptUnserialize($cripto->encryptSerialize($dados)));
    }

    public function testHashDeSenhaEVerificacao(): void
    {
        $hash = Encryption::hashPassword('senha-de-teste-123');

        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertNotSame('senha-de-teste-123', $hash);
        $this->assertTrue(Encryption::verifyPassword('senha-de-teste-123', $hash));
        $this->assertFalse(Encryption::verifyPassword('senha-errada', $hash));
        $this->assertSame(12, password_get_info($hash)['options']['cost']);
    }

    public function testHashDeSenhaMudaACadaChamada(): void
    {
        $this->assertNotSame(Encryption::hashPassword('mesma'), Encryption::hashPassword('mesma'));
    }

    public function testUtilitariosEstaticos(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Encryption::randomToken());
        $this->assertSame(20, strlen(Encryption::randomToken(10)));
        $this->assertSame(32, strlen(Encryption::hash('abc')));
        $this->assertSame(Encryption::hash('abc'), Encryption::hash('abc'));
        $this->assertTrue(Encryption::equals('abc', 'abc'));
        $this->assertFalse(Encryption::equals('abc', 'abd'));
    }
}
