<?php

declare(strict_types=1);

namespace Ravi\Agent;

use Ravi\Agent\Tools\{
    BashExecuteTool,
    FileReadTool,
    FileWriteTool,
    FileListTool,
    WebSearchTool,
    WebFetchTool,
    BrowserNavigateTool,
    BrowserScreenshotTool,
    BrowserClickTool,
    BrowserTypeTool,
    PythonExecuteTool,
    NodeExecuteTool,
    TaskCompleteTool,
    PrepareDeliveryTool,
    ExtractProjectTool,
    SystemTool
};

/**
 * Registro central de ferramentas disponíveis para o agente
 * 
 * Ferramentas disponíveis:
 * - Execução: bash_execute, python_execute, node_execute, system_execute
 * - Arquivos: file_read, file_write, file_list, extract_project
 * - Web: web_search, web_fetch
 * - Browser: browser_navigate, browser_screenshot, browser_click, browser_type
 * - Controle: task_complete, prepare_delivery
 */
class ToolRegistry
{
    private array $tools = [];
    private static ?self $instance = null;
    
    public function __construct()
    {
        $this->registerDefaultTools();
    }
    
    /**
     * Obtém instância singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Registra ferramentas padrão
     */
    private function registerDefaultTools(): void
    {
        $tools = [
            // Execução de código e sistema
            new BashExecuteTool(),
            new PythonExecuteTool(),
            new NodeExecuteTool(),
            new SystemTool(),  // Autonomia total no sandbox
            
            // Manipulação de arquivos
            new FileReadTool(),
            new FileWriteTool(),
            new FileListTool(),
            new ExtractProjectTool(),  // Extrai ZIPs do usuário
            
            // Web
            new WebSearchTool(),
            new WebFetchTool(),
            
            // Browser automation
            new BrowserNavigateTool(),
            new BrowserScreenshotTool(),
            new BrowserClickTool(),
            new BrowserTypeTool(),
            
            // Controle e entrega
            new TaskCompleteTool(),
            new PrepareDeliveryTool()  // Prepara entrega final
        ];
        
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }
    
    /**
     * Registra uma ferramenta
     */
    public function register(ToolInterface $tool): self
    {
        $this->tools[$tool->getName()] = $tool;
        return $this;
    }
    
    /**
     * Remove uma ferramenta
     */
    public function unregister(string $name): self
    {
        unset($this->tools[$name]);
        return $this;
    }
    
    /**
     * Verifica se ferramenta existe
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }
    
    /**
     * Obtém uma ferramenta
     */
    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }
    
    /**
     * Lista todas as ferramentas
     */
    public function all(): array
    {
        return $this->tools;
    }
    
    /**
     * Obtém nomes de todas as ferramentas
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }
    
    /**
     * Obtém definições para a API Claude
     */
    public function getApiDefinitions(): array
    {
        $definitions = [];
        
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'input_schema' => $tool->getInputSchema()
            ];
        }
        
        return $definitions;
    }
    
    /**
     * Obtém definição de uma ferramenta específica
     */
    public function getApiDefinition(string $name): ?array
    {
        $tool = $this->get($name);
        
        if (!$tool) {
            return null;
        }
        
        return [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'input_schema' => $tool->getInputSchema()
        ];
    }
    
    /**
     * Filtra ferramentas por nomes
     */
    public function only(array $names): array
    {
        $filtered = [];
        
        foreach ($names as $name) {
            if (isset($this->tools[$name])) {
                $filtered[$name] = $this->tools[$name];
            }
        }
        
        return $filtered;
    }
    
    /**
     * Exclui ferramentas por nomes
     */
    public function except(array $names): array
    {
        return array_diff_key($this->tools, array_flip($names));
    }
}
