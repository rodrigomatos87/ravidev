<?php

declare(strict_types=1);

namespace Ravi\Core;

use Exception;

/**
 * HTTP Exception
 * Used for HTTP error responses
 */
class HttpException extends Exception
{
    protected int $statusCode;
    protected array $headers = [];

    public function __construct(int $statusCode = 500, string $message = '', array $headers = [], ?Exception $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        
        if (empty($message)) {
            $message = $this->getDefaultMessage($statusCode);
        }
        
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    protected function getDefaultMessage(int $statusCode): string
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];
        
        return $messages[$statusCode] ?? 'Unknown Error';
    }

    /**
     * Factory methods for common HTTP errors
     */
    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function unauthorized(string $message = ''): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function methodNotAllowed(string $message = ''): self
    {
        return new self(405, $message);
    }

    public static function unprocessableEntity(string $message = ''): self
    {
        return new self(422, $message);
    }

    public static function tooManyRequests(string $message = ''): self
    {
        return new self(429, $message);
    }

    public static function serverError(string $message = ''): self
    {
        return new self(500, $message);
    }
}
