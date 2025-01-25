<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Tests\Cache\Subscription;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sofascore\PurgatoryBundle\Attribute\PurgeOn;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\PropertyValues;
use Sofascore\PurgatoryBundle\Attribute\Target\ForProperties;
use Sofascore\PurgatoryBundle\Cache\PropertyResolver\SubscriptionResolverInterface;
use Sofascore\PurgatoryBundle\Cache\RouteMetadata\RouteMetadata;
use Sofascore\PurgatoryBundle\Cache\RouteMetadata\RouteMetadataProviderInterface;
use Sofascore\PurgatoryBundle\Cache\Subscription\PurgeSubscription;
use Sofascore\PurgatoryBundle\Cache\Subscription\PurgeSubscriptionProvider;
use Sofascore\PurgatoryBundle\Cache\TargetResolver\TargetResolverInterface;
use Sofascore\PurgatoryBundle\Exception\EntityMetadataNotFoundException;
use Sofascore\PurgatoryBundle\Tests\Cache\Subscription\Fixtures\DummyController;
use Sofascore\PurgatoryBundle\Tests\Cache\Subscription\Fixtures\DummyTarget;
use Symfony\Component\Routing\Route;

#[CoversClass(PurgeSubscriptionProvider::class)]
final class PurgeSubscriptionProviderTest extends TestCase
{
    #[DataProvider('provideRouteMetadataWithoutTarget')]
    public function testWithoutTarget(RouteMetadata $routeMetadata, array $expectedSubscriptions): void
    {
        $routeMetadataProvider = $this->createMock(RouteMetadataProviderInterface::class);
        $routeMetadataProvider->method('provide')
            ->willReturnCallback(function () use ($routeMetadata) {
                yield $routeMetadata;
            });

        $targetResolverLocator = $this->createMock(ContainerInterface::class);
        $targetResolverLocator->expects(self::never())->method('get');

        $purgeSubscriptionProvider = new PurgeSubscriptionProvider(
            subscriptionResolvers: [],
            routeMetadataProviders: [$routeMetadataProvider],
            managerRegistry: $this->createMock(ManagerRegistry::class),
            targetResolverLocator: $targetResolverLocator,
        );

        /** @var PurgeSubscription[] $propertySubscriptions */
        $propertySubscriptions = [...$purgeSubscriptionProvider->provide()];

        self::assertCount(\count($expectedSubscriptions), $propertySubscriptions);
        self::assertEquals($expectedSubscriptions, $propertySubscriptions);
    }

