<?php

declare(strict_types=1);

namespace Ravi\Services;

/**
 * Serviço de automação de navegador usando Puppeteer
 * 
 * Este serviço executa scripts Node.js/Puppeteer no container sandbox
 * para automatizar interações com navegador headless.
 */
class BrowserService
{
    private SandboxService $sandbox;
    private string $containerName;
    private bool $initialized = false;
    
    public function __construct(SandboxService $sandbox)
    {
        $this->sandbox = $sandbox;
    }
    
    /**
     * Define o container a ser usado
     */
    public function setContainer(string $containerName): self
    {
        $this->containerName = $containerName;
        return $this;
    }
    
    /**
     * Inicializa o Puppeteer no container (instala se necessário)
     */
    public function initialize(): bool
    {
        if ($this->initialized) {
            return true;
        }
        
        // Verifica se puppeteer está instalado (usando rede temporariamente)
        $result = $this->sandbox->withNetwork($this->containerName, function() {
            return $this->sandbox->execute(
                $this->containerName,
                'cd /workspace && npm list puppeteer 2>/dev/null || (npm init -y 2>/dev/null && npm install puppeteer --save 2>&1)',
                '/workspace',
                120 // 2 minutos para instalação
            );
        });
        
        if (!$result['success']) {
            logger()->error('Failed to initialize Puppeteer', ['output' => $result['output']]);
            return false;
        }
        
        $this->initialized = true;
        return true;
    }
    
