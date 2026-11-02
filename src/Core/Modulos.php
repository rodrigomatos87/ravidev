<?php

declare(strict_types=1);

namespace Ravi\Core;

/**
 * Módulos opcionais do Ravi Developer.
 *
 * Um módulo é uma pasta em `modulos/<nome>/` com um `modulo.php` que devolve o manifesto.
 * Se a pasta não existe, o sistema sobe sem o módulo e sem nenhuma referência a ele.
 * É assim que a versão pública do código nasce da privada: o script de exportação simplesmente
 * não leva as pastas dos módulos que são só da casa (ex.: VPN de suporte, ionCube).
 *
 * Manifesto (todas as chaves são opcionais):
 *   'nome'        => 'vpn'                         identificador (= nome da pasta)
 *   'titulo'      => 'VPN de Suporte'
 *   'namespace'   => 'Ravi\\Modulos\\Vpn'           classes em modulos/<nome>/src/ (PSR-4)
 *   'rotas'       => fn(Router $r)                  rotas fora do painel (sem auth); raiz do roteador
 *   'rotas_auth'  => fn(Router $r)                  dentro do grupo autenticado (auth + csrf)
 *   'rotas_admin' => fn(Router $r)                  dentro do grupo /admin (auth + csrf + admin)
 *   'menu_admin'  => [['url' => '/admin/vpn', 'pagina' => 'admin-vpn', 'titulo' => 'VPN', 'icone_sprite' => 'cadeado', 'area' => 'configuracoes']]
 *   'views'       => __DIR__ . '/views'             views do módulo: $this->view('vpn::admin')
 *   'publico'     => __DIR__ . '/public'            estáticos servidos em /modulos/<nome>/<arquivo> (um nível)
 *   'migrations'  => __DIR__ . '/migrations'        *.sql, aplicadas junto com as do núcleo
 *   'ganchos'     => ['ide.container.env' => fn(array $ctx): array, …]
 *
 * Ganchos conhecidos:
 *   ide.container.env       (ctx: workspace, user_id, container, project_uuid) -> ['env' => [k => v], 'volumes' => [host => destino]]
 *   ide.container.removido  (container)
 */
final class Modulos
{
    /** @var array<string, array> manifestos indexados pelo nome */
    private static array $manifestos = [];
    private static bool $carregado = false;

    /** Varre `modulos/*\/modulo.php` e registra o autoload de cada um. Chamar uma vez no bootstrap. */
    public static function carregar(string $raiz): void
    {
        if (self::$carregado) {
            return;
        }
        self::$carregado = true;
        foreach (glob(rtrim($raiz, '/') . '/*/modulo.php') ?: [] as $arquivo) {
            $manifesto = require $arquivo;
            if (!is_array($manifesto)) {
                continue;
            }
            $nome = (string) ($manifesto['nome'] ?? basename(dirname($arquivo)));
            $manifesto['nome'] = $nome;
            $manifesto['pasta'] = dirname($arquivo);
            self::$manifestos[$nome] = $manifesto;
            if (!empty($manifesto['namespace'])) {
                self::autoload((string) $manifesto['namespace'], dirname($arquivo) . '/src');
            }
        }
    }

