<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle2\Purger;

final class InMemoryPurger implements PurgerInterface
{
    /** @var list<string> */
    private array $purgedUrls = [];

    public function purge(iterable $urls): void
    {
        foreach ($urls as $url) {
            $this->purgedUrls[] = $url;
        }
    }

    /**
     * @return list<string>
     */
    public function getPurgedUrls(): array
    {
        return $this->purgedUrls;
    }
}
