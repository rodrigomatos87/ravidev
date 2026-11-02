<?php

declare(strict_types=1);

namespace Ravi\Agent;

/**
 * Interface para implementação de ferramentas do agente
 */
interface ToolInterface
{
    /**
     * Obtém nome da ferramenta
     */
    public function getName(): string;
    
    /**
     * Obtém descrição da ferramenta
     */
    public function getDescription(): string;
    
    /**
     * Obtém schema de input (JSON Schema)
     */
    public function getInputSchema(): array;
    
    /**
     * Executa a ferramenta
     * 
     * @param array $input Parâmetros de entrada
     * @return string Resultado da execução
     */
    public function execute(array $input): string;
    
    /**
     * Valida input antes da execução
     */
    public function validateInput(array $input): bool;
}
