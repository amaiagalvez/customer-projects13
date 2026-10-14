<?php

namespace CustomerProjects13\Tests\Unit\Integrations;

use CustomerProjects13\Integrations\CustomerCountsIntegration;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class CustomerCountsIntegrationTest extends TestCase
{
    public function test_row_returns_empty_array_for_non_customer_model(): void
    {
        $integration = new CustomerCountsIntegration;

        // Test with a plain model instance (not a Customer)
        $model = new class extends Model
        {
            protected $table = 'test';
        };

        $result = $integration->row($model);

        $this->assertSame([], $result);
    }

    public function test_row_returns_counts_for_customer(): void
    {
        $integration = new CustomerCountsIntegration;

        $customer = Customer::factory()->make(['id' => 1, 'name' => 'Test Customer']);
        // Set the counts as if they came from the query
        $customer->projects_count = 5;
        $customer->epics_count = 3;
        $customer->comments_count = 2;
        $customer->projects_exists = true;

        $result = $integration->row($customer);

        $this->assertSame([
            'projectsCount' => 5,
            'projectsUrl' => route('projects.index', ['search' => 'Test Customer']),
            'epicsCount' => 3,
            'commentsCount' => 2,
            'projects_exists' => true,
        ], $result);
    }

    public function test_row_returns_null_projects_url_when_zero_projects(): void
    {
        $integration = new CustomerCountsIntegration;

        $customer = Customer::factory()->make(['id' => 1, 'name' => 'Test Customer']);
        $customer->projects_count = 0;
        $customer->epics_count = 0;
        $customer->comments_count = 0;
        $customer->projects_exists = false;

        $result = $integration->row($customer);

        $this->assertSame([
            'projectsCount' => 0,
            'projectsUrl' => null,
            'epicsCount' => 0,
            'commentsCount' => 0,
            'projects_exists' => false,
        ], $result);
    }
}
