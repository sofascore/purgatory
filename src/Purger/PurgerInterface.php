<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle2\Purger;

interface PurgerInterface
{
    /**
     * @param iterable<int, string> $urls
     */
    public function purge(iterable $urls): void;
}
