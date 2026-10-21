<?php

declare(strict_types=1);

namespace Solo\Input\Exceptions;

use Exception;
use Solo\Input\Casters\BuiltInCaster;

/**
 * Thrown when an invalid input class configuration is detected
 *
 * This exception is thrown during metadata building to catch configuration
 * errors early, before any requests are processed. The runtime cases are
 * `handlerNotInstantiable()` and `containerEntryMismatch()`: how a handler
 * class is resolved is only known when it is first used.
 */
final class ConfigurationException extends Exception
{
    /**
     * An attribute in the library's namespace that it does not read where it
     * stands — a typo, a wrong namespace, a property attribute on a class.
     * With a known attribute of the same short name, the message names it.
     */
    public static function unknownAttribute(
        string $className,
        ?string $propertyName,
        string $attribute,
        ?string $suggestion,
    ): self {
        $where = $propertyName === null ? "Class {$className}" : "Property {$className}::\${$propertyName}";
        $kind = $propertyName === null ? 'class' : 'property';

        return new self("{$where} has #[{$attribute}], which is not a Solo Input {$kind} attribute."
            . ($suggestion === null ? '' : " Did you mean #[{$suggestion}]?"));
    }

    /**
     * A class built or nested as an input that does not carry `#[Input]`.
     */
    public static function notAnInput(string $className): self
    {
        return new self("Class {$className} is not an input class: mark it #[Input].");
    }

    /**
     * An input class that cannot be instantiated: abstract, an interface or
     * with a non-public constructor.
     */
    public static function notInstantiable(string $className): self
    {
        return new self(
            "Input class {$className} cannot be instantiated: it is abstract or its constructor is not public."
        );
    }

    /**
     * A constructor parameter without a public property of its name — or a
     * variadic one: every parameter is a field, and a property holds it.
     *
     * Example: public function __construct(int $page) — neither promoted nor declared.
     */
    public static function parameterWithoutField(string $className, string $parameterName): self
    {
        return new self(sprintf(
            "Parameter \$%s of %s::__construct() has no public property of that name. " .
            "Every constructor parameter of an input class is a field: promote it with 'public' " .
            "or declare 'public \$%s'. Variadic parameters are not fields.",
            $parameterName,
            $className,
            $parameterName,
        ));
    }

    /**
     * A field attribute on a property no constructor parameter names — it
     * would never run.
     */
    public static function notAField(string $className, string $propertyName, string $attribute): self
    {
        return new self(sprintf(
            "Property %s::\$%s has #[%s] but is not a constructor parameter. " .
            "Only constructor parameters are fields: add it to the constructor or remove #[%s].",
            $className,
            $propertyName,
            $attribute,
            $attribute,
        ));
    }

    public static function invalidHandler(
        string $className,
        string $propertyName,
        string $attribute,
        string $handler,
        string $interface,
    ): self {
        return new self("Property {$className}::\${$propertyName} has invalid #[{$attribute}({$handler})]: " .
            "class must implement {$interface}.");
    }

