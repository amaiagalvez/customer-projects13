<?php

namespace CustomerProjects13\Tests\Feature\Epics;

use CustomerProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Queries\Epics\EpicListQuery;

class EpicListQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_list_includes_epics_with_trashed_projects(): void
    {
        $project = Project::factory()->for($this->customer())->trashed()->create();
        $epic = Epic::factory()->for($project)->create();

        $epics = app(EpicListQuery::class)->active('');

        $this->assertSame([$epic->id], $epics->pluck('id')->all());

        $listedEpic = $epics->first();

        if ($listedEpic === null) {
            self::fail('The active list must include the epic with its trashed project.');
        }

        $this->assertTrue($listedEpic->project->is($project));
    }
}
