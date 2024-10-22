<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Tests\Purger;

use PHPUnit\Framework\Attributes\CoversClass;
use Sofascore\PurgatoryBundle\Exception\InvalidArgumentException;
use Sofascore\PurgatoryBundle\Exception\PurgeRequestFailedException;
use Sofascore\PurgatoryBundle\Purger\PurgeRequest;
use Sofascore\PurgatoryBundle\Purger\VarnishPurger;
use Sofascore\PurgatoryBundle\RouteProvider\PurgeRoute;
use Sofascore\PurgatoryBundle\Tests\Functional\AbstractKernelTestCase;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(VarnishPurger::class)]
final class VarnishPurgerTest extends AbstractKernelTestCase
{
    public function testPurgeWithoutHosts(): void
    {
        $purgeRequests = [
            new PurgeRequest('http://example1.test/foo', new PurgeRoute('route_foo', [], [])),
            new PurgeRequest('http://example2.test/bar', new PurgeRoute('route_bar', [], [])),
        ];

        $httpClient = new MockHttpClient([
            static function (string $method, string $url) use ($purgeRequests): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame($purgeRequests[0]->url, $url);

                return new MockResponse();
            },
            static function (string $method, string $url) use ($purgeRequests): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame($purgeRequests[1]->url, $url);

                return new MockResponse();
            },
        ]);

        $purger = new VarnishPurger($httpClient);
        $purger->purge($purgeRequests);
    }

    public function testPurgeWithHosts(): void
    {
        $purgeRequests = [
            new PurgeRequest('http://example1.test/foo', new PurgeRoute('route_foo', [], [])),
            new PurgeRequest('http://example2.test/bar', new PurgeRoute('route_bar', [], [])),
        ];

        $httpClient = new MockHttpClient([
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame('http://host1/foo', $url);

                self::assertArrayHasKey('headers', $options);
                self::assertContains('Host: example1.test', $options['headers']);

                return new MockResponse();
            },
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame('http://host2/foo', $url);

                self::assertArrayHasKey('headers', $options);
                self::assertContains('Host: example1.test', $options['headers']);

                return new MockResponse();
            },
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame('http://host1/bar', $url);

                self::assertArrayHasKey('headers', $options);
                self::assertContains('Host: example2.test', $options['headers']);

                return new MockResponse();
            },
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame('http://host2/bar', $url);

                self::assertArrayHasKey('headers', $options);
                self::assertContains('Host: example2.test', $options['headers']);

                return new MockResponse();
            },
        ]);

        $purger = new VarnishPurger($httpClient, ['http://host1', 'http://host2']);
        $purger->purge($purgeRequests);
    }

    public function testExceptionIsThrownWhenUrlDoesNotHaveAHost(): void
    {
        $purger = new VarnishPurger(new MockHttpClient(), ['http://host1', 'http://host2']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL "/foo" provided. The URL must contain a host.');

        $purger->purge([
            new PurgeRequest('/foo', new PurgeRoute('route_foo', [], [])),
        ]);
    }

    public function testExceptionIsThrownWhenPurgeRequestsFail(): void
    {
        $purgeRequests = [
            new PurgeRequest('http://example1.test/foo', new PurgeRoute('route_foo', [], [])),
            new PurgeRequest('http://example2.test/bar', new PurgeRoute('route_bar', [], [])),
        ];

        $httpClient = new MockHttpClient([
            static function (string $method, string $url) use ($purgeRequests): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame($purgeRequests[0]->url, $url);

                return new MockResponse();
            },
            static function (string $method, string $url) use ($purgeRequests): MockResponse {
                self::assertSame('PURGE', $method);
                self::assertSame($purgeRequests[1]->url, $url);

                return new MockResponse(info: ['http_code' => 400]);
            },
        ]);

        $purger = new VarnishPurger($httpClient);

        $this->expectException(PurgeRequestFailedException::class);
        $this->expectExceptionMessage('An error occurred while trying to purge 1 URL.');

        $purger->purge($purgeRequests);
    }

    public function testPurgeWithHttpCache(): void
    {
        self::startServer(8088, ['test_case' => 'VarnishPurger', 'config' => 'app_config.yaml']);

        $httpClient = HttpClient::create();

        $decoratedHttpClient = new class($httpClient) implements HttpClientInterface {
            use DecoratorTrait;

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $options['headers'] ??= [];
                $options['headers']['X-HTTP-METHOD-OVERRIDE'] = 'PURGE';

                return $this->client->request('POST', $url, $options);
            }
        };

        $response1 = $httpClient->request('GET', 'http://localhost:8088/');
        self::assertSame(['miss/store'], $response1->getHeaders()['x-symfony-cache']);

        $response2 = $httpClient->request('GET', 'http://localhost:8088/');
        self::assertSame(['fresh'], $response2->getHeaders()['x-symfony-cache']);
        self::assertSame($response1->getContent(), $response2->getContent());

        (new VarnishPurger($decoratedHttpClient))->purge([
            new PurgeRequest('http://localhost:8088/', new PurgeRoute('route_name', [], [])),
        ]);

        $response3 = $httpClient->request('GET', 'http://localhost:8088/');
        self::assertSame(['miss/store'], $response3->getHeaders()['x-symfony-cache']);
        self::assertNotSame($response2->getContent(), $response3->getContent());

        (new VarnishPurger($decoratedHttpClient, ['http://127.0.0.1:8088']))->purge([
            new PurgeRequest('http://localhost:8088/', new PurgeRoute('route_name', [], [])),
        ]);

        $response4 = $httpClient->request('GET', 'http://localhost:8088/');
        self::assertSame(['miss/store'], $response4->getHeaders()['x-symfony-cache']);
        self::assertNotSame($response3->getContent(), $response4->getContent());
    }
}
