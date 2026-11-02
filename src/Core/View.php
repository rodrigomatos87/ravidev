<?php

declare(strict_types=1);

namespace Ravi\Core;

use RuntimeException;

/**
 * Renderização de views PHP
 */
class View
{
    private string $basePath;
    private string $extension = '.php';
    private array $shared = [];
    private ?string $layout = null;
    private array $sections = [];
    private ?string $currentSection = null;
    
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }
    
    /**
     * Compartilha variável com todas as views
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }
    
    /**
     * Renderiza uma view
     */
    public function render(string $name, array $data = []): string
    {
        $path = $this->resolvePath($name);
        
        if (!file_exists($path)) {
            throw new RuntimeException("View not found: {$name} ({$path})");
        }
        
        // Combina dados compartilhados com dados locais
        $data = array_merge($this->shared, $data);
        
        // Extrai variáveis para uso na view
        extract($data, EXTR_SKIP);
        
        // Inicia buffer
        ob_start();
        
        try {
            include $path;
            $content = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        
        // Se tiver layout definido, renderiza
        if ($this->layout !== null) {
            $layoutName = $this->layout;
            $this->layout = null; // Reset
            
            $data['content'] = $content;
            $content = $this->render($layoutName, $data);
        }
        
        return $content;
    }
    
    /**
     * Define layout para a view atual
     */
    public function extends(string $layout): void
    {
        $this->layout = $layout;
    }
   
    /**
     * Alias para extends (compatibilidade)
     */
    public function layout(string $layout, array $data = []): void
    {
        $this->layout = $layout;
        if (!empty($data)) {
            foreach ($data as $key => $value) {
                $this->share($key, $value);
            }
        }
    }

    /**
     * Inicia seção
     */
    public function section(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }
    
    /**
     * Finaliza seção
     */
    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw new RuntimeException('No section started');
        }
        
        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }
    
    /**
     * Obtém conteúdo de seção
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }
    
    /**
     * Inclui partial
     */
    public function include(string $name, array $data = []): void
    {
        echo $this->render($name, $data);
    }
    
    /**
     * Renderiza componente com slot
     */
    public function component(string $name, array $data = [], ?callable $slot = null): void
    {
        if ($slot !== null) {
            ob_start();
            $slot();
            $data['slot'] = ob_get_clean();
        }
        
        echo $this->render($name, $data);
    }
    
    /**
     * Escape HTML
     */
    public function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
    }
    
    /**
     * Resolve caminho da view
     */
    private function resolvePath(string $name): string
    {
        // View de módulo opcional: 'vpn::admin' -> modulos/vpn/views/admin.php
        if (str_contains($name, '::')) {
            [$modulo, $view] = explode('::', $name, 2);
            $base = Modulos::viewsDe($modulo);
            if ($base !== null) {
                return $base . '/' . str_replace('.', '/', $view) . $this->extension;
            }
        }

        // Converte notação de ponto para caminho
        $name = str_replace('.', '/', $name);
        
        return $this->basePath . '/' . $name . $this->extension;
    }
    
    /**
     * Verifica se view existe
     */
    public function exists(string $name): bool
    {
        return file_exists($this->resolvePath($name));
    }
    
    /**
     * Renderiza JSON
     */
    public function json(mixed $data, int $flags = 0): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags);
    }
}
