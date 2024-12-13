<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Tests\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sofascore\PurgatoryBundle\Purger\AsyncPurger;
use Sofascore\PurgatoryBundle\Purger\InMemoryPurger;
use Sofascore\PurgatoryBundle\Purger\PurgeRequest;
use Sofascore\PurgatoryBundle\Purger\PurgerInterface;
use Sofascore\PurgatoryBundle\Purger\VoidPurger;
use Sofascore\PurgatoryBundle\RouteProvider\PurgeRoute;
use Sofascore\PurgatoryBundle\Test\InteractsWithPurgatory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\MessageBusInterface;

final class InteractsWithPurgatoryTest extends TestCase
{
    #[DataProvider('provideTraitTestCases')]
    public function testTrait(string $idInMemory, string $idAsync): void
    {
        $container = new Container();
        $container->set($idInMemory, new InMemoryPurger());
        $container->set($idAsync, new AsyncPurger($this->createMock(MessageBusInterface::class)));

        $test = new class($container) extends KernelTestCase {
            use InteractsWithPurgatory;

            private static Container $myContainer;

            public function __construct(Container $container)
            {
                self::$myContainer = $container;

                parent::__construct('name');
            }

            public function testUrlIsPurged(): void
            {
                self::getPurger()->purge([
                    new PurgeRequest('http://localhost/url', new PurgeRoute('route_url', [])),
                ]);
                self::assertUrlIsPurged('http://localhost/url');
                self::assertUrlIsPurged('/url');

                self::assertUrlIsNotPurged('https://localhost/url');
                self::assertUrlIsNotPurged('http://example.test/url');
                self::assertUrlIsNotPurged('/url?foo=bar');
                self::assertUrlIsNotPurged('/foo');

                self::assertSame(['http://localhost/url'], self::getPurgedUrls(true));
                self::assertSame(['/url'], self::getPurgedUrls(false));

                self::clearPurger();
                self::assertNoUrlsArePurged();
            }

            protected static function getContainer(): Container
            {
                return self::$myContainer;
            }
        };

        $test->testUrlIsPurged();
    }

    public static function provideTraitTestCases(): iterable
    {
        yield 'sync' => [PurgerInterface::class, 'sofascore.purgatory.purger.async'];
        yield 'async' => ['sofascore.purgatory.purger.sync', PurgerInterface::class];
    }

    public function testExceptionIsThrownWhenClassIsNotKernelTestCase(): void
    {
        $test = new class('name') extends TestCase {
            use InteractsWithPurgatory;

            public function testUrlIsPurged(): void
            {
                self::getPurger();
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('The "%s" trait can only be used with "%s".', InteractsWithPurgatory::class, KernelTestCase::class));

        $test->testUrlIsPurged();
    }

    public function testExceptionIsThrownWhenPurgerIsNotInMemory(): void
    {
        $test = new class('name') extends KernelTestCase {
            use InteractsWithPurgatory;

            public function testUrlIsPurged(): void
            {
                self::getPurger();
            }

            protected static function getContainer(): Container
            {
                $container = new Container();
                $container->set(PurgerInterface::class, new VoidPurger());

                return $container;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('The "%s" trait can only be used if "InMemoryPurger" is set as the purger.', InteractsWithPurgatory::class));

        $test->testUrlIsPurged();
    }
}
