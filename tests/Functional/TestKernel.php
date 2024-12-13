<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Sofascore\PurgatoryBundle\PurgatoryBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    private readonly string $config;

    public function __construct(
        private readonly string $varDir,
        private readonly string $testCase,
        string $config,
        string $environment,
        bool $debug,
    ) {
        if (!is_dir($this->getProjectDir())) {
            throw new \InvalidArgumentException(\sprintf('The test case "%s" does not exist.', $testCase));
        }

        if ('' !== $config && !is_file($config = $this->getProjectDir().'/config/'.$config)) {
            throw new \InvalidArgumentException(\sprintf('The config "%s" does not exist.', $config));
        }

        $this->config = $config;

        parent::__construct($environment, $debug);
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().substr(md5($this->config), -16);
    }

    public function getProjectDir(): string
    {
        return __DIR__.'/'.$this->testCase;
    }

    public function getCacheDir(): string
    {
        return $this->varDir.'/'.$this->testCase.'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir.'/'.$this->testCase.'/logs';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new PurgatoryBundle();
    }

    public function shutdown(): void
    {
        $handler = set_exception_handler('var_dump');
        restore_exception_handler();
        if (\is_array($handler) && $handler[0] instanceof ErrorHandler) {
            restore_exception_handler();
        }
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container) use ($loader) {
            if (is_dir($dir = $this->getProjectDir().'/Controller')) {
                /** @var PhpFileLoader $phpLoader */
                $phpLoader = $loader->getResolver()->resolve(__FILE__, 'php');
                $phpLoader->registerClasses(
                    (new Definition())->setAutowired(true)->setAutoconfigured(true),
                    'Sofascore\PurgatoryBundle\Tests\Functional\\'.$this->testCase.'\Controller\\',
                    $dir,
                );

                $container->loadFromExtension('framework', [
                    'test' => true,
                    'serializer' => ['enabled' => true],
                    'router' => [
                        'resource' => $dir,
                        'type' => 5 === Kernel::MAJOR_VERSION ? 'annotation' : 'attribute',
                    ],
                ]);
            }

            $container->setParameter('database_url', 'sqlite:///:memory:');
            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'url' => '%env(string:default:database_url:DATABASE_URL)%',
                ],
            ]);

            if (is_dir($dir = $this->getProjectDir().'/Entity')) {
                $container->loadFromExtension('doctrine', [
                    'orm' => [
                        'mappings' => [
                            'App' => [
                                'type' => 'attribute',
                                'is_bundle' => false,
                                'dir' => $dir,
                                'prefix' => 'Sofascore\PurgatoryBundle\Tests\Functional\\'.$this->testCase.'\Entity',
                                'alias' => 'App',
                            ],
                        ],
                    ],
                ]);
            }

            $container->loadFromExtension('purgatory', [
                'purger' => 'in-memory',
            ]);
        });

        if ('' !== $this->config) {
            $loader->load($this->config);
        }
    }
}
