<?php

declare(strict_types=1);

/**
 * Consolida no lang/pt.json os textos padrão escritos inline nas views.
 *
 * Varre views/ e modulos/*\/views atrás de chamadas t('chave', 'padrão') (e dos atalhos
 * $entT('chave', 'padrão') e ['chave', 'padrão'] das páginas de erro) e:
 *   - chave nova entra no pt.json com o padrão inline;
 *   - chave existente mantém o valor atual (nunca sobrescreve);
 *   - chaves do pt.json que não aparecem no código são preservadas e listadas como órfãs;
 *   - chaves usadas sem padrão literal são listadas;
 *   - a mesma chave com padrões diferentes no código é listada como conflito.
 * O arquivo sai ordenado por chave.
 *
 * Uso: php bin/consolidar-textos.php [--simular]
 */

$raiz = dirname(__DIR__);
$arquivoPt = $raiz . '/lang/pt.json';
$simular = in_array('--simular', $argv ?? [], true);

/** Lista recursiva dos .php de um diretório. */
$listarPhp = static function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $saida = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $arq) {
        if ($arq->isFile() && strtolower($arq->getExtension()) === 'php') {
            $saida[] = $arq->getPathname();
        }
    }
    sort($saida);
    return $saida;
};

/** Converte o literal PHP (com aspas) no texto real; null se tiver interpolação. */
$desliteral = static function (string $lit): ?string {
    $aspa = $lit[0];
    $miolo = substr($lit, 1, -1);
    if ($aspa === "'") {
        return strtr($miolo, ["\\\\" => "\\", "\\'" => "'"]);
    }
    if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*\$[A-Za-z_{]/', $miolo)) {
        return null;
    }
    return stripcslashes(str_replace('\\$', '$', $miolo));
};

$arquivos = $listarPhp($raiz . '/views');
foreach (glob($raiz . '/modulos/*/views', GLOB_ONLYDIR) ?: [] as $dirModulo) {
    $arquivos = array_merge($arquivos, $listarPhp($dirModulo));
}

$str = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
// t( isolado (não ->t, não $t, não layout() e o atalho $entT( das telas de entrada.
$reChamada = '/(?:(?<![\w$>])t|\$entT)\(\s*' . $str . '\s*(?:(,)\s*(?:' . $str . '\s*(?=[,)]))?|(?=\)))/s';
// Pares ['chave', 'padrão'] das páginas de erro (views/errors).
$rePar = '/=>\s*\[\s*' . $str . '\s*,\s*' . $str . '\s*\]/s';

$encontradas = [];   // chave => padrão
$semPadrao = [];     // chave => [arquivo:linha]
$conflitos = [];     // chave => [padrões]
$usos = 0;

$registrar = static function (string $chave, ?string $padrao, string $onde) use (&$encontradas, &$semPadrao, &$conflitos, &$usos): void {
    $usos++;
    if ($padrao === null) {
        $semPadrao[$chave][] = $onde;
        return;
    }
    if (isset($encontradas[$chave]) && $encontradas[$chave] !== $padrao) {
        $conflitos[$chave] = array_values(array_unique(array_merge($conflitos[$chave] ?? [$encontradas[$chave]], [$padrao])));
        return;
    }
    $encontradas[$chave] = $padrao;
};

foreach ($arquivos as $arquivo) {
    $codigo = (string) file_get_contents($arquivo);
    $relativo = substr($arquivo, strlen($raiz) + 1);
    $linhaDe = static fn(int $pos): int => substr_count($codigo, "\n", 0, $pos) + 1;

    if (preg_match_all($reChamada, $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $c) {
            $chave = $desliteral($c[1][0]);
            if ($chave === null || $chave === '') {
                continue;
            }
            $padrao = isset($c[3]) && $c[3][1] >= 0 ? $desliteral($c[3][0]) : null;
            $registrar($chave, $padrao, $relativo . ':' . $linhaDe($c[0][1]));
        }
    }
    if (str_starts_with($relativo, 'views/errors/') && preg_match_all($rePar, $codigo, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $c) {
            $chave = $desliteral($c[1][0]);
            $padrao = $desliteral($c[2][0]);
            if ($chave !== null && preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/i', $chave)) {
                $registrar($chave, $padrao, $relativo . ':' . $linhaDe($c[0][1]));
            }
        }
    }
}

$atual = [];
if (is_file($arquivoPt)) {
    $atual = json_decode((string) file_get_contents($arquivoPt), true);
    if (!is_array($atual)) {
        fwrite(STDERR, "ERRO: lang/pt.json não é um JSON válido. Nada foi alterado.\n");
        exit(1);
    }
}

$novas = array_diff_key($encontradas, $atual);
$final = $atual + $novas;
ksort($final, SORT_STRING);

$usadas = $encontradas + $semPadrao + $conflitos;
$orfas = array_keys(array_diff_key($atual, $usadas));
sort($orfas, SORT_STRING);
$semPadraoReal = array_diff_key($semPadrao, $final);

echo "Arquivos varridos: " . count($arquivos) . "\n";
echo "Chamadas encontradas: {$usos}\n";
echo "Chaves distintas com padrão no código: " . count($encontradas) . "\n";
echo "Chaves no pt.json antes: " . count($atual) . "\n";
echo "Chaves novas: " . count($novas) . "\n";
echo "Chaves no pt.json depois: " . count($final) . "\n";

echo "\nÓrfãs (no pt.json, sem uso nas views; preservadas): " . count($orfas) . "\n";
foreach ($orfas as $k) {
    echo "  {$k}\n";
}

echo "\nUsadas sem padrão literal: " . count($semPadrao) . "\n";
foreach ($semPadrao as $k => $onde) {
    $situacao = isset($final[$k]) ? 'tem valor no pt.json' : 'SEM valor no pt.json';
    echo "  {$k} ({$situacao}) em " . implode(', ', array_unique($onde)) . "\n";
}

echo "\nMesma chave com padrões diferentes no código: " . count($conflitos) . "\n";
foreach ($conflitos as $k => $variantes) {
    echo "  {$k}: " . implode(' | ', array_map(static fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE), $variantes)) . "\n";
}

if ($simular) {
    echo "\nModo simulação: lang/pt.json não foi gravado.\n";
    exit(0);
}

$json = json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "ERRO ao gerar o JSON: " . json_last_error_msg() . "\n");
    exit(1);
}
// Mantém o recuo de 2 espaços do arquivo.
$json = preg_replace_callback('/^( {4})+/m', static fn($x) => str_repeat('  ', intdiv(strlen($x[0]), 4)), $json);
file_put_contents($arquivoPt, $json . "\n");
echo "\nlang/pt.json gravado (" . count($final) . " chaves, ordenado por chave).\n";
