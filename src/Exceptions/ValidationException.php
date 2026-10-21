<?php

declare(strict_types=1);

namespace Solo\Input\Exceptions;

use Exception;

/**
 * @phpstan-type ValidationErrors array<string, list<array{rule: string, params?: string[]}>>
 */
final class ValidationException extends Exception
{
    /**
     * @param ValidationErrors $errors
     */
    public function __construct(
        private readonly array $errors = [],
        ?\Throwable $previous = null
    ) {
        $message = empty($errors)
            ? "Validation failed"
            : "Validation failed: " . implode(', ', array_keys($errors));
        $code = 422;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return ValidationErrors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
