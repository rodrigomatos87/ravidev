<?php

declare(strict_types=1);

namespace Ravi\Tests\Unitarios\Apoio;

/** Cria e apaga pastas temporárias para os testes que precisam de arquivos em disco. */
trait PastaTemporaria
{
    /** @var list<string> */
    private array $pastasCriadas = [];

    protected function novaPasta(string $rotulo = 'teste'): string
    {
        $pasta = sys_get_temp_dir() . '/ravi-' . $rotulo . '-' . bin2hex(random_bytes(6));
        mkdir($pasta, 0700, true);
        $this->pastasCriadas[] = $pasta;
        return $pasta;
    }

    protected function escrever(string $caminho, string $conteudo): void
    {
        if (!is_dir(dirname($caminho))) {
            mkdir(dirname($caminho), 0700, true);
        }
        file_put_contents($caminho, $conteudo);
    }

    protected function apagarPastas(): void
    {
        foreach ($this->pastasCriadas as $pasta) {
            self::apagarRecursivo($pasta);
        }
        $this->pastasCriadas = [];
    }

    private static function apagarRecursivo(string $pasta): void
    {
        if (!is_dir($pasta)) {
            return;
        }
        $itens = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($itens as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($pasta);
    }
}
