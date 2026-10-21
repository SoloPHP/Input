<?php

declare(strict_types=1);

namespace Solo\Input\Pipeline;

use Solo\Input\Cache\PropertyMetadata;
use Solo\Input\Cache\InputMetadata;
use Solo\Input\Casters\BuiltInCaster;
use Solo\Input\Contracts\GeneratorInterface;
use Solo\Input\Exceptions\FieldValidationException;
use Solo\Input\FieldSource;
use Solo\Input\InputBags;
use Solo\Input\ProcessContext;

/**
 * Decodes and checks field values, up to the constructor call that receives
 * them.
 *
 * Client input failures become field errors; invalid application values and
 * handler results raise exceptions. Tree traversal and stage order belong to
 * InputFactory, while scalar conversions belong to BuiltInCaster.
 *
 * @internal
 */
final class FieldValueResolver
{
    private readonly BuiltInCaster $builtInCaster;

    public function __construct(private readonly ProcessorRegistry $processors)
    {
        $this->builtInCaster = new BuiltInCaster();
    }

    /**
     * Run the property's normalizer on raw input. A field rejection sets
     * `$error`; the caller has checked that a normalizer is configured.
     *
     * @param array{rule: string, params?: list<string>}|null $error
     * @param-out array{rule: string, params?: list<string>}|null $error
     */
    public function normalize(
        PropertyMetadata $property,
        mixed $value,
        string $className,
        InputBags $bags,
        ?array &$error,
    ): mixed {
        $error = null;
        /** @var string $normalizer */
        $normalizer = $property->normalizer;
        try {
            return $this->processors->normalize(
                $normalizer,
                $value,
                new ProcessContext($property->normalizeConfig, $bags->route, $bags->context),
            );
        } catch (FieldValidationException $e) {
            $error = $this->fieldFailure($property, $className, $e);
            return null;
        }
    }

    /**
     * Apply input absence policy once, then exactly one decoder.
     * A caster's result is checked, never decoded a second time.
     *
     * @param array{rule: string, params?: list<string>}|null $error
     * @param-out array{rule: string, params?: list<string>}|null $error
     */
    public function decode(
        PropertyMetadata $property,
        mixed $value,
        string $className,
        InputBags $bags,
        ?array &$error,
    ): mixed {
        $error = null;
        // The metadata settled what a client's "" is: the application's "" is
        // a value, and so is "" wherever `blank` keeps it.
        if ($value === '' && $property->blank !== '') {
            if ($property->blank !== null) {
                return $property->blank;
            }
            $value = null;
        }
        if ($value === null) {
            return $this->nullValue($property, $className, $error);
        }

        if ($property->customCasterClass !== null) {
            try {
                $value = $this->processors->cast(
                    $property->customCasterClass,
                    $value,
                    new ProcessContext($property->casterConfig, $bags->route, $bags->context),
                );
            } catch (FieldValidationException $e) {
                $error = $this->fieldFailure($property, $className, $e);
                return null;
            }
            $this->assertResult($property, $value, $className, 'Caster', $property->customCasterClass);
            return $value;
        }
        if ($property->appSupplied) {
            return $this->castTrusted($property, $value, $className);
        }
        return $property->buildsChildren ? $value : $this->castBuiltIn($value, $property, $error);
    }

    /**
     * Run the property's post-processor on a decoded value and check what it
     * returned.
     */
    public function postProcess(PropertyMetadata $property, string $handler, mixed $value, ResolvedInput $node): mixed
    {
        $value = $this->processors->postProcess(
            $handler,
            $value,
            new ProcessContext($property->postProcessConfig, $node->route, $node->context),
        );
        $this->assertResult($property, $value, $node->metadata->className, 'PostProcess', $handler);

        return $value;
    }

    /**
     * Run the property's generator; its value is checked like any other the
     * application supplies.
     *
     * @param class-string<GeneratorInterface> $generatorClass
     */
    public function generate(PropertyMetadata $property, string $generatorClass, string $className): mixed
    {
        $value = $this->processors->runGenerator($generatorClass, $property->generatorOptions);

        return $this->castTrusted($property, $value, $className);
    }

    /**
     * Check what something other than a built-in decoder produced: a handler
     * — `#[$attribute($handler)]` — or the application.
     */
    private function assertResult(
        PropertyMetadata $property,
        mixed $value,
        string $className,
        ?string $attribute = null,
        ?string $handler = null,
    ): void {
        if ($property->resultGuard?->accepts($value) === false) {
            throw $this->wrongType(
                $property,
                $value,
                $className,
                $attribute === null ? null : "#[{$attribute}({$handler})] returned",
            );
        }
        if ($property->producedItems !== null) {
            $this->checkItems($property, $property->producedItems, $value, $className);
        }
    }

    /**
     * Cast a value the application supplied (context, generator). One that
     * does not fit the declared type is a programming error, not a client
     * mistake, so it raises instead of becoming a validation error. A
     * context value is checked in the resolve pass — after the
     * normalizers, before any post-processor or generator runs —
     * a generated one as its generator returns.
     */
    private function castTrusted(PropertyMetadata $property, mixed $value, string $className): mixed
    {
        if ($value === null) {
            return $this->nullValue($property, $className, $error);
        }
        $cast = $this->castBuiltIn($value, $property, $error);
        if ($error !== null) {
            throw $this->doesNotFit($property, $className, $error['rule']);
        }
        $this->assertResult($property, $cast, $className);

        return $cast;
    }