    /**
     * Navega para uma URL
     */
    public function navigate(string $url, ?string $waitForSelector = null, int $timeout = 30000): array
    {
        $waitCode = $waitForSelector 
            ? "await page.waitForSelector('{$this->escapeJs($waitForSelector)}', { timeout: {$timeout} });"
            : "await page.waitForNetworkIdle({ timeout: {$timeout} }).catch(() => {});";
        
        $script = <<<JS
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu']
    });
    
    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
        
        const response = await page.goto('{$this->escapeJs($url)}', { 
            waitUntil: 'domcontentloaded',
            timeout: {$timeout}
        });
        
        {$waitCode}
        
        const title = await page.title();
        const content = await page.content();
        const finalUrl = page.url();
        
        console.log(JSON.stringify({
            success: true,
            title: title,
            url: finalUrl,
            status: response ? response.status() : 0,
            contentLength: content.length
        }));
        
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        await browser.close();
    }
})();
JS;

        return $this->runPuppeteerScript($script, $timeout / 1000 + 30);
    }
    
    /**
     * Captura screenshot de uma página
     */
    public function screenshot(
        string $url, 
        bool $fullPage = false, 
        ?string $selector = null,
        string $outputPath = '/workspace/screenshot.png'
    ): array {
        $screenshotCode = $selector
            ? "const element = await page.\$('{$this->escapeJs($selector)}');
               if (element) await element.screenshot({ path: '{$outputPath}' });
               else throw new Error('Elemento não encontrado: {$this->escapeJs($selector)}');"
            : "await page.screenshot({ path: '{$outputPath}', fullPage: " . ($fullPage ? 'true' : 'false') . " });";
        
        $script = <<<JS
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu']
    });
    
    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
        
        await page.goto('{$this->escapeJs($url)}', { 
            waitUntil: 'networkidle0',
            timeout: 30000
        });
        
        {$screenshotCode}
        
        console.log(JSON.stringify({
            success: true,
            path: '{$outputPath}',
            message: 'Screenshot saved successfully'
        }));
        
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        await browser.close();
    }
})();
JS;

        return $this->runPuppeteerScript($script, 60);
    }
    
    /**
     * Clica em um elemento
     */
    public function click(string $url, string $selector, bool $waitForNavigation = false): array
    {
        $clickAction = $waitForNavigation
            ? "await Promise.all([
                 page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
                 page.click('{$this->escapeJs($selector)}')
               ]);"
            : "await page.click('{$this->escapeJs($selector)}');";
        
        $script = <<<JS
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu']
    });
    
    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
        
        await page.goto('{$this->escapeJs($url)}', { 
            waitUntil: 'networkidle0',
            timeout: 30000
        });
        
        await page.waitForSelector('{$this->escapeJs($selector)}', { timeout: 10000 });
        {$clickAction}
        
        // Aguarda um pouco para ações completarem
        await new Promise(r => setTimeout(r, 500));
        
        const newUrl = page.url();
        
        console.log(JSON.stringify({
            success: true,
            url: newUrl,
            message: 'Click executed successfully'
        }));
        
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        await browser.close();
    }
})();
JS;

        return $this->runPuppeteerScript($script, 60);
    }
    
    /**
     * Digita em um campo de input
     */
    public function type(string $url, string $selector, string $text, bool $clearFirst = true): array
    {
        $clearCode = $clearFirst 
            ? "await page.click('{$this->escapeJs($selector)}', { clickCount: 3 });
               await page.keyboard.press('Backspace');"
            : "";
        
        $script = <<<JS
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu']
    });
    
    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
        
        await page.goto('{$this->escapeJs($url)}', { 
            waitUntil: 'networkidle0',
            timeout: 30000
        });
        
        await page.waitForSelector('{$this->escapeJs($selector)}', { timeout: 10000 });
        {$clearCode}
        await page.type('{$this->escapeJs($selector)}', '{$this->escapeJs($text)}', { delay: 30 });
        
        const value = await page.\$eval('{$this->escapeJs($selector)}', el => el.value || el.innerText);
        
        console.log(JSON.stringify({
            success: true,
            message: 'Text typed successfully',
            value: value
        }));
        
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        await browser.close();
    }
})();
JS;

        return $this->runPuppeteerScript($script, 60);
    }
    
    /**
     * Extrai texto/dados de uma página
     */
    public function extractText(string $url, ?string $selector = null): array
    {
        $extractCode = $selector
            ? "const text = await page.\$eval('{$this->escapeJs($selector)}', el => el.innerText);"
            : "const text = await page.evaluate(() => document.body.innerText);";
        
        $script = <<<JS
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu']
    });
    
    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 800 });
        
        await page.goto('{$this->escapeJs($url)}', { 
            waitUntil: 'networkidle0',
            timeout: 30000
        });
        
        {$extractCode}
        
        console.log(JSON.stringify({
            success: true,
            text: text.substring(0, 50000) // Limita tamanho
        }));
        
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        await browser.close();
    }
})();
JS;

        return $this->runPuppeteerScript($script, 60);
    }
    
    /**
     * Executa script Puppeteer no container (com acesso à rede)
     */
    private function runPuppeteerScript(string $script, int $timeout): array
    {
        if (!$this->initialize()) {
            return [
                'success' => false,
                'error' => 'Failed to initialize Puppeteer'
            ];
        }
        
        // Salva script em arquivo temporário
        $scriptFile = '/workspace/.puppeteer_' . uniqid() . '.js';
        $this->sandbox->writeFile($this->containerName, $scriptFile, $script);
        
        // Executa com acesso à rede (necessário para navegar)
        $result = $this->sandbox->withNetwork($this->containerName, function() use ($scriptFile, $timeout) {
            return $this->sandbox->execute(
                $this->containerName,
                "cd /workspace && node {$scriptFile} 2>&1",
                '/workspace',
                $timeout
            );
        });
        
        // Remove script temporário
        $this->sandbox->execute($this->containerName, "rm -f {$scriptFile}", '/workspace', 5);
        
        // Parse resultado JSON
        $output = trim($result['output'] ?? '');
        $lines = explode("\n", $output);
        $lastLine = end($lines);
        
        $jsonResult = @json_decode($lastLine, true);
        
        if ($jsonResult && is_array($jsonResult)) {
            return $jsonResult;
        }
        
        // Se não conseguiu parsear JSON, retorna output bruto
        return [
            'success' => $result['success'] ?? false,
            'output' => $output,
            'error' => ($result['success'] ?? false) ? null : 'Failed to execute Puppeteer script'
        ];
    }
    
    /**
     * Escapa string para uso em JavaScript
     */
    private function escapeJs(string $str): string
    {
        return str_replace(
            ['\\', "'", '"', "\n", "\r", "\t"],
            ['\\\\', "\\'", '\\"', '\\n', '\\r', '\\t'],
            $str
        );
    }
}