    private static function autoload(string $namespace, string $pasta): void
    {
        $prefixo = rtrim($namespace, '\\') . '\\';
        spl_autoload_register(static function (string $classe) use ($prefixo, $pasta): void {
            if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
                return;
            }
            $arquivo = $pasta . '/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        });
    }

    /** @return array<string, array> */
    public static function todos(): array
    {
        return self::$manifestos;
    }

    public static function ativo(string $nome): bool
    {
        return isset(self::$manifestos[$nome]);
    }

    public static function rotas(Router $router): void
    {
        self::registrarRotas('rotas', $router);
    }

    public static function rotasAuth(Router $router): void
    {
        self::registrarRotas('rotas_auth', $router);
    }

    public static function rotasAdmin(Router $router): void
    {
        self::registrarRotas('rotas_admin', $router);
    }

    private static function registrarRotas(string $chave, Router $router): void
    {
        foreach (self::$manifestos as $m) {
            if (isset($m[$chave]) && is_callable($m[$chave])) {
                ($m[$chave])($router);
            }
        }
    }

    /** Itens de menu do painel administrativo, na ordem de carga dos módulos. */
    public static function menuAdmin(): array
    {
        $itens = [];
        foreach (self::$manifestos as $m) {
            foreach ((array) ($m['menu_admin'] ?? []) as $item) {
                $itens[] = $item;
            }
        }
        return $itens;
    }

    /** Pasta de views de um módulo (para `modulo::view`), ou null se não há. */
    public static function viewsDe(string $nome): ?string
    {
        $pasta = self::$manifestos[$nome]['views'] ?? null;
        return is_string($pasta) && is_dir($pasta) ? rtrim($pasta, '/') : null;
    }

    /**
     * Serve um arquivo estático da pasta 'publico' de um módulo (/modulos/<nome>/<arquivo>).
     * A URL vai SEM extensão (o nginx e o php -S devolvem 404 direto para .js/.css inexistentes no
     * docroot); aqui se procura o arquivo com cada extensão conhecida. Nome simples, cache de um dia.
     */
    public static function servirEstatico(string $nome, string $arquivo): Response
    {
        $pasta = self::$manifestos[$nome]['publico'] ?? null;
        $arquivo = basename($arquivo);
        $tipos = ['js' => 'text/javascript; charset=UTF-8', 'css' => 'text/css; charset=UTF-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'json' => 'application/json', 'woff2' => 'font/woff2'];
        if (is_string($pasta) && $arquivo !== '' && preg_match('/^[a-z0-9_.-]+$/i', $arquivo)) {
            $ext = strtolower((string) pathinfo($arquivo, PATHINFO_EXTENSION));
            $candidatos = isset($tipos[$ext]) ? [$ext => $arquivo] : array_combine(array_keys($tipos), array_map(static fn($e) => $arquivo . '.' . $e, array_keys($tipos)));
            foreach ($candidatos as $e => $nomeArquivo) {
                $caminho = $pasta . '/' . $nomeArquivo;
                if (is_file($caminho)) {
                    return new Response((string) file_get_contents($caminho), 200, [
                        'Content-Type'  => $tipos[$e],
                        'Cache-Control' => 'public, max-age=86400',
                    ]);
                }
            }
        }
        return new Response('Não encontrado', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Todos os arquivos de migration dos módulos (caminhos absolutos). */
    public static function migrations(): array
    {
        $arquivos = [];
        foreach (self::$manifestos as $m) {
            $pasta = $m['migrations'] ?? null;
            if (is_string($pasta) && is_dir($pasta)) {
                foreach (glob(rtrim($pasta, '/') . '/*.sql') ?: [] as $f) {
                    $arquivos[] = $f;
                }
            }
        }
        return $arquivos;
    }

    /**
     * Dispara um gancho em todos os módulos que o implementam e devolve os retornos (um por módulo).
     * Erro num módulo é registrado e não derruba o fluxo principal.
     */
    public static function gancho(string $nome, mixed ...$args): array
    {
        $retornos = [];
        foreach (self::$manifestos as $m) {
            $fn = $m['ganchos'][$nome] ?? null;
            if (!is_callable($fn)) {
                continue;
            }
            try {
                $retornos[$m['nome']] = $fn(...$args);
            } catch (\Throwable $e) {
                // Registra sem depender da aplicação estar de pé (scripts de bin/ não têm o container):
                // o gancho nunca derruba o fluxo principal, nem quando o próprio log falha.
                try {
                    logger()->error('Modulo ' . $m['nome'] . ' falhou no gancho ' . $nome . ': ' . $e->getMessage());
                } catch (\Throwable) {
                    error_log('Modulo ' . $m['nome'] . ' falhou no gancho ' . $nome . ': ' . $e->getMessage());
                }
            }
        }
        return $retornos;
    }
}
