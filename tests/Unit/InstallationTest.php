<?php

namespace EntityProjects13\Tests\Unit;

use Entities13\Contracts\EntityIntegration;
use EntityProjects13\Integrations\EntityCountsIntegration;
use EntityProjects13\Integrations\EntityProjectsIntegration;
use EntityProjects13\Tests\TestCase;
use Projects13\Contracts\ProjectDisplayIntegration;
use Projects13\Contracts\ProjectQueryIntegration;
use Projects13\Contracts\ProjectValidationIntegration;

/**
 * Installing this package replaces what both base packages bound by default. If the order in which
 * Composer's auto-discovery happens to register the providers ever changed, these two bindings are
 * what would silently fall back to the null implementations.
 */
class InstallationTest extends TestCase
{
    public function test_the_integration_replaces_the_project_null_implementation(): void
    {
        $this->assertInstanceOf(EntityProjectsIntegration::class, app(ProjectValidationIntegration::class));
        $this->assertInstanceOf(EntityProjectsIntegration::class, app(ProjectQueryIntegration::class));
        $this->assertInstanceOf(EntityProjectsIntegration::class, app(ProjectDisplayIntegration::class));
    }

    public function test_the_integration_replaces_the_entity_null_implementation(): void
    {
        $this->assertInstanceOf(EntityCountsIntegration::class, app(EntityIntegration::class));
    }

    /**
     * `config:cache` exports the whole configuration with `var_export` and loads it back, so a
     * closure anywhere in it makes `config:cache` and `optimize` fail once this package is installed.
     */
    public function test_the_configuration_stays_serializable_for_config_cache(): void
    {
        $exported = var_export(config()->all(), true);

        $this->assertSame(config()->all(), eval('return '.$exported.';'));
    }
}
