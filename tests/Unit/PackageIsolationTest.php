<?php

namespace EntityProjects13\Tests\Unit;

use Basics13\Tests\PackageIsolationTestCase;

/**
 * The bridge may know both domain packages, but never the host application.
 */
class PackageIsolationTest extends PackageIsolationTestCase
{
    protected function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function forbiddenInProductionCode(): array
    {
        return [
            'App\\',
        ];
    }

    protected function forbiddenInTests(): array
    {
        return [];
    }

    protected function isolationAdvice(): string
    {
        return 'entity-projects13 must not depend on the host application. Use the configured '
            .'models and contracts of the packages it bridges.';
    }
}
