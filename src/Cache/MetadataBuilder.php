<?php

declare(strict_types=1);

namespace Solo\Input\Cache;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use Solo\Input\Attributes\Cast;
use Solo\Input\Attributes\Caster;
use Solo\Input\Attributes\DateFormat;
use Solo\Input\Attributes\EmptyToNull;
use Solo\Input\Attributes\Exclude;
use Solo\Input\Attributes\Generator;
use Solo\Input\Attributes\Group;
use Solo\Input\Attributes\Input;
use Solo\Input\Attributes\Items;
use Solo\Input\Attributes\NoTrim;
use Solo\Input\Attributes\PostProcess;
use Solo\Input\Attributes\Normalize;
use Solo\Input\Attributes\Source\FromBody;
use Solo\Input\Attributes\Source\FromContext;
use Solo\Input\Attributes\Source\FromRoute;
use Solo\Input\Attributes\Validate;
use Solo\Input\Casters\BuiltInCaster;
use Solo\Input\Contracts\CasterInterface;
use Solo\Input\Contracts\GeneratorInterface;
use Solo\Input\Contracts\NormalizerInterface;
use Solo\Input\Contracts\PostProcessorInterface;
use Solo\Input\Exceptions\ConfigurationException;
use Solo\Input\FieldSource;
use Solo\Input\Pipeline\TypeGuard;
use Solo\Input\RuleParser;

/**
 * Builds and validates reflection metadata for one input class.
 *
 * The class carries `#[Input]`, and its fields are its constructor
 * parameters, each held by the public property of the same name — promoted
 * by this constructor or a parent's, or declared: the attributes and type are
 * read from the property, the default from the parameter. Per-field behaviour is
 * composed from individual attributes. Source attributes (`#[FromRoute]`,
 * `#[FromContext]`, `#[FromBody]`) are optional — without one the field uses
 * the default source dictated by the `InputFactory::from*()` method invoked.
 *
 * @internal
 */
final class MetadataBuilder
{
    /**
     * `#[Cast]` type → acceptable PHP types for the target property. An int
     * result widens losslessly into a float property, a float result does not
     * narrow into an int one; `mixed` accepts every cast.
     */
    private const array CAST_COMPATIBILITY = [
        'int'     => ['int', 'float'],
        'float'   => ['float'],
        'bool'    => ['bool'],
        'string'  => ['string'],
        'array'   => ['array', 'iterable'],
        'json'    => ['string'],
        'decimal' => ['string'],
    ];

    /**
     * Built-in types a value read from request input can have: a property
     * typed with one — or with a union containing one — needs no producer.
     */
    private const array INPUT_TYPES = ['int', 'float', 'string', 'bool', 'true', 'false', 'array', 'iterable', 'mixed'];

    /**
     * Type → the validator rule a value that is not of it fails, widest first
     * (a string accepts any scalar, a float any number): a union reports the
     * first of its members.
     */
    private const array TYPE_RULES = [
        'string'   => 'string',
        'float'    => 'numeric',
        'int'      => 'integer',
        'bool'     => 'boolean',
        'true'     => 'boolean',
        'false'    => 'boolean',
        'array'    => 'array',
        'iterable' => 'array',
    ];

    /**
     * Attributes that act on a field's input: none may join #[Generator] — a
     * generated value reads no input.
     */
    private const array INPUT_ATTRIBUTES = [
        FromRoute::class,
        FromContext::class,
        FromBody::class,
        Validate::class,
        Cast::class,
        Caster::class,
        DateFormat::class,
        EmptyToNull::class,
        NoTrim::class,
        Normalize::class,
        PostProcess::class,
        Items::class,
    ];

    /**
     * Every attribute read from a field.
     */
    private const array PROPERTY_ATTRIBUTES = [
        ...self::INPUT_ATTRIBUTES,
        Generator::class,
        Group::class,
        Exclude::class,
    ];

    /**
     * Every attribute read from a class — and passed on to its subclasses.
     */
    private const array CLASS_ATTRIBUTES = [Input::class, EmptyToNull::class, NoTrim::class];

