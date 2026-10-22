<?php

namespace EntityProjects13\Tests\Unit;

use Basics13\Tests\BootstrapTestCase;

class BootstrapTest extends BootstrapTestCase
{
    protected function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
