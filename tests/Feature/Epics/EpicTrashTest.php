<?php

namespace EntityProjects13\Tests\Feature\Epics;

use App\Models\User;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class EpicTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_trash_preserves_index_columns_without_deletion_date(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-02 12:00:00');
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create();
        $epic = Epic::factory()->for($project)->create([
            'name' => 'Epic with dates',
            'start_date' => '2026-01-03',
            'end_date' => '2026-08-04',
        ]);

        $activeResponse = $this->get(route('epics.index'));
        $epic->delete();

        $response = $this->get(route('epics.trash.index'));

        $response->assertSeeInOrder([
            '<thead',
            __('Name'),
            __('Project'),
            __('Entity'),
            __('Start date'),
            __('End date'),
            __('Comments'),
            __('Actions'),
        ], false)->assertSeeInOrder([
            'Epic with dates',
            $project->name,
            $entity->name,
            '2026-01-03',
            '2026-08-04',
        ]);
        $response->assertSee('data-test="epic-comments-count-'.$epic->id.'"></span>', false);
    }

    public function test_trash_can_be_searched_by_project_and_entity_names(): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->create(['name' => 'Archived epic entity']);
        $project = Project::factory()->for($entity)->create(['name' => 'Archived epic project']);
        $matchingEpic = Epic::factory()->for($project)->trashed()->create(['name' => 'First archived epic']);
        $otherEntity = Entity::factory()->create(['name' => 'Other entity']);
        $otherProject = Project::factory()->for($otherEntity)->create(['name' => 'Other project']);
        $otherEpic = Epic::factory()->for($otherProject)->trashed()->create(['name' => 'Second archived epic']);

        foreach (['Archived epic entity', 'Archived epic project'] as $search) {
            $this->get(route('epics.trash.index', ['search' => $search]))
                ->assertOk()
                ->assertSee($matchingEpic->name)
                ->assertDontSee($otherEpic->name);
        }
    }
}
