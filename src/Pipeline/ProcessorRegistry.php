<?php

declare(strict_types=1);

namespace Solo\Input\Pipeline;

use Psr\Container\ContainerInterface;
use Solo\Input\Contracts\CasterInterface;
use Solo\Input\Contracts\GeneratorInterface;
use Solo\Input\Contracts\NormalizerInterface;
use Solo\Input\Contracts\PostProcessorInterface;
use Solo\Input\Exceptions\FieldValidationException;
use Solo\Input\Exceptions\ValidationException;
use Solo\Input\Exceptions\ConfigurationException;
use Solo\Input\ProcessContext;

/**
 * Resolves normalizer / caster / post-processor / generator classes.
 *
 * A class resolves to the instance registered for it, else the container's
 * entry for the class name, else a new instance built without arguments.
 * Registered and argument-less instances live as long as the owning
 * `InputFactory`; a container entry is fetched on every use, so the
 * container decides whether it is shared, built per call or request-scoped.
 *
 * @internal
 */
final class ProcessorRegistry
{
    /** @var array<class-string, object> */
    private array $instances = [];

    /** @var array<class-string, bool> Whether the container holds a class — asked once. */
    private array $inContainer = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * Register a pre-built instance — used when the normalizer, caster,
     * post-processor or generator has constructor dependencies that the
     * registry cannot satisfy itself.
     *
     * Fails fast if `$instance` is not actually an instance of `$className`
     * (typical copy-paste error: `register(MyCaster::class, $logger)`).
     *
     * @param class-string $className
     */
    public function register(string $className, object $instance): void
    {
        if (!$instance instanceof $className) {
            throw new \InvalidArgumentException(sprintf(
                "ProcessorRegistry::register(%s, ...) received an instance of %s, which is not a %s.",
                $className,
                $instance::class,
                $className,
            ));
        }
        $this->instances[$className] = $instance;
    }

    public function normalize(string $handler, mixed $value, ProcessContext $context): mixed
    {
        /** @var NormalizerInterface $normalizer */
        $normalizer = $this->getOrCreate($handler);
        try {
            return $normalizer->normalize($value, $context);
        } catch (ValidationException $e) {
            throw $this->aggregateRejection($handler, $e);
        }
    }

    public function cast(string $handler, mixed $value, ProcessContext $context): mixed
    {
        /** @var CasterInterface<mixed> $caster */
        $caster = $this->getOrCreate($handler);
        try {
            return $caster->cast($value, $context);
        } catch (ValidationException $e) {
            throw $this->aggregateRejection($handler, $e);
        }
    }

    public function postProcess(string $handler, mixed $value, ProcessContext $context): mixed
    {
        /** @var PostProcessorInterface<mixed> $processor */
        $processor = $this->getOrCreate($handler);
        try {
            return $processor->postProcess($value, $context);
        } catch (FieldValidationException | ValidationException $e) {
            throw $this->lateRejection($handler, $e);
        }
    }

    /**
     * @param class-string<GeneratorInterface> $generatorClass
     * @param array<string, mixed> $options
     */
    public function runGenerator(string $generatorClass, array $options): mixed
    {
        /** @var GeneratorInterface $generator */
        $generator = $this->getOrCreate($generatorClass);
        try {
            return $generator->generate($options);
        } catch (FieldValidationException | ValidationException $e) {
            throw $this->lateRejection($generatorClass, $e);
        }
    }

    private function aggregateRejection(string $handler, ValidationException $previous): \LogicException
    {
        return new \LogicException("{$handler} must reject its field with FieldValidationException.", 0, $previous);
    }

    private function lateRejection(string $handler, \Throwable $previous): \LogicException
    {
        return new \LogicException(
            "{$handler} cannot reject input during finalization. Move validation to a normalizer, validator or caster.",
            0,
            $previous,
        );
    }

    private function getOrCreate(string $className): object
    {
        /** @var class-string $className */
        if (isset($this->instances[$className])) {
            return $this->instances[$className];
        }

        if ($this->container !== null && ($this->inContainer[$className] ??= $this->container->has($className))) {
            $entry = $this->container->get($className);
            if (!$entry instanceof $className) {
                throw ConfigurationException::containerEntryMismatch($className, get_debug_type($entry));
            }

            return $entry;
        }

        return $this->instances[$className] = $this->instantiate($className);
    }

    /**
     * Whether a class can be built without arguments is only decidable here:
     * an interface, an abstract class or one with constructor dependencies is
     * valid configuration as long as an instance was registered for it or the
     * container provides one.
     *
     * @param class-string $className
     */
    private function instantiate(string $className): object
    {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (
            !$reflection->isInstantiable()
            || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)
        ) {
            throw ConfigurationException::handlerNotInstantiable($className);
        }

        return $reflection->newInstance();
    }
}
