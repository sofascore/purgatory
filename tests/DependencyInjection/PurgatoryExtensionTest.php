<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle2\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sofascore\PurgatoryBundle2\Cache\Metadata\ControllerMetadataProvider;
use Sofascore\PurgatoryBundle2\Cache\PropertyResolver\EmbeddableResolver;
use Sofascore\PurgatoryBundle2\Cache\PropertyResolver\MethodResolver;
use Sofascore\PurgatoryBundle2\Cache\PropertyResolver\PropertyResolver;
use Sofascore\PurgatoryBundle2\DependencyInjection\PurgatoryExtension;
use Sofascore\PurgatoryBundle2\Purger\PurgerInterface;
use Sofascore\PurgatoryBundle2\PurgeRouteGenerator\RemovedEntityRouteGenerator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(PurgatoryExtension::class)]
final class PurgatoryExtensionTest extends TestCase
{
    public function testRouteIgnorePatternsIsSet(): void
    {
        $container = new ContainerBuilder();

        $container->register('sofascore.purgatory.controller_metadata_provider', ControllerMetadataProvider::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->setArguments([
                $this->createMock(RouterInterface::class),
                [],
                [],
            ]);

        $extension = new PurgatoryExtension();
        $extension->load([
            'sofascore_purgatory' => [
                'route_ignore_patterns' => ['/^_profiler/'],
            ],
        ], $container);

        self::assertTrue($container->has('sofascore.purgatory.controller_metadata_provider'));

        $definition = $container->getDefinition('sofascore.purgatory.controller_metadata_provider');
        self::assertSame(ControllerMetadataProvider::class, $definition->getClass());

        $ignoredPatterns = $definition->getArgument(2);
        self::assertCount(1, $ignoredPatterns);
        self::assertSame('/^_profiler/', $ignoredPatterns[0]);
    }

    public function testSubscriptionResolverIsTagged(): void
    {
        $container = new ContainerBuilder();

        $container->register(EmbeddableResolver::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container->register(MethodResolver::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container->register(PropertyResolver::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $extension = new PurgatoryExtension();
        $extension->load([], $container);

        $container->compile();

        self::assertTrue($container->getDefinition(EmbeddableResolver::class)->hasTag('purgatory.subscription_resolver'));
        self::assertTrue($container->getDefinition(MethodResolver::class)->hasTag('purgatory.subscription_resolver'));
        self::assertTrue($container->getDefinition(PropertyResolver::class)->hasTag('purgatory.subscription_resolver'));
    }

    public function testPurgeRouteGeneratorIsTagged(): void
    {
        $container = new ContainerBuilder();

        $container->register(RemovedEntityRouteGenerator::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $extension = new PurgatoryExtension();
        $extension->load([], $container);

        $container->compile();

        self::assertTrue($container->getDefinition(RemovedEntityRouteGenerator::class)->hasTag('purgatory.purge_route_generator'));
    }

    public function testPurgerConfig(): void
    {
        $container = new ContainerBuilder();

        $extension = new PurgatoryExtension();
        $extension->load([
            'sofascore_purgatory' => [
                'purger' => [
                    'name' => 'foo_purger',
                    'host' => 'localhost:80',
                ],
            ],
        ], $container);

        self::assertTrue($container->hasParameter('.sofascore.purgatory.purger.name'));
        self::assertTrue($container->hasParameter('.sofascore.purgatory.purger.host'));

        self::assertSame('foo_purger', $container->getParameter('.sofascore.purgatory.purger.name'));
        self::assertSame('localhost:80', $container->getParameter('.sofascore.purgatory.purger.host'));
    }

    public function testDefaultPurgerIsSetToNullPurger(): void
    {
        $container = new ContainerBuilder();

        $extension = new PurgatoryExtension();
        $extension->load([
            'sofascore_purgatory' => [
                'purger' => [
                    'name' => 'foo_purger',
                    'host' => 'localhost:80',
                ],
            ],
        ], $container);

        self::assertTrue($container->hasAlias('sofascore.purgatory.purger'));
        self::assertSame('sofascore.purgatory.purger.null', (string) $container->getAlias('sofascore.purgatory.purger'));

        self::assertTrue($container->hasAlias(PurgerInterface::class));
        self::assertSame('sofascore.purgatory.purger', (string) $container->getAlias(PurgerInterface::class));
    }
}
