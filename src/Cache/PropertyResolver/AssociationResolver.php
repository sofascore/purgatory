<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Cache\PropertyResolver;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata as ORMClassMetadata;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\InverseValuesAwareInterface;
use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\ValuesInterface;
use Sofascore\PurgatoryBundle\Cache\RouteMetadata\RouteMetadata;
use Sofascore\PurgatoryBundle\Cache\Subscription\PurgeSubscription;
use Sofascore\PurgatoryBundle\Exception\PropertyNotAccessibleException;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\PropertyInfo\PropertyReadInfo;
use Symfony\Component\PropertyInfo\PropertyReadInfoExtractorInterface;

final class AssociationResolver implements SubscriptionResolverInterface
{
    public function __construct(
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
        if (!$classMetadata instanceof ORMClassMetadata || !$classMetadata->hasAssociation($target)) {
            return false;
        }

        /** @var AssociationMapping|array{type: int, inversedBy?: ?string} $associationMapping */
        $associationMapping = $classMetadata->getAssociationMapping($target);

        $associationType = $associationMapping instanceof AssociationMapping
            ? $associationMapping->type()
            : $associationMapping['type'];

        if (ORMClassMetadata::ONE_TO_ONE === $associationType) {
            if ($classMetadata->isAssociationInverseSide($target)) {
                $associationTarget = $classMetadata->getAssociationMappedByTargetField($target);
            } else {
                /** @var ?string $associationTarget */
                $associationTarget = $associationMapping instanceof OneToOneOwningSideMapping
                    ? $associationMapping->inversedBy
                    : $associationMapping['inversedBy'] ?? null;
            }
        } elseif (ORMClassMetadata::ONE_TO_MANY === $associationType) {
            $associationTarget = $classMetadata->getAssociationMappedByTargetField($target);
        } else {
            return false;
        }

        if (null === $associationTarget) {
            return false;
        }

        $associationClass = $classMetadata->getAssociationTargetClass($target);

        /** @var array<string, ValuesInterface> $inverseRouteParams */
        $inverseRouteParams = [];
        foreach ($routeParams as $routeParam => $values) {
            $inverseRouteParams[$routeParam] = $this->getInverseValuesFor($values, $associationTarget);
        }

        if (null !== $if = $routeMetadata->purgeOn->if) {
            $expression = (string) $if;
            $if = new Expression(str_replace('obj', 'obj.'.$this->createGetter($associationClass, $associationTarget), $expression));
        }

        yield new PurgeSubscription(
            class: $associationClass,
            property: null,
            routeParams: $inverseRouteParams,
            routeName: $routeMetadata->routeName,
            route: $routeMetadata->route,
            actions: $routeMetadata->purgeOn->actions,
            if: $if,
        );

        return true;
    }

    private function getInverseValuesFor(ValuesInterface $values, string $associationTarget): ValuesInterface
    {
        return $values instanceof InverseValuesAwareInterface ? $values->buildInverseValuesFor($associationTarget) : $values;
    }

    private function createGetter(string $class, string $property): string
    {
        if (null === $readInfo = $this->extractor->getReadInfo($class, $property)) {
            throw new PropertyNotAccessibleException($class, $property);
        }

        /** @var PropertyReadInfo::TYPE_* $type */
        $type = $readInfo->getType();

        return match ($type) {
            PropertyReadInfo::TYPE_METHOD => $readInfo->getName().'()',
            PropertyReadInfo::TYPE_PROPERTY => $readInfo->getName(),
        };
    }
}
