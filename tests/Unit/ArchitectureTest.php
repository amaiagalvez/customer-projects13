<?php

namespace EntityProjects13\Tests\Unit;

use EntityProjects13\Tests\TestCase;

/**
 * The bridge owns no resource: it only wires the domain packages together through integrations,
 * so the resource checks of the domain packages do not apply here.
 */
class ArchitectureTest extends TestCase
{
    public function test_source_holds_only_the_service_provider_and_integrations(): void
    {
        $entries = array_values(array_diff(scandir(dirname(__DIR__, 2).'/src') ?: [], ['.', '..']));
        sort($entries);

        $this->assertSame(
            ['Integrations', 'ServiceProvider.php'],
            $entries,
            'entity-projects13 is a bridge: models, controllers and other layers belong to a domain package.',
        );
    }
}
