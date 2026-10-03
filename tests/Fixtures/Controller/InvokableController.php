<?php

declare(strict_types=1);

namespace Starlite\Tests\Fixtures\Controller;

use Starlite\Controller;

final class InvokableController extends Controller
{
    public function __invoke(): string
    {
        return 'invoked';
    }
}
