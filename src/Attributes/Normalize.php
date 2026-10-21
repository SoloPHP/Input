<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * Normalize present input before validation and casting, including null and "".
 * The class must implement NormalizerInterface. Normalizers must be pure.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Normalize
{
    /**
     * @param string               $class Class implementing NormalizerInterface.
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $class,
        public array $config = [],
    ) {
    }
}
