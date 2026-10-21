<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * Transform a non-null decoded value after the whole input tree is valid.
 * The class must implement PostProcessorInterface. Nested fields receive
 * complete DTOs; #[Items] fields receive arrays of complete DTOs.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PostProcess
{
    /**
     * @param string               $class Class implementing PostProcessorInterface.
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $class,
        public array $config = [],
    ) {
    }
}
