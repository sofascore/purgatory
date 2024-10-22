<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Sofascore\PurgatoryBundle\Listener\Enum\Action;
use Sofascore\PurgatoryBundle\Purger\PurgeRequest;
use Sofascore\PurgatoryBundle\Purger\PurgerInterface;
use Sofascore\PurgatoryBundle\RouteProvider\PurgeRoute;
use Sofascore\PurgatoryBundle\RouteProvider\RouteProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EntityChangeListener
{
    /** @var array<string, PurgeRequest> */
    private array $queuedPurgeRequests = [];

    /**
     * @param iterable<RouteProviderInterface<object>> $routeProviders
     */
    public function __construct(
        private readonly iterable $routeProviders,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PurgerInterface $purger,
    ) {
    }

    public function preRemove(PreRemoveEventArgs $eventArgs): void
    {
        $this->handleChanges($eventArgs, Action::Delete);
    }

    public function postPersist(PostPersistEventArgs $eventArgs): void
    {
        $this->handleChanges($eventArgs, Action::Create);
    }

    public function postUpdate(PostUpdateEventArgs $eventArgs): void
    {
        $this->handleChanges($eventArgs, Action::Update);
    }

    public function postFlush(PostFlushEventArgs $eventArgs): void
    {
        if (!$eventArgs->getObjectManager()->getConnection()->isTransactionActive()) {
            $this->process();
        }
    }

    public function process(): void
    {
        if (!$this->queuedPurgeRequests) {
            return;
        }

        $purgeRequests = array_values($this->queuedPurgeRequests);
        $this->reset();
        $this->purger->purge($purgeRequests);
    }

    public function reset(): void
    {
        $this->queuedPurgeRequests = [];
    }

    /**
     * @param LifecycleEventArgs<EntityManagerInterface> $eventArgs
     */
    private function handleChanges(LifecycleEventArgs $eventArgs, Action $action): void
    {
        $entity = $eventArgs->getObject();

        /** @var array<string, array{mixed, mixed}> $entityChangeSet */
        $entityChangeSet = $eventArgs->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);

        foreach ($this->routeProviders as $routeProvider) {
            if (!$routeProvider->supports($action, $entity)) {
                continue;
            }

            foreach ($routeProvider->provideRoutesFor($action, $entity, $entityChangeSet) as $route) {
                $url = $this->urlGenerator->generate(
                    name: $route->name,
                    parameters: $route->params,
                    referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
                );

                $this->queuedPurgeRequests[$this->generateHash(
                    url: $url,
                    route: $route,
                )] ??= new PurgeRequest(
                    url: $url,
                    route: $route,
                );
            }
        }
    }

    private function generateHash(string $url, PurgeRoute $route): string
    {
        $context = $route->context;
        ksort($context);

        return ContainerBuilder::hash([$url, $context]);
    }
}
