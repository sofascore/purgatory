<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\RouteParamValueResolver;

use Psr\Container\ContainerInterface;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\DynamicValues;
use Sofascore\PurgatoryBundle\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * @implements ValuesResolverInterface<array{0: string, 1: ?string}>
 */
final class DynamicValuesResolver implements ValuesResolverInterface
{
    public function __construct(
        private readonly ContainerInterface $routeParamServiceLocator,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public static function for(): string
    {
        return DynamicValues::type();
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(array $unresolvedValues, object $entity): array
    {
        [$alias, $propertyPath] = $unresolvedValues;

        try {
            /** @var \Closure $routeParamService */
            $routeParamService = $this->routeParamServiceLocator->get($alias);
        } catch (ServiceNotFoundException $e) {
            throw new RuntimeException(\sprintf(
                'A route parameter resolver service with the alias "%s" was not found. Did you forget to use the #[AsPurgatoryResolver] attribute on your service?',
                $alias,
            ), previous: $e);
        }

        /** @var object|scalar|array<object|scalar> $arg */
        $arg = null === $propertyPath ? $entity : $this->propertyAccessor->getValue($entity, $propertyPath);

        /** @var scalar|list<?scalar>|null $values */
        $values = $routeParamService($arg);

        return \is_array($values) ? $values : [$values];
    }
}
