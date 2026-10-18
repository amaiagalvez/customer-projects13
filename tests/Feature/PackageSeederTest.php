<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use entities13\Database\Seeders\DevelopEntitySeeder;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Database\Seeders\DevelopProjectSeeder;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;

class PackageSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_seeders_create_each_package_hierarchy_without_implicit_entity_associations(): void
    {
        User::factory()->count(5)->create();

        app(DevelopEntitySeeder::class)->run(1);
        app(DevelopProjectSeeder::class)->run(3);

        $this->assertDatabaseCount('ENT_entities', 1);
        $this->assertDatabaseCount('PRO_projects', 9);
        $this->assertDatabaseCount('PRO_epics', 15);
        $this->assertDatabaseCount('PRO_epic_comments', 36);
        $this->assertSame(0, Project::query()->distinct()->count('entity_id'));
        $this->assertSame(12, Epic::query()->whereHas('project', fn ($query) => $query
            ->where('name', 'like', 'Perf Project %'))->count());
        $this->assertSame(36, EpicComment::query()->count());
    }

    public function test_development_seeders_include_projects_without_epics_and_epics_without_comments(): void
    {
        User::factory()->create();

        app(DevelopEntitySeeder::class)->run(1);
        app(DevelopProjectSeeder::class)->run(1);

        $this->assertDatabaseCount('ENT_entities', 1);
        $this->assertSame(0, Project::query()->whereNotNull('entity_id')->count());
        $this->assertSame(3, Project::query()->where('name', 'like', 'Project without epics %')->doesntHave('epics')->count());
        $this->assertSame(3, Epic::query()->where('name', 'like', 'Epic without comments %')->doesntHave('comments')->count());
        $this->assertDatabaseCount('PRO_epic_comments', 12);
    }
}
