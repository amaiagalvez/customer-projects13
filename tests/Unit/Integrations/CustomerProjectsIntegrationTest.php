<?php

namespace CustomerProjects13\Tests\Unit\Integrations;

use CustomerProjects13\Integrations\CustomerProjectsIntegration;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class CustomerProjectsIntegrationTest extends TestCase
{
    public function test_row_returns_empty_array_for_non_project_nor_epic_model(): void
    {
        $integration = new CustomerProjectsIntegration;

        // Test with a plain model instance (not a Project or Epic)
        $model = new class extends Model
        {
            protected $table = 'test';
        };

        $result = $integration->row($model);

        $this->assertSame([], $result);
    }

    public function test_row_returns_customer_data_for_project(): void
    {
        $integration = new CustomerProjectsIntegration;

        $customer = Customer::factory()->make(['id' => 1, 'name' => 'Test Customer']);
        $project = Project::factory()->for($customer)->make(['id' => 1, 'name' => 'Test Project']);
        // Set the relation to avoid loading
        $project->setRelation('customer', $customer);

        $result = $integration->row($project);

        $this->assertSame([
            'customer' => 'Test Customer',
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'customer_active' => true,
        ], $result);
    }

    public function test_row_marks_an_archived_customer_for_the_project_form(): void
    {
        $integration = new CustomerProjectsIntegration;

        $customer = Customer::factory()->archived()->make(['id' => 1, 'name' => 'Archived Customer']);
        $project = Project::factory()->for($customer)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('customer', $customer);

        $this->assertFalse($integration->row($project)['customer_active']);
    }

    public function test_row_returns_customer_data_for_epic(): void
    {
        $integration = new CustomerProjectsIntegration;

        $customer = Customer::factory()->make(['id' => 1, 'name' => 'Test Customer']);
        $project = Project::factory()->for($customer)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('customer', $customer);

        $epic = Epic::factory()->for($project)->make(['id' => 1, 'name' => 'Test Epic']);
        $epic->setRelation('project', $project);

        $result = $integration->row($epic);

        $this->assertSame([
            'customer' => 'Test Customer',
            'project_label' => 'Test Project (Test Customer)',
        ], $result);
    }

    public function test_row_returns_empty_customer_fields_when_project_has_no_customer(): void
    {
        $integration = new CustomerProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'customer_id' => null]);
        $project->setRelation('customer', null);

        $result = $integration->row($project);

        $this->assertSame([
            'customer' => null,
            'customer_id' => null,
            'customer_name' => null,
            'customer_active' => null,
        ], $result);
    }

    public function test_row_returns_project_label_when_epic_project_has_no_customer(): void
    {
        $integration = new CustomerProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'customer_id' => null]);
        $project->setRelation('customer', null);

        $epic = Epic::factory()->for($project)->make(['id' => 1, 'name' => 'Test Epic']);
        $epic->setRelation('project', $project);

        $result = $integration->row($epic);

        // When epic's project has no customer, the label still includes the project name
        $this->assertSame([
            'project_label' => 'Test Project ()',
        ], $result);
    }

    public function test_label_returns_project_name_with_customer(): void
    {
        $integration = new CustomerProjectsIntegration;

        $customer = Customer::factory()->make(['id' => 1, 'name' => 'Test Customer']);
        $project = Project::factory()->for($customer)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('customer', $customer);

        $result = $integration->label($project);

        $this->assertSame('Test Project (Test Customer)', $result);
    }

    public function test_label_returns_project_name_without_customer_when_missing(): void
    {
        $integration = new CustomerProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'customer_id' => null]);
        $project->setRelation('customer', null);

        $result = $integration->label($project);

        $this->assertSame('Test Project ()', $result);
    }

    public function test_trail_returns_customer_name_for_project(): void
    {
        $integration = new CustomerProjectsIntegration;

        $customer = Customer::factory()->make(['id' => 1, 'name' => 'Test Customer']);
        $project = Project::factory()->for($customer)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('customer', $customer);

        $result = $integration->trail($project);

        $this->assertSame(['Test Customer'], $result);
    }

    public function test_trail_returns_empty_array_when_no_customer(): void
    {
        $integration = new CustomerProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'customer_id' => null]);
        $project->setRelation('customer', null);

        $result = $integration->trail($project);

        $this->assertSame([], $result);
    }

    public function test_has_active_related_entities_checks_for_active_customers(): void
    {
        $integration = new CustomerProjectsIntegration;

        // Test when no active customers exist
        Customer::factory()->create(['active' => false]);
        $result = $integration->hasActiveRelatedEntities();
        $this->assertFalse($result);

        // Test when active customers exist
        Customer::factory()->create(['active' => true]);
        $result = $integration->hasActiveRelatedEntities();
        $this->assertTrue($result);
    }

    public function test_no_related_entities_banner_returns_expected_structure(): void
    {
        $integration = new CustomerProjectsIntegration;

        $result = $integration->noRelatedEntitiesBanner();

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('action_label', $result);
        $this->assertArrayHasKey('action_url', $result);
    }

    public function test_no_related_entities_banner_returns_null_when_active_customers_exist(): void
    {
        $integration = new CustomerProjectsIntegration;
        Customer::factory()->create(['active' => true]);

        $result = $integration->noRelatedEntitiesBanner();

        $this->assertNull($result);
    }

    public function test_no_related_entities_banner_returns_banner_when_no_active_customers(): void
    {
        $integration = new CustomerProjectsIntegration;
        Customer::factory()->create(['active' => false]);

        $result = $integration->noRelatedEntitiesBanner();

        $this->assertNotNull($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('action_label', $result);
        $this->assertArrayHasKey('action_url', $result);
    }
}