    /**
     * Where the library's attributes live: another name here is a mistake,
     * never another library's attribute.
     */
    private const string ATTRIBUTE_NAMESPACE = 'Solo\\Input\\Attributes\\';

    /**
     * Date type (lower-case) → the class a date property is built as.
     * `DateTimeInterface` gets the immutable implementation.
     */
    private const array DATETIME_TYPES = [
        'datetimeimmutable' => DateTimeImmutable::class,
        'datetime'          => DateTime::class,
        'datetimeinterface' => DateTimeImmutable::class,
    ];

    /**
     * Name of an attribute → its class: the name as declared and in lower
     * case, so another spelling finds the class as getAttributes(X::class)
     * does. Built on first use.
     *
     * @var array<string, class-string>|null
     */
    private static ?array $attributeClasses = null;

    /**
     * Class → whether it is an input class, answered once per class.
     *
     * @var array<string, bool>
     */
    private static array $inputClasses = [];

    /** Asked what `""` reads as for a type — see buildPropertyMetadata(). */
    private readonly BuiltInCaster $builtInCaster;

    /**
     * @param \Closure(class-string): void $loadChild Loads a child's metadata at
     *        the point its property is checked, preserving eager validation and
     *        the order of configuration errors. It guards recursive references;
     *        this builder does not cache metadata.
     */
    public function __construct(private readonly \Closure $loadChild)
    {
        $this->builtInCaster = new BuiltInCaster();
    }

    /**
     * @param class-string $className
     */
    public function build(string $className): InputMetadata
    {
        $reflection = new ReflectionClass($className);
        $classAttributes = $this->classAttributesOf($reflection);
        if (!isset($classAttributes[Input::class])) {
            throw ConfigurationException::notAnInput($className);
        }
        if (!$reflection->isInstantiable()) {
            throw ConfigurationException::notInstantiable($className);
        }
        $emptyToNull = isset($classAttributes[EmptyToNull::class]);
        $noTrim = isset($classAttributes[NoTrim::class]);
        $properties = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $property = $reflection->hasProperty($name) ? $reflection->getProperty($name) : null;
            if ($parameter->isVariadic() || $property === null || !$property->isPublic() || $property->isStatic()) {
                throw ConfigurationException::parameterWithoutField($className, $name);
            }

            $attributes = $this->attributesOf($property);
            $metadata = $this->buildPropertyMetadata($property, $parameter, $attributes, $emptyToNull, $noTrim);
            $properties[$metadata->name] = $metadata;
        }

        $this->assertNoFieldAttributesElsewhere($reflection, $properties);

        $generated = [];
        $postProcessed = [];
        $groups = [];
        foreach ($properties as $name => $property) {
            if ($property->generator !== null) {
                $generated[$name] = $property->generator;
            }
            if ($property->postProcessor !== null) {
                $postProcessed[$name] = $property->postProcessor;
            }
            if ($property->group !== null) {
                $groups[$property->group][] = $name;
            }
        }

