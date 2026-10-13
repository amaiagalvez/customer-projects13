<?php

namespace CustomerProjects13\Tests\Feature;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Database\Seeders\DevelopCustomerSeeder;
use Customers13\Database\Seeders\EdgeCaseCustomerSeeder;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Database\Seeders\DevelopProjectSeeder;
use Projects13\Database\Seeders\EdgeCaseProjectSeeder;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;

class PackageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_seeders_create_each_package_hierarchy_without_implicit_customer_associations(): void
    {
        User::factory()->count(5)->create();

        app(DevelopCustomerSeeder::class)->run(1);
        app(DevelopProjectSeeder::class)->run(3);

        $this->assertDatabaseCount('CUM_customers', 1);
        $this->assertDatabaseCount('PRO_projects', 3);
        $this->assertDatabaseCount('PRO_epics', 12);
        $this->assertDatabaseCount('PRO_epic_comments', 36);
        $this->assertSame(0, Project::query()->distinct()->count('customer_id'));
        $this->assertSame(12, Epic::query()->whereHas('project', fn ($query) => $query
            ->where('name', 'like', 'Perf Project %'))->count());
        $this->assertSame(36, EpicComment::query()->count());
    }

    public function test_edge_case_seeders_create_records_for_each_resource_package(): void
    {
        $this->seed(EdgeCaseCustomerSeeder::class);
        $this->seed(EdgeCaseProjectSeeder::class);

        $this->assertDatabaseCount('CUM_customers', 11);
        $this->assertSame(5, Customer::query()->where('name', 'like', 'Customer without projects %')->count());
        $this->assertSame(3, Project::query()->where('name', 'like', 'Project without epics %')->count());
        $this->assertSame(3, Epic::query()->where('name', 'like', 'Epic without comments %')->count());
        $this->assertDatabaseCount('PRO_epic_comments', 0);
    }
}
