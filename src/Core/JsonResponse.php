<?php

declare(strict_types=1);

namespace Ravi\Core;

/**
 * Response JSON para APIs
 */
class JsonResponse extends Response
{
    public function __construct(mixed $data = null, int $statusCode = 200, array $headers = [])
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $headers = array_merge([
            'Content-Type' => 'application/json; charset=UTF-8',
        ], $headers);
        
        parent::__construct($content ?: '{}', $statusCode, $headers);
    }
    
    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): self
    {
        return new self([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }
    
    public static function error(string $message, int $statusCode = 400, mixed $errors = null): self
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        return new self($response, $statusCode);
    }
    
    public static function created(mixed $data = null, string $message = 'Created'): self
    {
        return self::success($data, $message, 201);
    }
    
    public static function noContent(): self
    {
        return new self(null, 204);
    }
    
    public static function notFound(string $message = 'Resource not found'): self
    {
        return self::error($message, 404);
    }
    
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }
    
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }
    
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        return self::error($message, 422, $errors);
    }
    
    public static function serverError(string $message = 'Internal server error'): self
    {
        return self::error($message, 500);
    }
    
    /**
     * Resposta paginada
     */
    public static function paginated(
        array $items,
        int $total,
        int $page,
        int $perPage,
        string $message = 'Success'
    ): self {
        return new self([
            'success' => true,
            'message' => $message,
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }
}
