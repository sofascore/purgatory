<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Cache\PropertyResolver;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Sofascore\PurgatoryBundle\Attribute\TargetedProperties;
use Sofascore\PurgatoryBundle\Cache\RouteMetadata\RouteMetadata;
use Sofascore\PurgatoryBundle\Exception\TargetSubscriptionNotResolvableException;
use Symfony\Component\PropertyInfo\PropertyReadInfo;
use Symfony\Component\PropertyInfo\PropertyReadInfoExtractorInterface;

/**
 * Handles methods with the {@see TargetedProperties} attribute.
 */
final class MethodResolver implements SubscriptionResolverInterface
{
    /**
     * @param iterable<SubscriptionResolverInterface> $subscriptionResolvers
     */
    public function __construct(
        private readonly iterable $subscriptionResolvers,
        private readonly PropertyReadInfoExtractorInterface $extractor,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function resolveSubscription(
        RouteMetadata $routeMetadata,
        ClassMetadata $classMetadata,
        array $routeParams,
        string $target,
    ): \Generator {
        $purgeOn = $routeMetadata->purgeOn;

        $method = $target;
        if (!method_exists($purgeOn->class, $method)) {
            $readInfo = $this->extractor
                ->getReadInfo(
                    class: $purgeOn->class,
                    property: $target,
                );

            if (null === $readInfo) {
                return false;
            }

            if (PropertyReadInfo::TYPE_METHOD !== $readInfo->getType()) {
                return false;
            }

            $method = $readInfo->getName();
        }

        $reflection = new \ReflectionMethod($purgeOn->class, $method);

        if (!$reflectionAttribute = $reflection->getAttributes(TargetedProperties::class)) {
            return false;
        }

        $targetedProperties = $reflectionAttribute[0]->newInstance();

        foreach ($targetedProperties->properties as $targetProperty) {
            $targetResolved = false;

            foreach ($this->subscriptionResolvers as $resolver) {
                yield from $subscriptions = $resolver->resolveSubscription($routeMetadata, $classMetadata, $routeParams, $targetProperty);

                if (true === $subscriptions->getReturn()) {
                    $targetResolved = true;
                }
            }

            if (!$targetResolved) {
                throw new TargetSubscriptionNotResolvableException($routeMetadata->routeName, $purgeOn->class, $targetProperty);
            }
        }

        return true;
    }
}
