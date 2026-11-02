<?php

declare(strict_types=1);

namespace Ravi\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * Sistema de logs simples
 */
class Logger implements LoggerInterface
{
    private static ?self $instance = null;
    private string $path;
    private string $channel;
    private string $level = 'debug';
    
    private const LEVELS = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];
    
    public function __construct(?string $path = null, string $channel = 'app')
    {
        $this->path = $path ? rtrim($path, '/') : (defined('STORAGE_PATH') ? STORAGE_PATH . '/logs' : '/tmp');
        $this->channel = $channel;
        
        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function setLogPath(string $path): void
    {
        $this->path = rtrim($path, '/');
        if (!is_dir($this->path)) {
            @mkdir($this->path, 0755, true);
        }
    }
    
    public function setLevel(string $level): void
    {
        $this->level = strtolower($level);
    }
    
    private function shouldLog(string $level): bool
    {
        $configLevel = $this->level ?: strtolower(env('LOG_LEVEL', 'debug'));
        
        if (!isset(self::LEVELS[$configLevel]) || !isset(self::LEVELS[$level])) {
            return true;
        }
        
        return self::LEVELS[$level] <= self::LEVELS[$configLevel];
    }
    
    private function getLogFile(): string
    {
        $filename = match (env('LOG_CHANNEL', 'daily')) {
            'daily' => $this->channel . '-' . date('Y-m-d') . '.log',
            'single' => $this->channel . '.log',
            default => $this->channel . '-' . date('Y-m-d') . '.log',
        };
        
        return $this->path . '/' . $filename;
    }
    
    /**
     * Escreve log
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $message = $this->interpolate((string) $message, $context);
        
        $line = "[{$timestamp}] {$this->channel}.{$levelUpper}: {$message}";
        
        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        
        $line .= PHP_EOL;
        
        $file = $this->getLogFile();
        
        if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Failed to write to log file: {$file}");
        }
    }
    
    /**
     * Interpola placeholders na mensagem
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        
        foreach ($context as $key => $val) {
            if (is_string($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        
        return strtr($message, $replace);
    }
    
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }
    
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }
    
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }
    
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }
    
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }
    
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }
    
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }
    
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
    
    /**
     * Log em canal específico
     */
    public function channel(string $channel): self
    {
        return new self($this->path, $channel);
    }
    
    /**
     * Log com stack trace
     */
    public function exception(\Throwable $e, string $level = LogLevel::ERROR): void
    {
        $this->log($level, $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
