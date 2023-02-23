<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @codeCoverageIgnore
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     *
     * @psalm-suppress PossiblyNullReference
     * @psalm-suppress PossiblyUndefinedMethod
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('purgatory');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('purger')
                    ->info('ID of the service implementing the \'Sofascore\PurgatoryBundle\Purger\PurgerInterface\' interface.')
                    ->cannotBeEmpty()
                    ->defaultValue('sofascore.purgatory.purger.default')
                ->end()
                ->booleanNode('entity_change_listener')
                    ->info('Determines whether entity changes should trigger the configured purge mechanism automatically.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('host')
                    ->info('Host on which urls should be purged.')
                    ->defaultValue('localhost:80')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
