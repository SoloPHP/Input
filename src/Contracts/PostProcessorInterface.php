<?php

declare(strict_types=1);

namespace Solo\Input\Contracts;

use Solo\Input\ProcessContext;

/**
 * Transform a non-null, decoded value after the whole input tree is valid.
 * Results must fit the property type. Input rejection belongs in validation
 * or a caster; throwing a validation exception here is a contract error.
 *
 * @template T
 */
interface PostProcessorInterface
{
    /**
     * @param T $value
     * @return T
     */
    public function postProcess(mixed $value, ProcessContext $context): mixed;
}
