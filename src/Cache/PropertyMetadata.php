<?php

declare(strict_types=1);

namespace Solo\Input\Cache;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use ReflectionProperty;
use Solo\Input\Contracts\GeneratorInterface;
use Solo\Input\FieldSource;
use Solo\Input\Pipeline\TypeGuard;

/**
 * Immutable per-field input binding and type information.
 * Null source uses the handle method's default. Normalizers prepare raw input;
 * one built-in/custom decoder produces its type; post-processors receive that
 * type only after the whole tree is valid. `blank` describes how a client
 * empty string is read before decoding. `appSupplied` marks a value the
 * application supplies — context or a generator — instead of the client.
 *
 * `resultGuard` is set where something other than a built-in decoder produces
 * the value — a custom caster, a post-processor, the application (context, a
 * generator) — to check that value, and for a type decoded member by member,
 * where `memberGuard` is the same guard. It is null where a built-in decoder
 * or child construction already yields the declared type, and for an untyped
 * or `mixed` property, which accepts anything. `producedItems` is the
 * `#[Items]` class of a list something other than the handler builds —
 * context, a generator, a post-processor — whose members are checked.
 *
 * @internal
 */
final readonly class PropertyMetadata
{
    /**
     * Whether the handler builds DTOs from the value: a nested object or an
     * `#[Items]` list.
     */
    public bool $buildsChildren;

    /**
     * @param array<string, mixed>            $normalizeConfig
     * @param array<string, mixed>            $casterConfig
     * @param array<string, mixed>            $postProcessConfig
     * @param class-string<GeneratorInterface>|null $generator
     * @param array<string, mixed>            $generatorOptions
     * @param class-string|null               $items
     * @param list<string>|null               $pathSegments
     * @param class-string<BackedEnum>|null   $enumClass
     * @param array{rule: string, params?: list<string>}|null $castFailure
     * @param class-string<DateTime|DateTimeImmutable>|null    $dateClass
     * @param class-string|null                                $nested
     * @param class-string|null                                $producedItems
     */
    public function __construct(
        public string $name,
        public ?FieldSource $source,
        public string $sourceKey,
        public bool $appSupplied,
        public ?string $type,
        public bool $isNullable,
        public bool $hasDefault,
        public mixed $defaultValue,
        public ?string $validationRules,
        public ?string $effectiveCastType,
        public ?string $customCasterClass,
        public ?string $normalizer,
        public array $normalizeConfig,
        public array $casterConfig,
        public ?string $postProcessor,
        public array $postProcessConfig,
        public ?string $group,
        public bool $isRequired,
        public ReflectionProperty $reflection,
        public ?string $generator,
        public array $generatorOptions,
        public bool $exclude,
        public ?string $items,
        public ?array $pathSegments,
        public ?string $enumClass,
        public ?array $castFailure,
        public ?string $groupMapTo,
        public ?string $dateClass,
        public ?string $dateFormat,
        public ?string $nested,
        public bool $emptyToNull,
        public bool $noTrim,
        public ?TypeGuard $resultGuard,
        public ?TypeGuard $memberGuard,
        public ?string $producedItems,
        public mixed $blank,
    ) {
        $this->buildsChildren = $items !== null || $nested !== null;
    }
}
