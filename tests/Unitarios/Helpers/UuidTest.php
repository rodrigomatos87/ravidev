<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Helpers;

use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    private const PADRAO_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testGenerateUuidProduzV4Valido(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $uuid = generateUuid();
            $this->assertMatchesRegularExpression(self::PADRAO_V4, $uuid);
            $this->assertTrue(isValidUuid($uuid));
        }
    }

    public function testHelperUuidDoRamseyTambemEhV4(): void
    {
        $this->assertMatchesRegularExpression(self::PADRAO_V4, uuid());
        $this->assertNotSame(uuid(), uuid());
    }

    public function testUuidsGeradosSaoUnicos(): void
    {
        $gerados = array_map(fn () => generateUuid(), range(1, 500));

        $this->assertCount(500, array_unique($gerados));
    }

    public function testIsValidUuid(): void
    {
        $this->assertTrue(isValidUuid('550E8400-E29B-41D4-A716-446655440000'));
        $this->assertFalse(isValidUuid('550e8400-e29b-11d4-a716-446655440000'), 'versão 1 não é aceita');
        $this->assertFalse(isValidUuid('550e8400-e29b-41d4-c716-446655440000'), 'variante inválida');
        $this->assertFalse(isValidUuid('550e8400e29b41d4a716446655440000'));
        $this->assertFalse(isValidUuid(''));
    }

    public function testConversaoParaBytesEDeVolta(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $bytes = uuidToBytes($uuid);

        $this->assertSame(16, strlen($bytes));
        $this->assertSame($uuid, bytesToUuid($bytes));
    }

    public function testShortUuid(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-zA-Z]{8}$/', shortUuid());
        $this->assertSame(20, strlen(shortUuid(20)));
        $this->assertSame('', shortUuid(0));
    }
}
