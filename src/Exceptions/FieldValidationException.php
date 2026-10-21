<?php

declare(strict_types=1);

namespace Solo\Input\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Reject the current field from a normalizer or caster.
 * InputFactory supplies the input path and collects it into ValidationException.
 */
final class FieldValidationException extends RuntimeException
{
    /** @param list<string> $params */
    public function __construct(
        public readonly string $rule,
        public readonly array $params = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct("Invalid field value: {$rule}", 0, $previous);
    }

    /** @return array{rule: string, params?: list<string>} */
    public function error(): array
    {
        return $this->params === [] ? ['rule' => $this->rule] : ['rule' => $this->rule, 'params' => $this->params];
    }
}
