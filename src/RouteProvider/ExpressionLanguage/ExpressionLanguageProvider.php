<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle\RouteProvider\ExpressionLanguage;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * @internal
 */
final class ExpressionLanguageProvider implements ExpressionFunctionProviderInterface
{
    /**
     * @param ServiceProviderInterface<\Closure> $functionsProvider
     */
    public function __construct(
        private readonly ServiceProviderInterface $functionsProvider,
    ) {
    }

    public function getFunctions(): array
    {
        $functions = [];

        foreach ($this->functionsProvider->getProvidedServices() as $function => $_) {
            $functions[] = new ExpressionFunction(
                $function,
                static fn (string ...$args): string => \sprintf('($functionsProvider->get(%s))(%s)', var_export($function, true), implode(', ', $args)),
                fn (array $values, mixed ...$args): mixed => $this->functionsProvider->get($function)(...$args),
            );
        }

        return $functions;
    }
}