        return new InputMetadata(
            $className,
            $properties,
            $reflection,
            $generated,
            $postProcessed,
            $groups,
            self::isPartial($reflection),
        );
    }

    /**
     * What the nearest `#[Input]` of the class or its parents says: a
     * subclass with a marker of its own decides for itself.
     *
     * @param ReflectionClass<object> $class
     */
    private static function isPartial(ReflectionClass $class): bool
    {
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $input = $current->getAttributes(Input::class)[0] ?? null;
            if ($input !== null) {
                return $input->newInstance()->partial;
            }
        }

        return false;
    }

    /**
     * The property's attributes by class, read in one call instead of a
     * getAttributes(X::class) call per class. Attributes of other libraries
     * keep their name and are never instantiated; an unknown name in the
     * library's namespace — a typo, a wrong namespace — fails the build
     * instead of leaving the property without it.
     *
     * @return array<string, non-empty-list<ReflectionAttribute<object>>>
     */
    private function attributesOf(ReflectionProperty $property): array
    {
        $attributes = [];
        foreach ($property->getAttributes() as $attribute) {
            $name = $attribute->getName();
            $class = self::knownAttribute($name, self::PROPERTY_ATTRIBUTES, $property->class, $property->getName());
            $attributes[$class ?? $name][] = $attribute;
        }

        return $attributes;
    }

    /**
     * A field attribute on a property no constructor parameter names would
     * never run: only constructor parameters are fields. Surface that as a
     * build-time failure.
     *
     * @param ReflectionClass<object>         $class
     * @param array<string, PropertyMetadata> $fields
     */
    private function assertNoFieldAttributesElsewhere(ReflectionClass $class, array $fields): void
    {
        foreach ($class->getProperties() as $property) {
            if (isset($fields[$property->getName()])) {
                continue;
            }
            $attribute = $this->firstPresent($this->attributesOf($property), self::PROPERTY_ATTRIBUTES);
            if ($attribute !== null) {
                throw ConfigurationException::notAField($class->getName(), $property->getName(), $attribute);
            }
        }
    }

    /**
     * Whether the class or one of its parents carries `#[Input]` — PHP does
     * not inherit attributes, but a subclass of an input class is one too.
     */
    public static function isInputClass(string $class): bool
    {
        if (isset(self::$inputClasses[$class])) {
            return self::$inputClasses[$class];
        }

        $isInput = false;
        if (class_exists($class)) {
            for ($current = new ReflectionClass($class); $current !== false; $current = $current->getParentClass()) {
                if ($current->getAttributes(Input::class) !== []) {
                    $isInput = true;
                    break;
                }
            }
        }

        return self::$inputClasses[$class] = $isInput;
    }

    /**
     * The short name of the first of the classes the property carries an
     * attribute of.
     *
     * @param array<string, non-empty-list<ReflectionAttribute<object>>> $attributes
     * @param list<class-string>                                        $classes
     */
    private function firstPresent(array $attributes, array $classes): ?string
    {
        foreach ($classes as $class) {
            if (isset($attributes[$class])) {
                return self::shortName($class);
            }
        }

        return null;
    }

    /**
     * The class-level attributes the class or one of its parents carries —
     * PHP does not inherit attributes, but an input base class should pass
     * its class-level ones on. Another name in the library's namespace fails
     * the build, like an unknown property attribute.
     *
     * @param ReflectionClass<object> $class
     * @return array<class-string, true>
     */
    private function classAttributesOf(ReflectionClass $class): array
    {
        $found = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getAttributes() as $attribute) {
                $name = $attribute->getName();
                $known = self::knownAttribute($name, self::CLASS_ATTRIBUTES, $current->getName(), null);
                if ($known !== null) {
                    $found[$known] = true;
                }
            }
        }

        return $found;
    }

    /**
     * The attribute class a declared name stands for, in any spelling — null
     * for another library's attribute. A name in the library's namespace
     * that is none of `$known` fails the build.
     *
     * @param list<class-string> $known
     * @return class-string|null
     */
    private static function knownAttribute(string $name, array $known, string $owner, ?string $property): ?string
    {
        if (self::$attributeClasses === null) {
            $all = array_values(array_unique([...self::PROPERTY_ATTRIBUTES, ...self::CLASS_ATTRIBUTES]));
            $classes = array_combine($all, $all);
            self::$attributeClasses = array_change_key_case($classes) + $classes;
        }

        $class = self::$attributeClasses[$name] ?? self::$attributeClasses[strtolower($name)] ?? null;
        if ($class !== null && in_array($class, $known, true)) {
            return $class;
        }
        if (strncasecmp($name, self::ATTRIBUTE_NAMESPACE, strlen(self::ATTRIBUTE_NAMESPACE)) === 0) {
            throw ConfigurationException::unknownAttribute(
                $owner,
                $property,
                $name,
                self::sameShortName($name, $known),
            );
        }

        return null;
    }

    /**
     * The known attribute an unknown name most likely means: the one with
     * its short name, as `Attributes\Source\FromContext` for a misplaced
     * `Attributes\FromContext`.
     *
     * @param list<class-string> $known
     */
    private static function sameShortName(string $name, array $known): ?string
    {
        $short = self::shortName($name);
        foreach ($known as $class) {
            if (strcasecmp(self::shortName($class), $short) === 0) {
                return $class;
            }
        }

        return null;
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr($class, '\\'), 1);
    }

    /**
     * @param ReflectionParameter $parameter The constructor parameter promoting the property.
     * @param array<string, non-empty-list<ReflectionAttribute<object>>> $attributes By class: attributesOf().
     * @param bool         $classEmptyToNull `#[EmptyToNull]` on the class or a parent.
     * @param bool         $classNoTrim      `#[NoTrim]` on the class or a parent.
     */
    private function buildPropertyMetadata(
        ReflectionProperty $property,
        ReflectionParameter $parameter,
        array $attributes,
        bool $classEmptyToNull,
        bool $classNoTrim,
    ): PropertyMetadata {
        $name = $property->getName();
        /** @var class-string $className The declaring class. */
        $className = $property->class;

        $validate = isset($attributes[Validate::class]) ? $this->first($attributes, Validate::class) : null;
        $cast = isset($attributes[Cast::class]) ? $this->first($attributes, Cast::class) : null;
        $caster = isset($attributes[Caster::class]) ? $this->first($attributes, Caster::class) : null;
        $dateFormat = isset($attributes[DateFormat::class]) ? $this->first($attributes, DateFormat::class) : null;
        $normalize = isset($attributes[Normalize::class]) ? $this->first($attributes, Normalize::class) : null;
        $postProcess = isset($attributes[PostProcess::class]) ? $this->first($attributes, PostProcess::class) : null;
        $generator = isset($attributes[Generator::class]) ? $this->first($attributes, Generator::class) : null;
        $items = isset($attributes[Items::class]) ? $this->first($attributes, Items::class) : null;
        $group = isset($attributes[Group::class]) ? $this->first($attributes, Group::class) : null;
        $excludeAttr = isset($attributes[Exclude::class]) ? $this->first($attributes, Exclude::class) : null;
        $emptyToNull = isset($attributes[EmptyToNull::class]) ? $this->first($attributes, EmptyToNull::class) : null;
        $noTrim = isset($attributes[NoTrim::class]) ? $this->first($attributes, NoTrim::class) : null;

        [$source, $sourceKey, $pathSegments] = $this->detectSource($attributes, $name, $className);

        $rules = $validate?->rules;
        $castType = $cast !== null ? strtolower($cast->type) : null;
        $customCasterClass = $caster?->class;

        if ($cast !== null && $caster !== null) {
            throw ConfigurationException::castAndCasterConflict($className, $name);
        }

        // Generator presence forbids every other pipeline attribute, so check
        // that conflict BEFORE running rule-based diagnostics that would
        // otherwise surface a less-relevant error first.
        if ($generator !== null) {
            $this->validateHandler($generator->class, GeneratorInterface::class, $className, $name, 'Generator');
            $this->checkGeneratorConflicts($attributes, $className, $name);
        }

        // A promoted property has no default of its own: the parameter has it.
        $hasDefault = $parameter->isDefaultValueAvailable();
        $defaultValue = $hasDefault ? $parameter->getDefaultValue() : null;

        $type = $property->getType();
        [$phpType, $isNullable, $classType] = $this->resolvePropertyType($type);
        $classType = match ($classType) {
            'self' => $className,
            'parent' => get_parent_class($className) ?: null,
            default => $classType,
        };
        // Lower-cased names of the type's named members; null when untyped.
        $members = $phpType === null ? null : explode('|', strtolower($phpType));
        $appSupplied = $source === FieldSource::Context || $generator !== null;

        $isRequired = RuleParser::has($rules, 'required');

        if (RuleParser::has($rules, 'nullable') && !$isNullable && $phpType !== null) {
            throw ConfigurationException::nullableRuleWithNonNullableType($className, $name, $phpType);
        }

        if ($isRequired && $hasDefault) {
            throw ConfigurationException::requiredWithDefault($className, $name);
        }

        if ($cast !== null && $castType !== null) {
            if (!BuiltInCaster::isBuiltIn($castType)) {
                if (class_exists($cast->type)) {
                    throw ConfigurationException::castExpectsBuiltInType($className, $name, $cast->type);
                }
                throw ConfigurationException::unknownCastType($className, $name, $cast->type);
            }
            $compatible = [...self::CAST_COMPATIBILITY[$castType], 'mixed'];
            if ($members !== null && array_intersect($members, $compatible) === []) {
                throw ConfigurationException::castTypeMismatch($className, $name, $cast->type, (string) $phpType);
            }
        }

        if ($dateFormat !== null) {
            $conflict = $this->firstPresent($attributes, [Cast::class, Caster::class, Items::class]);
            if ($conflict !== null) {
                throw ConfigurationException::dateFormatConflict($className, $name, $conflict);
            }
        }

        $dateClass = $this->resolveDateClass($dateFormat, $cast, $caster, $phpType, $classType, $className, $name);

        $this->validateHandler($customCasterClass, CasterInterface::class, $className, $name, 'Caster');
        $this->validateHandler($normalize?->class, NormalizerInterface::class, $className, $name, 'Normalize');
        $this->validateHandler($postProcess?->class, PostProcessorInterface::class, $className, $name, 'PostProcess');

        if ($items !== null) {
            $this->validateChildClass($items->class, $className, $name, 'Items');

            if ($phpType !== null && $phpType !== 'array') {
                throw ConfigurationException::itemsRequiresArrayType($className, $name, $phpType);
            }

            $conflict = $this->firstPresent($attributes, [Cast::class, Caster::class]);
            if ($conflict !== null) {
                throw ConfigurationException::itemsCastConflict($className, $name, $conflict);
            }
        }

        [$effectiveCastType, $enumClass] = $this->resolveCast($castType, $customCasterClass, $phpType, $classType);

        // A field typed with an input class is a nested DTO — unless a caster
        // produces the object itself, or the application supplies it (a
        // generator, context).
        $nested = null;
        if (
            $classType !== null
            && $caster === null
            && !$appSupplied
            && self::isInputClass($classType)
        ) {
            $this->validateChildClass($classType, $className, $name, 'nested');
            /** @var class-string $classType An input class exists. */
            $nested = $classType;
        }

        // Fail fast on a type nothing can produce from request input — a
        // class, `object`, an intersection, a union of classes: otherwise the
        // first request carrying the field ends in a TypeError.
        $producedOtherwise = $nested !== null
            || $dateClass !== null
            || $enumClass !== null
            || $effectiveCastType !== null
            || $customCasterClass !== null
            || $appSupplied;
        $holdsInput = $members === null || array_intersect($members, self::INPUT_TYPES) !== [];

        if (!$producedOtherwise && !$holdsInput) {
            throw ConfigurationException::unproducibleType($className, $name, (string) $phpType);
        }

        // An input type no cast handles — a union, a standalone `true` /
        // `false` — is cast member by member; `mixed` takes anything as it is.
        $memberCast = $members !== null
            && $members !== ['mixed']
            && $holdsInput
            && $effectiveCastType === null
            && $enumClass === null
            && $dateClass === null
            && $customCasterClass === null;
        $unionFailure = $memberCast ? $this->typeRule($members) : null;

        // A list the application supplies holds its DTOs already: they are
        // checked, not built.
        $listItems = $appSupplied ? null : $items?->class;
        // Only a value something else produces needs checking: built-in
        // decoders and child construction already yield the declared type.
        $resultGuard = $customCasterClass !== null || $postProcess !== null || $appSupplied || $memberCast
            ? TypeGuard::fromType($type, $className)
            : null;
        $memberGuard = $memberCast ? $resultGuard : null;

        // Empty input policy is independent of the post-processor.
        $blank = match (true) {
            $appSupplied => '',
            // A caster gets "" or [] where the type holds it; no guard: any type.
            $customCasterClass !== null => match (true) {
                $resultGuard?->accepts('') ?? true => '',
                $resultGuard?->accepts([]) ?? false => [],
                default => null,
            },
            $listItems !== null => [],
            $enumClass !== null => $this->builtInCaster->castEnum($enumClass, ''),
            $dateClass !== null => $this->builtInCaster->castDate($dateClass, $dateFormat?->format, ''),
            $effectiveCastType !== null => BuiltInCaster::BLANK[$effectiveCastType],
            $memberGuard !== null => $this->builtInCaster->castToMembers($memberGuard, ''),
            !$holdsInput => null,
            default => '',
        };

        return new PropertyMetadata(
            name: $name,
            source: $source,
            sourceKey: $sourceKey,
            appSupplied: $appSupplied,
            type: $phpType,
            isNullable: $isNullable,
            hasDefault: $hasDefault,
            defaultValue: $defaultValue,
            validationRules: $rules,
            effectiveCastType: $effectiveCastType,
            customCasterClass: $customCasterClass,
            normalizer: $normalize?->class,
            normalizeConfig: $normalize !== null ? $normalize->config : [],
            casterConfig: $caster !== null ? $caster->config : [],
            postProcessor: $postProcess?->class,
            postProcessConfig: $postProcess !== null ? $postProcess->config : [],
            group: $group?->name,
            isRequired: $isRequired,
            reflection: $property,
            generator: $generator?->class,
            generatorOptions: $generator !== null ? $generator->options : [],
            exclude: $excludeAttr !== null,
            items: $listItems,
            pathSegments: $pathSegments,
            enumClass: $enumClass,
            castFailure: $unionFailure
                ?? $this->castFailure($effectiveCastType, $dateClass, $dateFormat?->format, $enumClass),
            groupMapTo: $group?->mapTo,
            dateClass: $dateClass,
            dateFormat: $dateFormat?->format,
            nested: $nested,
            // What the application supplies is kept as it is: never
            // auto-trimmed, and left out by a class-level #[EmptyToNull].
            emptyToNull: $emptyToNull !== null || ($classEmptyToNull && !$appSupplied),
            noTrim: $noTrim !== null || $classNoTrim || $appSupplied,
            resultGuard: $resultGuard,
            memberGuard: $memberGuard,
            producedItems: $appSupplied || $postProcess !== null ? $items?->class : null,
            blank: $blank,
        );
    }

    /**
     * The rule a value fails that is none of the types — named after the
     * widest of them. Null when no member is a type input can have.
     *
     * @param list<string> $members Lower-cased type names.
     * @return array{rule: string}|null
     */
    private function typeRule(array $members): ?array
    {
        // The intersection keeps TYPE_RULES' order: widest first.
        $rule = current(array_intersect_key(self::TYPE_RULES, array_flip($members)));

        return $rule === false ? null : ['rule' => $rule];
    }

    /**
     * The class a date property is built as: from a DateTime-family type, or
     * DateTimeImmutable for an untyped / `mixed` property carrying
     * `#[DateFormat]`. Null when the property is not a date or another
     * attribute produces the value.
     *
     * @return class-string<DateTime|DateTimeImmutable>|null
     */
    private function resolveDateClass(
        ?DateFormat $dateFormat,
        ?Cast $cast,
        ?Caster $caster,
        ?string $phpType,
        ?string $classType,
        string $className,
        string $name,
    ): ?string {
        if ($cast !== null || $caster !== null) {
            return null;
        }

        $dateClass = $classType !== null ? (self::DATETIME_TYPES[strtolower($classType)] ?? null) : null;

        if ($dateFormat !== null && $dateClass === null) {
            if ($phpType !== null && $phpType !== 'mixed') {
                throw ConfigurationException::dateFormatRequiresDateType($className, $name, $phpType);
            }
            $dateClass = DateTimeImmutable::class;
        }

        return $dateClass;
    }

    /**
     * The validation error reported when a built-in cast cannot convert
     * non-empty input — named like the validator rule for that type. Null
     * without a built-in cast, and for an array or iterable one, which
     * accepts any input.
     *
     * @param class-string<BackedEnum>|null $enumClass
     * @return array{rule: string, params?: list<string>}|null
     */
    private function castFailure(
        ?string $effectiveCastType,
        ?string $dateClass,
        ?string $dateFormat,
        ?string $enumClass
    ): ?array {
        if ($enumClass !== null) {
            return ['rule' => 'in', 'params' => array_map('strval', array_column($enumClass::cases(), 'value'))];
        }

        if ($dateClass !== null) {
            return $dateFormat === null ? ['rule' => 'date'] : ['rule' => 'date_format', 'params' => [$dateFormat]];
        }

        return match ($effectiveCastType) {
            'int', 'float', 'bool', 'string' => ['rule' => self::TYPE_RULES[$effectiveCastType]],
            'json' => ['rule' => 'json'],
            'decimal' => ['rule' => 'numeric'],
            null, 'array', 'iterable' => null,
            // A cast with no error here would let the input it rejects through as null.
            default => throw new \LogicException("Cast type '{$effectiveCastType}' has no cast failure."),
        };
    }

    /**
     * Determine the property's source.
     *
     * Returns `[null, $propertyName, null]` when no source attribute is present —
     * the handler will substitute the method-level default at runtime.
     *
     * The third tuple element is non-null only for `#[FromBody]`: an array of
     * dot-path segments used for nested traversal.
     *
     * @param array<string, non-empty-list<ReflectionAttribute<object>>> $attributes
     * @return array{0: ?FieldSource, 1: string, 2: ?list<string>}
     */
    private function detectSource(array $attributes, string $name, string $className): array
    {
        $fromRoute = $attributes[FromRoute::class] ?? [];
        $fromContext = $attributes[FromContext::class] ?? [];
        $fromBody = $attributes[FromBody::class] ?? [];

        $declared = [];
        if (!empty($fromRoute)) {
            $declared[] = 'FromRoute';
        }
        if (!empty($fromContext)) {
            $declared[] = 'FromContext';
        }
        if (!empty($fromBody)) {
            $declared[] = 'FromBody';
        }

        if (count($declared) > 1) {
            throw ConfigurationException::multipleSources($className, $name, $declared);
        }

        if (!empty($fromRoute)) {
            /** @var FromRoute $attr */
            $attr = $fromRoute[0]->newInstance();
            return [FieldSource::Route, $attr->key ?? $name, null];
        }

        if (!empty($fromContext)) {
            /** @var FromContext $attr */
            $attr = $fromContext[0]->newInstance();
            return [FieldSource::Context, $attr->key, null];
        }

        if (!empty($fromBody)) {
            /** @var FromBody $attr */
            $attr = $fromBody[0]->newInstance();
            $segments = explode('.', $attr->path);
            return [FieldSource::Body, $attr->path, $segments];
        }

        return [null, $name, null];
    }

    /**
     * @param array<string, non-empty-list<ReflectionAttribute<object>>> $attributes
     */
    private function checkGeneratorConflicts(
        array $attributes,
        string $className,
        string $propertyName,
    ): void {
        $conflict = $this->firstPresent($attributes, self::INPUT_ATTRIBUTES);

        if ($conflict !== null) {
            throw ConfigurationException::generatorConflict($className, $propertyName, $conflict);
        }
    }

    /**
     * The first attribute of the class the property carries, instantiated.
     * Called only for a class the property carries — the caller checks, as a
     * call per absent attribute is what reading them would cost — so a line
     * checking one class and reading another fails on a property that
     * carries the checked one.
     *
     * @template TAttr of object
     * @param array<string, non-empty-list<ReflectionAttribute<object>>> $attributes
     * @param class-string<TAttr>                                        $attributeClass
     * @return TAttr
     */
    private function first(array $attributes, string $attributeClass): object
    {
        $attribute = $attributes[$attributeClass][0]->newInstance();
        assert($attribute instanceof $attributeClass);

        return $attribute;
    }

    /**
     * Returns [type string, nullable, class name]. The class name is set only
     * for a single named class type — the one shape that can be an enum or a
     * DateTime, so unions, intersections and `mixed` never probe the autoloader.
     *
     * @return array{0: ?string, 1: bool, 2: ?string}
     */
    private function resolvePropertyType(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionUnionType) {
            // Every member, `(A&B)` ones of a DNF type included.
            return [(string) $type, $type->allowsNull(), null];
        }
        if ($type instanceof \ReflectionIntersectionType) {
            $names = array_map(
                static fn(\ReflectionNamedType $t) => $t->getName(),
                array_filter($type->getTypes(), static fn($t) => $t instanceof \ReflectionNamedType)
            );
            // Intersection types cannot include `null` — they're never nullable.
            return [implode('&', $names), false, null];
        }
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName(), $type->allowsNull(), $type->isBuiltin() ? null : $type->getName()];
        }
        return [null, true, null];
    }

    /**
     * The one rule for #[Normalize], #[Caster], #[PostProcess] and
     * #[Generator] classes: the class implements the attribute's contract.
     * Whether it can be built — abstract, an interface, constructor
     * arguments — is decided on first use: register() or the container may
     * supply the instance.
     *
     * @param class-string $interface
     */
    private function validateHandler(
        ?string $handler,
        string $interface,
        string $className,
        string $propertyName,
        string $attribute,
    ): void {
        if ($handler !== null && !is_a($handler, $interface, true)) {
            throw ConfigurationException::invalidHandler($className, $propertyName, $attribute, $handler, $interface);
        }
    }

    /**
     * Resolve the built-in cast. Returns [effectiveCastType, enumClass].
     * Dates are resolved separately (`resolveDateClass`).
     *
     * - explicit `#[Caster(...)]`              → [null, null] — the caster decides
     * - explicit `#[Cast('int'|…|'decimal')]` → [castType, null]
     * - no attribute, built-in property type   → [propertyType, null]
     * - no attribute, `iterable`               → ['iterable', null]
     * - no attribute, BackedEnum property type → [null, enumClass]
     * - anything else                          → [null, null]
     *
     * @return array{0: ?string, 1: ?class-string<BackedEnum>}
     */
    private function resolveCast(
        ?string $castType,
        ?string $customCasterClass,
        ?string $propertyType,
        ?string $classType
    ): array {
        if ($customCasterClass !== null) {
            return [null, null];
        }

        if ($castType !== null) {
            // castType is validated upstream to be a built-in.
            return [$castType, null];
        }

        // A class type is never a cast, though it may be named one (`json`, `decimal`).
        if ($propertyType !== null && $classType === null && BuiltInCaster::isBuiltIn($propertyType)) {
            return [$propertyType, null];
        }

        if ($propertyType === 'iterable') {
            return ['iterable', null];
        }

        if ($classType !== null && is_subclass_of($classType, BackedEnum::class)) {
            return [null, $classType];
        }

        return [null, null];
    }

    /**
     * Validate the input class of an `#[Items]` list or a nested DTO, and
     * build its metadata now — otherwise its misconfiguration surfaces only
     * when a request first carries it. The loader handles caching and skips
     * classes already being built to allow recursive references.
     */
    private function validateChildClass(
        string $child,
        string $className,
        string $propertyName,
        string $kind,
    ): void {
        if (!class_exists($child)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $child, 'class does not exist');
        }

        if (!self::isInputClass($child)) {
            throw ConfigurationException::invalidItems($className, $propertyName, $child, 'must be marked #[Input]');
        }

        if (!(new ReflectionClass($child))->isInstantiable()) {
            throw ConfigurationException::abstractClass($className, $propertyName, $kind, $child);
        }

        ($this->loadChild)($child);
    }
}
