<?php

declare(strict_types=1);

namespace Solo\Input;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\Input\Cache\PropertyMetadata;
use Solo\Input\Cache\ReflectionCache;
use Solo\Input\Exceptions\ValidationException;
use Solo\Input\Pipeline\ErrorMapper;
use Solo\Input\Pipeline\FieldValueResolver;
use Solo\Input\Pipeline\ProcessorRegistry;
use Solo\Input\Pipeline\ProvidedFields;
use Solo\Input\Pipeline\ResolvedInput;
use Solo\Input\Schema\SchemaBuilder;

/**
 * Builds input DTOs — `#[Input]` classes — from HTTP requests and arrays.
 *
 * Each field's source (body / query / route / context) is decided by its
 * attributes (`#[FromRoute]`, `#[FromContext]`, `#[FromBody]`). Without a
 * source attribute, the default is determined by the method invoked:
 * `fromBody` / `fromArray` default to body; `fromQuery` defaults to query.
 * The whole input is validated and decoded before the DTO's constructor runs
 * with the field values as named arguments.
 *
 * Example:
 * ```php
 * $input = $inputs->fromBody(
 *     UpdateProductInput::class,
 *     $request,
 *     route:   ['id' => $productId],
 *     context: ['authenticatedUserId' => $auth->id()],
 * );
 * ```
 *
 * @phpstan-import-type ValidationErrors from ValidationException
 * @phpstan-import-type FieldSchema from SchemaBuilder
 */
final class InputFactory
{
    private readonly ReflectionCache $cache;
    private readonly FieldValueResolver $fieldValues;
    private readonly ProcessorRegistry $processors;

