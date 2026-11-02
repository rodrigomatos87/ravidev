<?php

declare(strict_types=1);

namespace Ravi\Core;

/**
 * Validação de dados
 */
class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];
    private array $validated = [];
    
    private static array $messages = [
        'required' => 'O campo :field é obrigatório.',
        'email' => 'O campo :field deve ser um email válido.',
        'min' => 'O campo :field deve ter no mínimo :param caracteres.',
        'max' => 'O campo :field deve ter no máximo :param caracteres.',
        'numeric' => 'O campo :field deve ser numérico.',
        'integer' => 'O campo :field deve ser um número inteiro.',
        'string' => 'O campo :field deve ser texto.',
        'array' => 'O campo :field deve ser uma lista.',
        'confirmed' => 'A confirmação de :field não confere.',
        'unique' => 'Este :field já está em uso.',
        'exists' => 'O :field selecionado é inválido.',
        'in' => 'O :field selecionado é inválido.',
        'url' => 'O campo :field deve ser uma URL válida.',
        'date' => 'O campo :field deve ser uma data válida.',
        'regex' => 'O formato do campo :field é inválido.',
        'same' => 'Os campos :field e :param devem ser iguais.',
        'different' => 'Os campos :field e :param devem ser diferentes.',
        'boolean' => 'O campo :field deve ser verdadeiro ou falso.',
        'alpha' => 'O campo :field deve conter apenas letras.',
        'alpha_num' => 'O campo :field deve conter apenas letras e números.',
        'between' => 'O campo :field deve estar entre :param.',
    ];
    
    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->validate();
    }
    
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }
    
    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->getValue($field);
            
            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
            
            // Se passou validação, adiciona aos validados
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }
    }
    
    private function getValue(string $field): mixed
    {
        // Suporta notação de ponto
        $keys = explode('.', $field);
        $value = $this->data;
        
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    private function applyRule(string $field, mixed $value, string $rule): void
    {
        // Parse rule:param
        $param = null;
        if (str_contains($rule, ':')) {
            [$rule, $param] = explode(':', $rule, 2);
        }
        
        // Skip se não é required e valor é vazio
        if ($rule !== 'required' && $this->isEmpty($value)) {
            return;
        }
        
        $method = 'validate' . str_replace('_', '', ucwords($rule, '_'));
        
        if (!method_exists($this, $method)) {
            throw new \InvalidArgumentException("Unknown validation rule: {$rule}");
        }
        
        if (!$this->$method($field, $value, $param)) {
            $this->addError($field, $rule, $param);
        }
    }
    
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
    
    private function addError(string $field, string $rule, ?string $param = null): void
    {
        $message = self::$messages[$rule] ?? "O campo :field é inválido.";
        $message = str_replace(':field', $field, $message);
        
        if ($param !== null) {
            $message = str_replace(':param', $param, $message);
        }
        
        $this->errors[$field] = $this->errors[$field] ?? [];
        $this->errors[$field][] = $message;
    }
    
    // ========================================
    // Regras de validação
    // ========================================
    
    private function validateRequired(string $field, mixed $value, ?string $param): bool
    {
        return !$this->isEmpty($value);
    }

    /** 'nullable' / 'sometimes': campo opcional — sempre passa (a checagem de vazio é feita no applyRule). */
    private function validateNullable(string $field, mixed $value, ?string $param): bool
    {
        return true;
    }

    private function validateSometimes(string $field, mixed $value, ?string $param): bool
    {
        return true;
    }

    private function validateEmail(string $field, mixed $value, ?string $param): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    private function validateMin(string $field, mixed $value, ?string $param): bool
    {
        $min = (int) $param;
        
        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }
        
        if (is_array($value)) {
            return count($value) >= $min;
        }
        
        if (is_numeric($value)) {
            return $value >= $min;
        }
        
        return false;
    }
    
    private function validateMax(string $field, mixed $value, ?string $param): bool
    {
        $max = (int) $param;
        
        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }
        
        if (is_array($value)) {
            return count($value) <= $max;
        }
        
        if (is_numeric($value)) {
            return $value <= $max;
        }
        
        return false;
    }
    
    private function validateNumeric(string $field, mixed $value, ?string $param): bool
    {
        return is_numeric($value);
    }
    
    private function validateInteger(string $field, mixed $value, ?string $param): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
    
    private function validateString(string $field, mixed $value, ?string $param): bool
    {
        return is_string($value);
    }
    
    private function validateArray(string $field, mixed $value, ?string $param): bool
    {
        return is_array($value);
    }
    
    private function validateConfirmed(string $field, mixed $value, ?string $param): bool
    {
        $confirmation = $this->getValue($field . '_confirmation');
        return $value === $confirmation;
    }
    
    private function validateUrl(string $field, mixed $value, ?string $param): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    
    private function validateDate(string $field, mixed $value, ?string $param): bool
    {
        $format = $param ?? 'Y-m-d';
        $date = \DateTime::createFromFormat($format, $value);
        
        return $date && $date->format($format) === $value;
    }
    
    private function validateIn(string $field, mixed $value, ?string $param): bool
    {
        $allowed = explode(',', $param);
        return in_array($value, $allowed, true);
    }
    
    private function validateRegex(string $field, mixed $value, ?string $param): bool
    {
        return preg_match($param, $value) === 1;
    }
    
    private function validateSame(string $field, mixed $value, ?string $param): bool
    {
        return $value === $this->getValue($param);
    }
    
    private function validateDifferent(string $field, mixed $value, ?string $param): bool
    {
        return $value !== $this->getValue($param);
    }
    
    private function validateBoolean(string $field, mixed $value, ?string $param): bool
    {
        return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }
    
    private function validateAlpha(string $field, mixed $value, ?string $param): bool
    {
        return preg_match('/^[\pL\pM]+$/u', $value) === 1;
    }
    
    private function validateAlphaNum(string $field, mixed $value, ?string $param): bool
    {
        return preg_match('/^[\pL\pM\pN]+$/u', $value) === 1;
    }
    
    private function validateBetween(string $field, mixed $value, ?string $param): bool
    {
        [$min, $max] = explode(',', $param);
        
        return $this->validateMin($field, $value, $min) 
            && $this->validateMax($field, $value, $max);
    }
    
    // ========================================
    // API pública
    // ========================================
    
    public function passes(): bool
    {
        return empty($this->errors);
    }
    
    public function fails(): bool
    {
        return !$this->passes();
    }
    
    public function errors(): array
    {
        return $this->errors;
    }
    
    public function firstError(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field][0] ?? null;
        }
        
        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }
        
        return null;
    }
    
    public function validated(): array
    {
        return $this->validated;
    }
    
    public static function setMessage(string $rule, string $message): void
    {
        self::$messages[$rule] = $message;
    }
}
