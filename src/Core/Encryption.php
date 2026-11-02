<?php

declare(strict_types=1);

namespace Ravi\Core;

use RuntimeException;
use SodiumException;

/**
 * Criptografia usando libsodium (crypto_secretbox).
 *
 * REGRA DA CHAVE (ponto único: Encryption::chaveDoAmbiente()):
 *  - A chave mestra vem de APP_KEY. ENCRYPTION_KEY é aceita como nome alternativo.
 *  - Só uma definida (não vazia): é ela que vale.
 *  - As duas definidas e IGUAIS: tanto faz, é a mesma chave.
 *  - As duas definidas e DIFERENTES: a subida é recusada. Antes desta regra o
 *    construtor preferia ENCRYPTION_KEY e seis pontos do código usavam APP_KEY, o que
 *    cifrava parte dos dados com uma chave e parte com outra. Recusar evita escolher
 *    em silêncio a chave errada e deixar segredos indecifráveis.
 *  - Nenhuma definida, vazia ou curta demais: exceção clara (antes virava um hash
 *    da string vazia, chave pública e determinística, sem erro nenhum).
 *
 * Derivação (inalterada, para manter decifráveis os dados já gravados):
 *  - "base64:..." é decodificada; o resultado (ou a string crua) é usado direto se
 *    tiver 32 bytes, senão passa por sodium_crypto_generichash para virar 32 bytes.
 *  - Mínimo exigido: 32 bytes depois da decodificação (CHAVE_MIN_BYTES).
 *    Os geradores da casa já cumprem: generateKey() (base64: de 32 bytes) e
 *    `openssl rand -base64 32` (44 caracteres).
 */
class Encryption
{
    /** Tamanho mínimo da chave mestra, em bytes, depois de decodificar "base64:". */
    public const CHAVE_MIN_BYTES = 32;

    private string $key;
    
    public function __construct(?string $key = null)
    {
        $key = $key ?? self::chaveDoAmbiente();
        $bytes = self::decodificarChave($key);

        // Garante que a chave tem 32 bytes (derivação histórica, não alterar)
        if (strlen($bytes) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $bytes = sodium_crypto_generichash($bytes, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
        
        $this->key = $bytes;
    }

    /**
     * Resolve a chave mestra a partir do ambiente (APP_KEY / ENCRYPTION_KEY) e valida.
     * Único ponto do sistema que decide qual variável vale. Lança RuntimeException
     * com mensagem em português quando a configuração é inválida.
     */
    public static function chaveDoAmbiente(): string
    {
        $appKey = self::lerVariavel('APP_KEY');
        $encKey = self::lerVariavel('ENCRYPTION_KEY');

        if ($appKey !== '' && $encKey !== '' && !hash_equals($appKey, $encKey)) {
            throw new RuntimeException(
                'Chave de criptografia em conflito: APP_KEY e ENCRYPTION_KEY estão definidas com valores '
                . 'diferentes no .env. Deixe só uma (APP_KEY) com a chave que cifrou os dados existentes.'
            );
        }

        $chave = $appKey !== '' ? $appKey : $encKey;

        if ($chave === '') {
            throw new RuntimeException(
                'Chave de criptografia ausente: defina APP_KEY no .env '
                . '(gere uma com: openssl rand -base64 32).'
            );
        }

        self::decodificarChave($chave); // valida tamanho mínimo
        return $chave;
    }

    /**
     * Lê a variável crua do ambiente (sem as conversões true/false/null do helper env()).
     * Não apara espaços: o valor é usado byte a byte na derivação, como sempre foi.
     * Só espaço em branco conta como vazio.
     */
    private static function lerVariavel(string $nome): string
    {
        $valor = $_ENV[$nome] ?? $_SERVER[$nome] ?? getenv($nome);
        return (is_string($valor) && trim($valor) !== '') ? $valor : '';
    }

    /**
     * Decodifica "base64:" (mesmo comportamento histórico) e exige o mínimo de bytes.
     */
    private static function decodificarChave(string $chave): string
    {
        $bytes = str_starts_with($chave, 'base64:')
            ? (string) base64_decode(substr($chave, 7))
            : $chave;

        if (strlen($bytes) < self::CHAVE_MIN_BYTES) {
            throw new RuntimeException(sprintf(
                'Chave de criptografia inválida: precisa de pelo menos %d bytes (veio %d). '
                . 'Use uma chave gerada por Encryption::generateKey() ou "openssl rand -base64 32".',
                self::CHAVE_MIN_BYTES,
                strlen($bytes)
            ));
        }

        return $bytes;
    }
    
    /**
     * Gera nova chave
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
    
    /**
     * Criptografa dados
     */
    public function encrypt(string $data): string
    {
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($data, $nonce, $this->key);
            
            return base64_encode($nonce . $ciphertext);
        } catch (SodiumException $e) {
            throw new RuntimeException('Encryption failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Descriptografa dados
     */
    public function decrypt(string $data): string
    {
        try {
            $decoded = base64_decode($data, true);
            
            if ($decoded === false) {
                throw new RuntimeException('Invalid encrypted data');
            }
            
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
            
            if ($plaintext === false) {
                throw new RuntimeException('Decryption failed - invalid key or corrupted data');
            }
            
            return $plaintext;
        } catch (SodiumException $e) {
            throw new RuntimeException('Decryption failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Criptografa e serializa dados
     */
    public function encryptSerialize(mixed $data): string
    {
        return $this->encrypt(serialize($data));
    }
    
    /**
     * Descriptografa e deserializa dados
     */
    public function decryptUnserialize(string $data): mixed
    {
        return unserialize($this->decrypt($data));
    }
    
    /**
     * Hash de senha
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => config('app.security.password_cost', 12),
        ]);
    }
    
    /**
     * Verifica senha
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Gera token aleatório
     */
    public static function randomToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Hash seguro para comparação
     */
    public static function hash(string $data): string
    {
        return sodium_crypto_generichash($data);
    }
    
    /**
     * Comparação segura de strings (timing-safe)
     */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
    
    /**
     * Limpa dados sensíveis da memória
     */
    public function __destruct()
    {
        try {
            sodium_memzero($this->key);
        } catch (SodiumException) {
            // Ignora erros de limpeza
        }
    }
}
