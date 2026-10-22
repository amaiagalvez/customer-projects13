<?php

namespace EntityProjects13\Tests\Feature\Epics;

use App\Models\User;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Projects13\Models\Epic;
use Projects13\Models\Project;

/**
 * Each epic row names the entity of its project. The joined entity columns sit on the epic, so
 * the project's entity has to be eager loaded with the project or every distinct project would
 * load it on its own.
 */
class EpicListQueryCountTest extends TestCase
{
    public function test_the_epic_list_queries_do_not_grow_with_the_number_of_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $this->epicsInNewProjects(2);

        $few = $this->countQueries(fn () => $this->get(route('epics.index'))->assertOk());

        $this->epicsInNewProjects(6);

        $this->assertSame($few, $this->countQueries(fn () => $this->get(route('epics.index'))->assertOk()));
    }

    private function epicsInNewProjects(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Epic::factory()->for(Project::factory()->for(Entity::factory()))->create();
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
