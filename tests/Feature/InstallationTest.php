<?php

namespace CustomerProjects13\Tests\Feature;

use CustomerProjects13\Integrations\CustomerCountsIntegration;
use CustomerProjects13\Integrations\CustomerProjectsIntegration;
use CustomerProjects13\Tests\TestCase;
use Customers13\Contracts\CustomerIntegration;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Projects13\Contracts\ProjectIntegration;

/**
 * Installing this package replaces what both base packages bound by default. If the order in which
 * Composer's auto-discovery happens to register the providers ever changed, these two bindings are
 * what would silently fall back to the null implementations.
 */
class InstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_integration_replaces_the_project_null_implementation(): void
    {
        $this->assertInstanceOf(CustomerProjectsIntegration::class, app(ProjectIntegration::class));
    }

    public function test_the_integration_replaces_the_customer_null_implementation(): void
    {
        $this->assertInstanceOf(CustomerCountsIntegration::class, app(CustomerIntegration::class));
    }

    public function test_a_project_may_only_hang_from_a_customer_that_exists(): void
    {
        $this->assertDatabaseHas('CUM_customers', ['id' => Customer::factory()->create()->id]);
    }

    public function test_the_column_and_its_foreign_key_reach_the_projects_table(): void
    {
        $this->assertTrue(Schema::hasColumn('PRO_projects', 'customer_id'));

        $keys = array_values(array_filter(
            Schema::getForeignKeys('PRO_projects'),
            static fn (array $key): bool => $key['columns'] === ['customer_id'],
        ));

        $this->assertNotEmpty($keys, 'the foreign key has to be enforced, not just declared');
        $this->assertSame('CUM_customers', $keys[0]['foreign_table']);
        $this->assertSame(['id'], $keys[0]['foreign_columns']);
    }
}