    /**
     * @param ContainerInterface|null $container Resolves normalizer, caster,
     *        post-processor and generator classes by class name — for those
     *        with constructor dependencies. An instance passed to `register()` takes precedence.
     */
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly bool $autoTrim = true,
        ?ContainerInterface $container = null,
    ) {
        $this->cache = ReflectionCache::shared();
        $this->processors = new ProcessorRegistry($container);
        $this->fieldValues = new FieldValueResolver($this->processors);
    }

    /**
     * Register a normalizer, caster, post-processor or generator instance for
     * dependency injection.
     * It takes precedence over the container's entry for the class.
     *
     * @param class-string $className
     */
    public function register(string $className, object $instance): self
    {
        $this->processors->register($className, $instance);
        return $this;
    }

    /**
     * Build an input DTO from the request body (POST/PUT/PATCH requests).
     *
     * @template T of object
     * @param class-string<T>      $className
     * @param array<string, mixed> $route   Consumed by #[FromRoute].
     * @param array<string, mixed> $context Consumed by #[FromContext].
     * @return T
     */
    public function fromBody(
        string $className,
        ServerRequestInterface $request,
        array $route = [],
        array $context = [],
    ): object {
        return $this->build($className, FieldSource::Body, InputBags::fromRequest($request, $route, $context));
    }

    /**
     * Build an input DTO from query parameters (GET requests).
     *
     * @template T of object
     * @param class-string<T>      $className
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     * @return T
     */
    public function fromQuery(
        string $className,
        ServerRequestInterface $request,
        array $route = [],
        array $context = [],
    ): object {
        return $this->build($className, FieldSource::Query, InputBags::fromRequest($request, $route, $context));
    }

    /**
     * Build an input DTO from a raw array. The array plays the role of the
     * body; query is empty.
     *
     * @template T of object
     * @param class-string<T>      $className
     * @param array<string, mixed> $data
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     * @return T
     */
    public function fromArray(
        string $className,
        array $data,
        array $route = [],
        array $context = [],
    ): object {
        return $this->build($className, FieldSource::Body, InputBags::fromArray($data, $route, $context));
    }

    /**
     * Build the JSON-serializable input contract of an input class.
     *
     * One entry per field the client sends, keyed by its input key (the key
     * validation errors use): type, nullability, requiredness — including
     * conditional `required_if` — the normalized rule list, the default, and
     * where relevant the date format, enum cases and nested schemas, so a
     * frontend can treat the backend as the single source of truth. Generated
     * and `#[FromContext]` fields are not part of it. Read-only: it does not
     * process any HTTP input.
     *
     * @param  class-string $className
     * @return array<string, FieldSchema>
     */
    public function schema(string $className): array
    {
        return (new SchemaBuilder($this->cache))->build($this->cache->get($className));
    }

    /**
     * Resolve every input error before any post-processor, generator or
     * constructor runs. Finalize bottom-up so nested post-processors receive
     * complete DTOs.
     *
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function build(string $className, FieldSource $defaultSource, InputBags $bags): object
    {
        $resolved = $this->resolve($className, $defaultSource, $bags);
        if ($resolved->errors !== []) {
            throw new ValidationException($resolved->errors);
        }

        /** @var T */
        return $this->finalize($resolved);
    }

    /**
     * Normalize all present fields, validate the raw bag, then decode values
     * and children. Pure custom casters participate in this same error pass.
     * A field the input lacks and the constructor has no default for fails
     * `required`, unless the validator already reported it.
     *
     * @param class-string $className
     */
    private function resolve(string $className, FieldSource $defaultSource, InputBags $bags): ResolvedInput
    {
        $metadata = $this->cache->get($className);
        $validationRules = [];
        $values = [];
        $errors = [];
        $missing = [];

        foreach ($metadata->properties as $name => $property) {
            if ($property->generator !== null) {
                continue;
            }

            $present = false;
            $value = $bags->lookup($property, $defaultSource, $present);
            if (!$present) {
                // Its rules go to the validator, but no value: the field stays
                // absent, and the validator decides whether it may be.
                if ($property->validationRules !== null) {
                    $validationRules[$name] = $property->validationRules;
                }
                if (!$property->hasDefault) {
                    $missing[$name] = $property;
                }
                continue;
            }

            if ($this->autoTrim && !$property->noTrim && is_string($value)) {
                $value = trim($value);
            }
            if ($property->normalizer !== null) {
                $value = $this->fieldValues->normalize($property, $value, $className, $bags, $error);
                if ($error !== null) {
                    $errors[$name] = [$error];
                    continue;
                }
            }
            if ($property->emptyToNull && is_string($value) && trim($value) === '') {
                $value = null;
            }

            $values[$name] = $value;
            if ($property->validationRules !== null) {
                $validationRules[$name] = $property->validationRules;
            }
        }

        if ($validationRules !== []) {
            // Cross-field rules also see present fields without rules of their own.
            try {
                ErrorMapper::append($errors, $this->validator->validate($values, $validationRules));
            } catch (ValidationException $e) {
                ErrorMapper::append($errors, $e->getErrors());
            }
        }

        foreach ($missing as $name => $property) {
            if (!isset($errors[$name])) {
                $errors[$name] = [$this->fieldValues->missing($property, $className)];
            }
        }

        $children = [];
        $childErrors = [];
        foreach ($values as $name => $value) {
            if (isset($errors[$name])) {
                continue;
            }
            $property = $metadata->properties[$name];
            $values[$name] = $value = $this->fieldValues->decode($property, $value, $className, $bags, $error);
            if ($error !== null) {
                $errors[$name] = [$error];
            } elseif ($property->buildsChildren && $value !== null) {
                $pathErrors = [];
                $children[$name] = $this->resolveChildren($property, $value, $bags->context, $pathErrors);
                if ($pathErrors !== []) {
                    $childErrors[$name] = $pathErrors;
                }
            }
        }

        return new ResolvedInput(
            metadata: $metadata,
            values: $values,
            children: $children,
            errors: $errors === [] && $childErrors === [] ? [] : ErrorMapper::order($metadata, $errors, $childErrors),
            route: $bags->route,
            context: $bags->context,
        );
    }

    private function finalize(ResolvedInput $resolved): object
    {
        $metadata = $resolved->metadata;
        $values = $resolved->values;
        foreach ($resolved->children as $name => $children) {
            $hydrated = [];
            foreach ($children as $child) {
                $hydrated[] = $this->finalize($child);
            }
            $values[$name] = $metadata->properties[$name]->nested !== null ? $hydrated[0] : $hydrated;
        }

        foreach ($metadata->postProcessed as $name => $handler) {
            if (isset($values[$name])) {
                $property = $metadata->properties[$name];
                $values[$name] = $this->fieldValues->postProcess($property, $handler, $values[$name], $resolved);
            }
        }

        foreach ($metadata->generated as $name => $generatorClass) {
            $property = $metadata->properties[$name];
            $values[$name] = $this->fieldValues->generate($property, $generatorClass, $metadata->className);
        }

        $instance = $this->fieldValues->instantiate($metadata, $values);
        // Absent keys never reach $values: what the constructor received is
        // what the request supplied.
        ProvidedFields::record($instance, $metadata, $values);

        return $instance;
    }

    /**
     * Resolve children in input order. Error paths retain the original keys;
     * finalization only needs a list of the children that could be resolved.
     *
     * @param array<string, mixed> $context
     * @param ValidationErrors     $errors Receives path-keyed child errors.
     * @return list<ResolvedInput>
     */
    private function resolveChildren(
        PropertyMetadata $property,
        mixed $value,
        array $context,
        array &$errors,
    ): array {
        $path = $property->sourceKey;

        if ($property->nested !== null) {
            $child = $this->resolveChild($property->nested, $value, $path, null, $context, $errors);
            return $child !== null ? [$child] : [];
        }

        if (!is_array($value)) {
            $errors[$path] = [['rule' => 'array']];
            return [];
        }

        /** @var class-string $itemsClass */
        $itemsClass = $property->items;
        $children = [];
        foreach ($value as $index => $itemData) {
            $child = $this->resolveChild($itemsClass, $itemData, $path, $index, $context, $errors);
            if ($child !== null) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Resolve one nested object — the value must be an array (a JSON object).
     * Only the outer context is propagated (see `InputBags::forItem()`), and
     * errors are recorded under the field's `$path`, followed by `$index` for
     * a list item (`address.city`, `lines.0.qty`). The item path is built
     * only for an error.
     *
     * @param class-string         $class
     * @param array<string, mixed> $context
     * @param ValidationErrors     $errors
     */
    private function resolveChild(
        string $class,
        mixed $data,
        string $path,
        int|string|null $index,
        array $context,
        array &$errors,
    ): ?ResolvedInput {
        if (!is_array($data)) {
            $errors[self::childPath($path, $index)][] = ['rule' => 'array'];
            return null;
        }

        // resolve() keeps errors a validator throws with the ones it returns.
        $child = $this->resolve($class, FieldSource::Body, InputBags::forItem($data, $context));
        if ($child->errors !== []) {
            ErrorMapper::append($errors, $child->errors, self::childPath($path, $index) . '.');
        }

        return $child;
    }

    /**
     * Where a child's errors go: the field's input key, followed by the
     * item's index in a list.
     */
    private static function childPath(string $path, int|string|null $index): string
    {
        return $index === null ? $path : "{$path}.{$index}";
    }
}
