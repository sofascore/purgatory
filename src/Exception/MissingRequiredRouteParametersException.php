<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Exception;

final class MissingRequiredRouteParametersException extends \LogicException implements PurgatoryException
{
    private const MESSAGE = 'Cannot purge route "%s" because the following required route parameters are missing: "%s".';

    /**
     * @param non-empty-list<string> $missingRouteParams
     */
    public function __construct(
        public readonly string $routeName,
        public readonly array $missingRouteParams,
    ) {
        parent::__construct(
            \sprintf(self::MESSAGE, $this->routeName, implode('", "', $this->missingRouteParams)),
        );
    }
}
