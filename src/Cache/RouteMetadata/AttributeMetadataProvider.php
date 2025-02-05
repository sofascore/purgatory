<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Cache\RouteMetadata;

use Sofascore\PurgatoryBundle\Attribute\PurgeOn;
use Sofascore\PurgatoryBundle\Exception\InvalidPatternException;
use Symfony\Component\Routing\RouterInterface;

/**
 * @internal Used during cache warmup
 */
final class AttributeMetadataProvider implements RouteMetadataProviderInterface
{
    /**
     * @param array<string, class-string> $classMap
     * @param list<non-empty-string>      $routeIgnorePatterns
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly array $classMap,
        private readonly array $routeIgnorePatterns,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function provide(): iterable
    {
        $routeCollection = $this->router->getRouteCollection();

        foreach ($routeCollection as $routeName => $route) {
            if ($this->shouldSkipRoute($routeName)) {
                continue;
            }

            /** @var array{0: string, 1: string}|string|null $controller */
            $controller = $route->getDefault('_controller');
            if (null === $controller) {
                continue;
            }

            if (null === $reflectionMethod = $this->resolveControllerCallable($controller)) {
                continue;
            }

            foreach ([new \ReflectionClass($reflectionMethod->class), $reflectionMethod] as $reflection) {
                foreach ($reflection->getAttributes(PurgeOn::class) as $attribute) {
                    $purgeOn = $attribute->newInstance();

                    if (null === $purgeOn->route || \in_array($routeName, $purgeOn->route, true)) {
                        yield new RouteMetadata(
                            routeName: $routeName,
                            route: $route,
                            purgeOn: $purgeOn,
                            reflectionMethod: $reflectionMethod,
                        );
                    }
                }
            }
        }
    }

    /**
     * @param array{0: string, 1: string}|string $controller
     */
    private function resolveControllerCallable(array|string $controller): ?\ReflectionMethod
    {
        [$class, $method] = match (true) {
            \is_array($controller) => $controller,
            !str_contains($controller, '::') => [$controller, '__invoke'],
            default => explode('::', $controller, 2),
        };

        return isset($this->classMap[$class]) ? new \ReflectionMethod($this->classMap[$class], $method) : null;
    }

    private function shouldSkipRoute(string $routeName): bool
    {
        foreach ($this->routeIgnorePatterns as $pattern) {
            $result = preg_match($pattern, $routeName);

            if (1 === $result) {
                return true;
            }

            if (false === $result) {
                throw new InvalidPatternException($pattern, $routeName);
            }
        }

        return false;
    }
}
