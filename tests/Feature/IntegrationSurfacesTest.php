<?php

namespace EntityProjects13\Tests\Feature;

use Entities13\Models\Entity;
use Entities13\Queries\Entities\EntityListQuery;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Queries\Planning\PlanningQuery;

/**
 * What the entity's presence does to the other screens: the searches that reach it by name, the
 * labels that name a project wherever it is picked, and the counts it puts back on its own lists.
 */
class IntegrationSurfacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_project_list_searches_entities_by_name(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        Project::factory()->create(['name' => 'South project']);

        $this->get(route('projects.index', ['search' => 'North client']))
            ->assertOk()
            ->assertSee($project->name)
            ->assertDontSee('South project');
    }

    public function test_the_project_list_shows_the_entity_column(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        Project::factory()->for($entity)->create(['name' => 'Website renewal']);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('data-test="project-advanced-search"', false)
            ->assertSee('Entity')
            ->assertSee('North client');
    }

    public function test_the_epic_list_shows_the_entity_of_its_project(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        Epic::factory()->for($project)->create(['name' => 'First slice']);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee('North client');
    }

    public function test_the_project_selector_names_the_entity(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        Project::factory()->for($entity)->create(['name' => 'Website renewal']);

        $this->getJson(route('projects.options'))
            ->assertOk()
            ->assertExactJson(['results' => [
                ['id' => Project::query()->value('id'), 'text' => 'Website renewal (North client)'],
            ]]);
    }

    public function test_the_project_selector_searches_entities_by_name(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        Project::factory()->create(['name' => 'South project']);

        $this->getJson(route('projects.options', ['q' => 'North client']))
            ->assertOk()
            ->assertExactJson(['results' => [
                ['id' => $project->id, 'text' => 'Website renewal (North client)'],
            ]]);
    }

    public function test_the_planning_board_carries_the_entity_as_the_row_trail(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);

        $overview = app(PlanningQuery::class)->overview('');

        $this->assertSame(['North client'], $overview['paginator']->items()[0]['trail']);
    }

    public function test_the_entity_row_carries_the_counts_of_its_projects(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        Epic::factory()->for($project)->create();

        // Through the list query, because that is where the counts are added.
        $record = app(EntityListQuery::class)->active('')->items()[0];

        $this->assertSame(1, (int) $record->getAttribute('projects_count'));
        $this->assertSame(1, (int) $record->getAttribute('epics_count'));
        $this->assertSame(0, (int) $record->getAttribute('comments_count'));

        $this->get(route('entities.index'))
            ->assertOk()
            // The count itself is bound by Alpine, so the cell is what the markup has to carry.
            ->assertSee('entity-projects-count-'.$entity->id, false);
    }

    public function test_the_entity_row_counts_only_what_is_alive(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();
        $active = Project::factory()->for($entity)->create([]);
        Epic::factory()->for($active)->create();

        $archived = Project::factory()->archived()->create(['entity_id' => $entity->id]);
        Epic::factory()->for($archived)->create();
        Epic::factory()->for($archived)->trashed()->create();
        Project::factory()->trashed()->create(['entity_id' => $entity->id]);

        // Through the list query, because that is where the counts are added.
        $record = app(EntityListQuery::class)->active('')->items()[0];

        $this->assertSame(1, (int) $record->getAttribute('projects_count'));
        $this->assertSame(1, (int) $record->getAttribute('epics_count'));
    }

    public function test_the_entity_list_searches_a_project_through_the_joined_alias(): void
    {
        // The join and the search column share one alias, and nothing at runtime catches a drift
        // between them: this search only works when both still name the same table.
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        Project::factory()->create(['name' => 'South project']);
        Epic::factory()->for($project)->create();

        $this->get(route('entities.index', ['search' => 'Website renewal']))
            ->assertOk()
            ->assertSee('North client');
    }

    public function test_entity_search_uses_the_owner_key_and_keeps_each_entity_once(): void
    {
        $otherEntity = Entity::factory()->create(['name' => 'Other client']);
        $entity = Entity::factory()->create(['name' => 'North client']);
        Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        Project::factory()->for($entity)->create(['name' => 'Website maintenance']);

        $query = app(EntityListQuery::class);
        $matching = $query->active('Website');
        $all = $query->active('');

        $this->assertSame([$entity->id], $matching->pluck('id')->all());
        $this->assertSame(1, $matching->total());
        $this->assertSame([$entity->id, $otherEntity->id], $all->pluck('id')->all());
        $this->assertSame(2, $all->total());
    }

    public function test_the_project_form_offers_the_entity_field(): void
    {
        $this->signIn();
        Entity::factory()->create(['name' => 'North client']);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('data-project-entity-select', false)
            ->assertSee(route('entities.store', [], false), false);
    }
}
