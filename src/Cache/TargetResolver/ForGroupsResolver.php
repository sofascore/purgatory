<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Cache\TargetResolver;

use Sofascore\PurgatoryBundle\Attribute\Target\ForGroups;
use Sofascore\PurgatoryBundle\Attribute\Target\TargetInterface;
use Sofascore\PurgatoryBundle\Cache\RouteMetadata\RouteMetadata;
use Sofascore\PurgatoryBundle\Exception\LogicException;
use Sofascore\PurgatoryBundle\Exception\RuntimeException;
use Symfony\Component\PropertyInfo\PropertyListExtractorInterface;

/**
 * @implements TargetResolverInterface<ForGroups>
 */
final class ForGroupsResolver implements TargetResolverInterface
{
    private readonly PropertyListExtractorInterface $propertyListExtractor;

    public function __construct(?PropertyListExtractorInterface $propertyListExtractor)
    {
        if (null === $propertyListExtractor) {
            throw new LogicException('You cannot use the "ForGroups" attribute because the Symfony Serializer component is not installed. Try running "composer require symfony/serializer".');
        }

        $this->propertyListExtractor = $propertyListExtractor;
    }

    /**
     * {@inheritDoc}
     */
    public static function for(): string
    {
        return ForGroups::class;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(TargetInterface $target, RouteMetadata $routeMetadata): array
    {
        /** @var list<string>|null $resolvedProperties */
        $resolvedProperties = $this->propertyListExtractor->getProperties(
            $routeMetadata->purgeOn->class,
            ['serializer_groups' => $target->groups],
        );

        if (null === $resolvedProperties) {
            throw new RuntimeException(\sprintf('Could not resolve properties for groups "%s" in class "%s".', implode('", "', $target->groups), $routeMetadata->purgeOn->class));
        }

        return $resolvedProperties;
    }
}
