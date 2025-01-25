<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Cache\Subscription;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Container\ContainerInterface;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\PropertyValues;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\ValuesInterface;
use Sofascore\PurgatoryBundle\Attribute\Target\TargetInterface;
use Sofascore\PurgatoryBundle\Cache\PropertyResolver\SubscriptionResolverInterface;
use Sofascore\PurgatoryBundle\Cache\RouteMetadata\RouteMetadata;
use Sofascore\PurgatoryBundle\Cache\RouteMetadata\RouteMetadataProviderInterface;
use Sofascore\PurgatoryBundle\Cache\TargetResolver\TargetResolverInterface;
use Sofascore\PurgatoryBundle\Exception\EntityMetadataNotFoundException;
use Sofascore\PurgatoryBundle\Exception\MissingRequiredRouteParametersException;
use Sofascore\PurgatoryBundle\Exception\TargetSubscriptionNotResolvableException;

/**
 * @internal Used during cache warmup
 */
final class PurgeSubscriptionProvider implements PurgeSubscriptionProviderInterface
{
    /**
     * @param iterable<SubscriptionResolverInterface>  $subscriptionResolvers
     * @param iterable<RouteMetadataProviderInterface> $routeMetadataProviders
     */
    public function __construct(
        private readonly iterable $subscriptionResolvers,
        private readonly iterable $routeMetadataProviders,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ContainerInterface $targetResolverLocator,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function provide(): iterable
    {
        foreach ($this->routeMetadataProviders as $routeMetadataProvider) {
            yield from $this->provideFromMetadata($routeMetadataProvider);
        }
    }

    /**
     * @return iterable<PurgeSubscription>
     */
    private function provideFromMetadata(RouteMetadataProviderInterface $routeMetadataProvider): iterable
    {
        foreach ($routeMetadataProvider->provide() as $routeMetadata) {
            $purgeOn = $routeMetadata->purgeOn;

            // if route parameters are not specified, they are same as path variables
            if (null === $purgeOn->routeParams) {
                /** @var list<string> $pathVariables */
                $pathVariables = $routeMetadata->route->compile()->getPathVariables();

                /** @var array<string, ValuesInterface> $routeParams */
                $routeParams = [];

                foreach ($pathVariables as $pathVariable) {
                    $routeParams[$pathVariable] = new PropertyValues($pathVariable);
                }
            } else {
                $this->validateRouteParams(array_keys($purgeOn->routeParams), $routeMetadata);
                $routeParams = $purgeOn->routeParams;
            }

            if (null === $purgeOn->target) {
                yield new PurgeSubscription(
                    class: $purgeOn->class,
                    property: null,
                    routeParams: $routeParams,
                    routeName: $routeMetadata->routeName,
                    route: $routeMetadata->route,
                    actions: $purgeOn->actions,
                    if: $purgeOn->if,
                );

                continue;
            }

            $class = $purgeOn->class;

            if (null === $entityMetadata = $this->managerRegistry->getManagerForClass($class)?->getClassMetadata($class)) {
                throw new EntityMetadataNotFoundException($class);
            }

            /** @var TargetResolverInterface<TargetInterface> $targetResolver */
            $targetResolver = $this->targetResolverLocator->get($purgeOn->target::class);

            foreach ($targetResolver->resolve($purgeOn->target, $routeMetadata) as $property) {
                $targetResolved = false;

                foreach ($this->subscriptionResolvers as $resolver) {
                    yield from $subscriptions = $resolver->resolveSubscription($routeMetadata, $entityMetadata, $routeParams, $property);

                    $targetResolved = $targetResolved || $subscriptions->getReturn();
                }

                if (!$targetResolved) {
                    throw new TargetSubscriptionNotResolvableException($routeMetadata->routeName, $class, $property);
                }
            }
        }
    }

    /**
     * Check if all required route params are present in PurgeOn.
     *
     * @param non-empty-list<string> $routeParams
     */
    private function validateRouteParams(array $routeParams, RouteMetadata $routeMetadata): void
    {
        /** @var list<string> $pathVariables */
        $pathVariables = $routeMetadata->route->compile()->getPathVariables();
        $route = $routeMetadata->route;

        $requiredRouteParams = array_filter(
            array: $pathVariables,
            callback: static fn (string $var): bool => !$route->hasDefault($var),
        );

        if ([] !== $missingRouteParams = array_diff($requiredRouteParams, $routeParams)) {
            throw new MissingRequiredRouteParametersException(
                routeName: $routeMetadata->routeName,
                missingRouteParams: array_values($missingRouteParams),
            );
        }
    }
}