    /**
     * Call the constructor with the decoded values as named arguments; a
     * field the input lacked takes its default. A list something other than
     * the factory built is checked again: its members may be references
     * another handler changed after the first check.
     *
     * @param array<string, mixed> $values
     */
    public function instantiate(InputMetadata $metadata, array $values): object
    {
        foreach ($values as $name => $value) {
            $property = $metadata->properties[$name];
            if ($property->producedItems !== null) {
                $this->checkItems($property, $property->producedItems, $value, $metadata->className);
            }
        }

        return $metadata->reflection->newInstanceArgs($values);
    }

    /**
     * What a field the input lacks and the constructor has no default for
     * means: `required` for the client — or, where the application supplies
     * the value, a programming error.
     *
     * @return array{rule: string}
     */
    public function missing(PropertyMetadata $property, string $className): array
    {
        if ($property->appSupplied) {
            throw new \UnexpectedValueException(sprintf(
                "%s is missing, but %s::\$%s has no default.",
                $this->describeValue($property),
                $className,
                $property->name,
            ));
        }

        return ['rule' => 'required'];
    }

    /**
     * Built-in, type-driven and enum casts of a non-null value — the
     * conversions that can reject client input. A value that cannot be
     * converted without loss sets `$error` (the metadata's `castFailure`)
     * instead of becoming `0`, `1`, `null` or an uninitialised property.
     *
     * @param array{rule: string, params?: list<string>}|null     $error
     * @param-out array{rule: string, params?: list<string>}|null $error
     */
    private function castBuiltIn(mixed $value, PropertyMetadata $property, ?array &$error): mixed
    {
        $error = null;

        // Each reader here has its `blank` arm in MetadataBuilder.
        $cast = match (true) {
            $property->enumClass !== null => $this->builtInCaster->castEnum($property->enumClass, $value),
            $property->dateClass !== null => $this->builtInCaster->castDate(
                $property->dateClass,
                $property->dateFormat,
                $value
            ),
            $property->effectiveCastType !== null => $this->builtInCaster->cast($property->effectiveCastType, $value),
            $property->memberGuard !== null => $this->builtInCaster->castToMembers($property->memberGuard, $value),
            default => $value,
        };

        // "" is read like any other value: what the client's "" means was
        // settled by the property's `blank`; the application's is a value.
        if ($cast === null) {
            $error = $property->castFailure;
        }

        return $cast;
    }

    /**
     * What null means for the property: null itself, its default or — where
     * the application supplies the value — an exception. A client's null
     * otherwise fails `required`.
     *
     * @param array{rule: string, params?: list<string>}|null $error
     * @param-out array{rule: string, params?: list<string>}|null $error
     */
    private function nullValue(PropertyMetadata $property, string $className, ?array &$error): mixed
    {
        if ($property->isNullable) {
            return null;
        }
        if ($property->hasDefault) {
            return $property->defaultValue;
        }
        if ($property->appSupplied) {
            throw $this->emptyValue($property, $className);
        }
        $error = ['rule' => 'required'];

        return null;
    }

    /**
     * A handler's field rejection: the client's error, or — for a value the
     * application supplied — a programming error.
     *
     * @return array{rule: string, params?: list<string>}
     */
    private function fieldFailure(
        PropertyMetadata $property,
        string $className,
        FieldValidationException $error,
    ): array {
        if ($property->appSupplied) {
            throw $this->doesNotFit($property, $className, $error->rule, $error);
        }
        return $error->error();
    }

    private function emptyValue(PropertyMetadata $property, string $className): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf(
            "%s is empty, but %s::\$%s is neither nullable nor has a default.",
            $this->describeValue($property),
            $className,
            $property->name,
        ));
    }

    private function doesNotFit(
        PropertyMetadata $property,
        string $className,
        string $rule,
        ?\Throwable $previous = null,
    ): \UnexpectedValueException {
        return new \UnexpectedValueException(sprintf(
            "%s does not fit %s::\$%s (%s).",
            $this->describeValue($property),
            $className,
            $property->name,
            $rule,
        ), 0, $previous);
    }

    /**
     * @param string|null $subject What produced the value, with its verb;
     *                             by default the value's origin and "is".
     */
    private function wrongType(
        PropertyMetadata $property,
        mixed $value,
        string $className,
        ?string $subject = null,
        ?\Throwable $previous = null,
    ): \UnexpectedValueException {
        return new \UnexpectedValueException(sprintf(
            "%s %s, which does not fit %s::\$%s (%s).",
            $subject ?? $this->describeValue($property) . ' is',
            get_debug_type($value),
            $className,
            $property->name,
            (string) $property->type,
        ), 0, $previous);
    }

    /**
     * Where a value that does not fit its property came from — for the
     * exception: its generator, the context, or the input.
     */
    private function describeValue(PropertyMetadata $property): string
    {
        return match (true) {
            $property->generator !== null => "Value generated by {$property->generator}",
            $property->source === FieldSource::Context => "Context value '{$property->sourceKey}'",
            default => "Value of '{$property->sourceKey}'",
        };
    }

    /**
     * `#[Items]` on a list something other than the factory built — context,
     * a generator, a post-processor — names the class of its members: each
     * element is checked, none is built from an array.
     *
     * @param class-string $itemClass
     */
    private function checkItems(
        PropertyMetadata $property,
        string $itemClass,
        mixed $value,
        string $className,
    ): void {
        // A nullable list may be null.
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!$item instanceof $itemClass) {
                    throw new \UnexpectedValueException(sprintf(
                        "%s holds %s at key %s, but %s::\$%s lists %s.",
                        $this->describeValue($property),
                        get_debug_type($item),
                        $key,
                        $className,
                        $property->name,
                        $itemClass,
                    ));
                }
            }
        }
    }
}
