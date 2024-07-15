<?php

declare(strict_types=1);

namespace Sofascore\PurgatoryBundle2\Tests\DependencyInjection\Fixtures;

use Sofascore\PurgatoryBundle2\Attribute\AsExpressionLanguageFunction;

#[AsExpressionLanguageFunction('function_class')]
class DummyExpressionLanguageFunction
{
    public function __invoke()
    {
    }

    #[AsExpressionLanguageFunction('function_foo')]
    public function foo()
    {
    }
}
