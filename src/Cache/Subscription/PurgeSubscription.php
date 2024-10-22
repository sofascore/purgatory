<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Cache\Subscription;

use Sofascore\PurgatoryBundle\Attribute\RouteParamValue\ValuesInterface;
use Sofascore\PurgatoryBundle\Listener\Enum\Action;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Routing\Route;

final class PurgeSubscription
{
    /**
     * @param class-string                   $class
     * @param array<string, ValuesInterface> $routeParams
     * @param ?non-empty-list<Action>        $actions
     * @param array<string, ?scalar>         $context
     */
    public function __construct(
        public readonly string $class,
        public readonly ?string $property,
        public readonly array $routeParams,
        public readonly string $routeName,
        public readonly Route $route,
        public readonly ?array $actions,
        public readonly ?Expression $if,
        public readonly array $context,
    ) {
    }
}