    /**
     * Nullable rule used with non-nullable property type
     *
     * Example: #[Validate('nullable')] public string $name;
     */
    public static function nullableRuleWithNonNullableType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has 'nullable' in validation rules but type '%s' doesn't allow null. " .
            "Change type to '?%s' or remove 'nullable' from rules.",
            $className,
            $propertyName,
            $propertyType,
            $propertyType
        ));
    }

    /**
     * Cast type incompatible with property type
     *
     * Example: #[Cast('string')] public int $id;
     */
    public static function castTypeMismatch(
        string $className,
        string $propertyName,
        string $castType,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has cast type '%s' which is incompatible with property type '%s'. " .
            "Ensure cast result matches property type.",
            $className,
            $propertyName,
            $castType,
            $propertyType
        ));
    }

    /**
     * Required field with default value
     *
     * Example: #[Validate('required')] public string $name = 'default';
     */
    public static function requiredWithDefault(
        string $className,
        string $propertyName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has 'required' in rules but also has a default value. " .
            "Required fields should not have defaults - remove 'required' or remove default value.",
            $className,
            $propertyName
        ));
    }

    /**
     * Invalid items class
     *
     * Example: #[Items('NonExistentClass')] public ?array $items = null;
     */
    public static function invalidItems(
        string $className,
        string $propertyName,
        string $itemsClass,
        string $reason
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has invalid items class '%s': %s.",
            $className,
            $propertyName,
            $itemsClass,
            $reason
        ));
    }

    /**
     * Items used on non-array property type
     *
     * Example: #[Items(OrderLineInput::class)] public string $lines,
     */
    public static function itemsRequiresArrayType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has 'items' but type is '%s'. " .
            "Properties with 'items' must have type 'array' or '?array'.",
            $className,
            $propertyName,
            $propertyType
        ));
    }

    /**
     * Both #[Cast] and #[Caster] declared on the same property.
     */
    public static function castAndCasterConflict(
        string $className,
        string $propertyName
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[Cast] and #[Caster]. " .
            "Use one or the other — they are mutually exclusive.",
            $className,
            $propertyName
        ));
    }

    /**
     * #[Cast] received a string that is neither a recognised built-in type nor a class.
     */
    public static function unknownCastType(
        string $className,
        string $propertyName,
        string $castType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has #[Cast('%s')] which is neither a built-in type " .
            "(%s) nor a known class.",
            $className,
            $propertyName,
            $castType,
            implode(', ', BuiltInCaster::CAST_TYPES)
        ));
    }

    /**
     * #[Cast] received a class name. Built-in casts only — for custom logic use #[Caster].
     */
    public static function castExpectsBuiltInType(
        string $className,
        string $propertyName,
        string $castType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has #[Cast('%s')] but that is a class name. " .
            "#[Cast] accepts built-in types only (%s).",
            $className,
            $propertyName,
            $castType,
            implode(', ', BuiltInCaster::CAST_TYPES)
        ));
    }

    /**
     * A class-typed property that nothing can produce from request input.
     *
     * Example: public Money $price; — no #[Caster] or #[FromContext].
     */
    public static function unproducibleType(
        string $className,
        string $propertyName,
        string $type
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has type '%s', which cannot be produced from request input. " .
            "Add #[Caster(...)], a #[Generator], read it #[FromContext(...)], " .
            "or type it as an #[Input] class, a BackedEnum or a DateTime class.",
            $className,
            $propertyName,
            $type
        ));
    }

    /**
     * #[DateFormat] on a property whose type cannot hold a date.
     */
    public static function dateFormatRequiresDateType(
        string $className,
        string $propertyName,
        string $propertyType
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has #[DateFormat] but type '%s'. " .
            "Use DateTimeImmutable, DateTime, DateTimeInterface (or an untyped / mixed property).",
            $className,
            $propertyName,
            $propertyType
        ));
    }

    /**
     * #[DateFormat] together with an attribute that produces the value another way.
     */
    public static function dateFormatConflict(
        string $className,
        string $propertyName,
        string $conflictingAttribute
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[DateFormat] and #[%s]. They are mutually exclusive.",
            $className,
            $propertyName,
            $conflictingAttribute
        ));
    }

    /**
     * More than one source attribute (#[FromRoute], #[FromContext], #[FromBody])
     * on the same property.
     *
     * @param list<string> $attributeNames All declared source-attribute names, in declaration order.
     */
    public static function multipleSources(
        string $className,
        string $propertyName,
        array $attributeNames
    ): self {
        $formatted = implode(', ', array_map(static fn(string $n) => "#[{$n}]", $attributeNames));
        return new self(sprintf(
            "Property %s::\$%s has multiple source attributes (%s). " .
            "A property has exactly one source.",
            $className,
            $propertyName,
            $formatted
        ));
    }

    /**
     * #[Items] / nested-DTO class is abstract and cannot be instantiated.
     */
    public static function abstractClass(
        string $className,
        string $propertyName,
        string $attributeName,
        string $targetClass
    ): self {
        return new self(sprintf(
            "Property %s::\$%s uses #[%s(%s)] but the class is abstract and cannot be instantiated. " .
            "Pass a concrete subclass instead.",
            $className,
            $propertyName,
            $attributeName,
            $targetClass
        ));
    }

    /**
     * #[Generator] used together with another attribute that has no effect for generated values.
     */
    public static function generatorConflict(
        string $className,
        string $propertyName,
        string $conflictingAttribute
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[Generator] and #[%s]. " .
            "Generated values bypass the input-read and validation pipeline, so #[%s] would never run.",
            $className,
            $propertyName,
            $conflictingAttribute,
            $conflictingAttribute
        ));
    }

    /**
     * #[Items] used together with #[Cast] or #[Caster] (casting is skipped for items properties).
     */
    public static function itemsCastConflict(
        string $className,
        string $propertyName,
        string $conflictingAttribute
    ): self {
        return new self(sprintf(
            "Property %s::\$%s has both #[Items] and #[%s]. " .
            "Items properties bypass automatic casting — each element is processed through its own DTO instead.",
            $className,
            $propertyName,
            $conflictingAttribute
        ));
    }

    /**
     * Normalizer, caster, post-processor or generator class cannot be
     * instantiated without arguments and no instance was registered for it.
     */
    public static function handlerNotInstantiable(string $handlerClass): self
    {
        return new self(sprintf(
            "Cannot instantiate %s automatically: it is an interface or abstract, has a non-public " .
            "constructor or requires constructor arguments. Provide it through the container passed to " .
            "InputFactory, or register an instance with InputFactory::register(%s::class, \$instance).",
            $handlerClass,
            $handlerClass
        ));
    }

    /**
     * The container's entry for a handler class is not an instance of it.
     */
    public static function containerEntryMismatch(string $handlerClass, string $entryType): self
    {
        return new self(sprintf(
            "The container entry for %s is a %s, not an instance of %s.",
            $handlerClass,
            $entryType,
            $handlerClass
        ));
    }
}
