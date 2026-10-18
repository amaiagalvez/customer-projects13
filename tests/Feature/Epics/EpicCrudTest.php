<?php

namespace EntityProjects13\Tests\Feature\Epics;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use EntityProjects13\Tests\TestCase;
use entities13\Models\Entity;
use Illuminate\Pagination\LengthAwarePaginator;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Transformers\EpicListTransformer;

class EpicCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_epic_list_renders_the_entity_of_its_project(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->entity())->create();
        $epic = Epic::factory()->for($project)->create(['name' => 'Checkout flow']);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee($epic->name)
            ->assertSee($project->name)
            ->assertSee($project->entity->name);
    }

    public function test_epic_list_transformer_renders_the_parents_it_is_bound_to(): void
    {
        $entity = Entity::factory()->create(['name' => 'Bound entity']);
        $project = Project::factory()->for($entity)->create(['name' => 'Bound project']);
        $epic = Epic::factory()->for($project)->create();

        $list = app(EpicListTransformer::class)->active(
            new LengthAwarePaginator([$epic], 1, 15),
            '',
        );

        $this->assertSame('Bound project', data_get($list, 'rows.0.project'));
        $this->assertSame('Bound entity', data_get($list, 'rows.0.entity'));
        $this->assertSame(
            'Bound project (Bound entity)',
            data_get($list, 'rows.0.actions.0.payload.project_label'),
        );
    }

    public function test_epics_are_ordered_by_start_date_end_date_project_and_entity(): void
    {
        $this->actingAs(User::factory()->create());
        $alphaEntity = Entity::factory()->create(['name' => 'Alpha entity']);
        $zuluEntity = Entity::factory()->create(['name' => 'Zulu entity']);
        $alphaProject = Project::factory()->for($zuluEntity)->create(['name' => 'Alpha project']);
        $zuluProjectAlphaEntity = Project::factory()->for($alphaEntity)->create(['name' => 'Zulu project A']);
        $zuluProjectZuluEntity = Project::factory()->for($zuluEntity)->create(['name' => 'Zulu project Z']);

        $dates = ['start_date' => '2026-10-01', 'end_date' => '2026-10-03'];
        Epic::factory()->for($alphaProject)->create(['name' => 'Epic later start', 'start_date' => '2026-10-02', 'end_date' => '2026-10-03']);
        Epic::factory()->for($alphaProject)->create(['name' => 'Epic later end', 'start_date' => '2026-10-01', 'end_date' => '2026-10-04']);
        Epic::factory()->for($zuluProjectZuluEntity)->create(['name' => 'Epic zulu entity', ...$dates]);
        Epic::factory()->for($zuluProjectAlphaEntity)->create(['name' => 'Epic alpha entity', ...$dates]);
        Epic::factory()->for($alphaProject)->create(['name' => 'Epic alpha project', ...$dates]);

        for ($index = 6; $index <= ListQueryBase::PER_PAGE; $index++) {
            Epic::factory()->for($alphaProject)->create([
                'name' => "Epic filler {$index}",
                ...$dates,
            ]);
        }

        Epic::factory()->withoutDates()->for($alphaProject)->create(['name' => 'Epic without dates']);

        $this->get(route('epics.index'))
            ->assertSeeInOrder([
                'Epic alpha project',
                'Epic alpha entity',
                'Epic zulu entity',
                'Epic later end',
                'Epic later start',
            ]);

        $this->get(route('epics.index', ['page' => 2]))
            ->assertSee('Epic without dates');
    }

    public function test_epics_can_be_searched_by_project_and_entity_name(): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->create(['name' => 'Northwind Studio']);
        $otherEntity = Entity::factory()->create(['name' => 'Southwind Studio']);
        Epic::factory()->for(Project::factory()->for($entity)->state(['name' => 'Website']))->create(['name' => 'Matching epic']);
        Epic::factory()->for(Project::factory()->for($otherEntity)->state(['name' => 'Other project']))->create(['name' => 'Other epic']);

        $this->get(route('epics.index', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Matching epic')
            ->assertDontSee('Other epic');

        $this->get(route('epics.index', ['search' => 'Website']))
            ->assertOk()
            ->assertSee('Matching epic')
            ->assertDontSee('Other epic');
    }
}