    public static function provideRouteMetadataWithoutTarget(): iterable
    {
        $route = new Route('/foo');
        yield 'PurgeOn for route without params' => [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                ),
                reflectionMethod: new \ReflectionMethod(DummyController::class, 'barAction'),
            ),
            'expectedSubscriptions' => [
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: null,
                    routeParams: [],
                    routeName: 'foo',
                    route: $route,
                    actions: null,
                    if: null,
                ),
            ],
        ];

        $route = new Route('/foo/{bar}');
        yield 'PurgeOn for route with params' => [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    routeParams: ['bar' => 'baz'],
                ),
                reflectionMethod: new \ReflectionMethod(DummyController::class, 'barAction'),
            ),
            'expectedSubscriptions' => [
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: null,
                    routeParams: ['bar' => new PropertyValues('baz')],
                    routeName: 'foo',
                    route: $route,
                    actions: null,
                    if: null,
                ),
            ],
        ];

        $route = new Route('/foo/{bar}/{baz}');
        yield 'PurgeOn with automatic route params resolving' => [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                ),
                reflectionMethod: new \ReflectionMethod(DummyController::class, 'barAction'),
            ),
            'expectedSubscriptions' => [
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: null,
                    routeParams: ['bar' => new PropertyValues('bar'), 'baz' => new PropertyValues('baz')],
                    routeName: 'foo',
                    route: $route,
                    actions: null,
                    if: null,
                ),
            ],
        ];
    }

    #[DataProvider('provideRouteMetadataWithTarget')]
    public function testWithTarget(RouteMetadata $routeMetadata, array $targetResolverReturn, array $expectedSubscriptions): void
    {
        $subscriptionResolver = $this->createMock(SubscriptionResolverInterface::class);
        $subscriptionResolver->method('resolveSubscription')
            ->willReturnCallback(function () use ($expectedSubscriptions) {
                static $i = 0;

                yield $expectedSubscriptions[$i++];

                return true;
            });

        $routeMetadataProvider = $this->createMock(RouteMetadataProviderInterface::class);
        $routeMetadataProvider->method('provide')
            ->willReturnCallback(function () use ($routeMetadata) {
                yield $routeMetadata;
            });

        $classMetadata = $this->createMock(ClassMetadata::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')
            ->with('FooEntity')
            ->willReturn($classMetadata);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')
            ->with('FooEntity')
            ->willReturn($entityManager);

        $dummyTargetResolver = $this->createMock(TargetResolverInterface::class);
        $dummyTargetResolver->method('resolve')
            ->with($routeMetadata->purgeOn->target, $routeMetadata)
            ->willReturn($targetResolverReturn);

        $targetResolverLocator = $this->createMock(ContainerInterface::class);
        $targetResolverLocator->method('get')
            ->with(DummyTarget::class)
            ->willReturn($dummyTargetResolver);

        $purgeSubscriptionProvider = new PurgeSubscriptionProvider(
            subscriptionResolvers: [$subscriptionResolver],
            routeMetadataProviders: [$routeMetadataProvider],
            managerRegistry: $managerRegistry,
            targetResolverLocator: $targetResolverLocator,
        );

        /** @var PurgeSubscription[] $propertySubscriptions */
        $propertySubscriptions = [...$purgeSubscriptionProvider->provide()];

        self::assertCount(\count($expectedSubscriptions), $propertySubscriptions);
        self::assertEquals($expectedSubscriptions, $propertySubscriptions);
    }

    public static function provideRouteMetadataWithTarget(): iterable
    {
        $route = new Route('/foo');
        yield 'PurgeOn for route without params' => [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    target: new DummyTarget(),
                ),
                reflectionMethod: new \ReflectionMethod(DummyController::class, 'barAction'),
            ),
            'targetResolverReturn' => ['foo', 'bar'],
            'expectedSubscriptions' => [
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: 'foo',
                    routeParams: [],
                    routeName: 'foo',
                    route: $route,
                    actions: null,
                    if: null,
                ),
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: 'bar',
                    routeParams: [],
                    routeName: 'bar',
                    route: $route,
                    actions: null,
                    if: null,
                ),
            ],
        ];

        $route = new Route('/foo/{bar}');
        yield 'PurgeOn for route with params' => [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    target: new DummyTarget(),
                    routeParams: ['bar' => 'baz'],
                ),
                reflectionMethod: new \ReflectionMethod(DummyController::class, 'barAction'),
            ),
            'targetResolverReturn' => ['bar', 'baz'],
            'expectedSubscriptions' => [
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: 'bar',
                    routeParams: ['bar' => new PropertyValues('baz')],
                    routeName: 'foo',
                    route: $route,
                    actions: null,
                    if: null,
                ),
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: 'baz',
                    routeParams: ['bar' => new PropertyValues('baz')],
                    routeName: 'bar',
                    route: $route,
                    actions: null,
                    if: null,
                ),
            ],
        ];

        $route = new Route('/foo/{bar}/{baz}');
        yield 'PurgeOn with automatic route params resolving' => [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    target: new DummyTarget(),
                ),
                reflectionMethod: new \ReflectionMethod(DummyController::class, 'barAction'),
            ),
            'targetResolverReturn' => ['qux', 'corge'],
            'expectedSubscriptions' => [
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: 'qux',
                    routeParams: ['bar' => new PropertyValues('bar'), 'baz' => new PropertyValues('baz')],
                    routeName: 'foo',
                    route: $route,
                    actions: null,
                    if: null,
                ),
                new PurgeSubscription(
                    class: 'FooEntity',
                    property: 'corge',
                    routeParams: ['bar' => new PropertyValues('bar'), 'baz' => new PropertyValues('baz')],
                    routeName: 'foo',
                    route: $route,
                    actions: null,
                    if: null,
                ),
            ],
        ];
    }

    public function testExceptionIsThrownWhenEntityMetadataIsNotFound(): void
    {
        $routeMetadataProvider = $this->createMock(RouteMetadataProviderInterface::class);
        $routeMetadataProvider->method('provide')
            ->willReturnCallback(function () {
                yield new RouteMetadata(
                    routeName: 'foo',
                    route: new Route('/foo'),
                    purgeOn: new PurgeOn(
                        class: 'FooEntity',
                        target: new ForProperties(['bar']),
                    ),
                    reflectionMethod: new \ReflectionMethod(DummyController::class, 'barAction'),
                );
            });

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManager')
            ->willReturn(null);

        $purgeSubscriptionProvider = new PurgeSubscriptionProvider(
            subscriptionResolvers: [],
            routeMetadataProviders: [$routeMetadataProvider],
            managerRegistry: $managerRegistry,
            targetResolverLocator: $this->createMock(ContainerInterface::class),
        );

        $this->expectException(EntityMetadataNotFoundException::class);
        $this->expectExceptionMessage('Unable to retrieve metadata for entity "FooEntity".');

        [...$purgeSubscriptionProvider->provide()];
    }

    #[DataProvider('provideRouteMetadataWithInvalidPurgeRouteParams')]
    public function testInvalidRouteParams(
        RouteMetadata $routeMetadata,
        array $expectedMissingRequiredParameters,
    ): void {
        $routeMetadataProvider = $this->createMock(RouteMetadataProviderInterface::class);
        $routeMetadataProvider->method('provide')
            ->willReturnCallback(function () use ($routeMetadata) {
                yield $routeMetadata;
            });

        $purgeSubscriptionProvider = new PurgeSubscriptionProvider(
            subscriptionResolvers: [],
            routeMetadataProviders: [$routeMetadataProvider],
            managerRegistry: $this->createMock(ManagerRegistry::class),
            targetResolverLocator: $this->createMock(ContainerInterface::class),
        );

        $this->expectException(\LogicException::class);

        $missingParams = implode(', ', $expectedMissingRequiredParameters);
        $this->expectExceptionMessage(
            "Can not purge route \"foo\" because some required route parameters are missing ($missingParams)",
        );

        [...$purgeSubscriptionProvider->provide()];
    }

    public static function provideRouteMetadataWithInvalidPurgeRouteParams(): iterable
    {
        $route = new Route(
            path: '/{foo}',
            defaults: [],
        );
        yield [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    routeParams: [
                        'bar' => 'bar',
                    ],
                ),
                reflectionMethod: null,
            ),
            'expectedMissingRequiredParameters' => ['foo'],
        ];

        $route = new Route(
            path: '/{foo}/{bar}',
            defaults: [
                'bar' => 'bar',
            ],
        );
        yield [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    routeParams: [
                        'bar' => 'bar',
                    ],
                ),
                reflectionMethod: null,
            ),
            'expectedMissingRequiredParameters' => ['foo'],
        ];

        $route = new Route(
            path: '/{foo}/{bar}/{baz}',
            defaults: [
                'bar' => 'bar',
                'baz' => 'baz',
            ],
        );
        yield [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    routeParams: [
                        'baz' => 'baz',
                    ],
                ),
                reflectionMethod: null,
            ),
            'expectedMissingRequiredParameters' => ['foo'],
        ];

        $route = new Route(
            path: '/{foo}/{bar}/{baz}',
            defaults: [
                'baz' => 'baz',
            ],
        );
        yield [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    routeParams: [
                        'foo' => 'foo',
                    ],
                ),
                reflectionMethod: null,
            ),
            'expectedMissingRequiredParameters' => ['bar'],
        ];

        $route = new Route(
            path: '/{foo}/{bar}/{baz}',
            defaults: [
                'baz' => 'baz',
            ],
        );
        yield [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    routeParams: [
                        'qux' => 'qux',
                    ],
                ),
                reflectionMethod: null,
            ),
            'expectedMissingRequiredParameters' => ['foo', 'bar'],
        ];

        $route = new Route(
            path: '/{foo}/{bar}/{baz}',
            defaults: [],
        );
        yield [
            'routeMetadata' => new RouteMetadata(
                routeName: 'foo',
                route: $route,
                purgeOn: new PurgeOn(
                    class: 'FooEntity',
                    routeParams: [
                        'bar' => 'bar',
                    ],
                ),
                reflectionMethod: null,
            ),
            'expectedMissingRequiredParameters' => ['foo', 'baz'],
        ];
    }
}
