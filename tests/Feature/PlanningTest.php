<?php

namespace CustomerProjects13\Tests\Feature;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\Project;

/**
 * The planning screen: the active projects and epics ordered by deadline and grouped by quarter,
 * rendered either as a roadmap or on a timeline. What matters is that it only ever shows active,
 * non-deleted records, that a record is labelled against today rather than by colour alone, and
 * that both layouts come off the same rows.
 */
class PlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('planning'))->assertRedirect(route('login'));
    }

    public function test_the_screen_offers_its_heading_a_search_box_and_the_create_action(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('planning'))
            ->assertOk()
            ->assertSee('data-test="planning-heading"', false)
            ->assertSee(__('Planning'))
            ->assertSee(__('Roadmap'))
            ->assertSee(__('Timeline'))
            ->assertSee('data-test="planning-search"', false)
            ->assertSee('href="'.route('projects.index', ['create' => 1]).'"', false)
            ->assertSee(__('New project'));
    }

    /**
     * The summary tiles describe the whole active slice: projects, epics and overdue items.
     * They do not shrink when a search term narrows the list below them.
     */
    public function test_the_summary_counts_the_active_slice(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create([
            'start_date' => '2026-01-01',
            'end_date' => '2099-01-01',
        ]);

        Project::factory()->for($customer)->archived()->create();
        Project::factory()->for($customer)->trashed()->create();
        Epic::factory()->for($project)->create([
            'start_date' => '2026-01-01',
            'end_date' => '2099-01-01',
        ]);
        Epic::factory()->for($project)->archived()->create();
        Epic::factory()->for($project)->trashed()->create();

        $this->travelTo('2026-10-06');

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSeeInOrder(['data-test="planning-summary-projects"', '>1</span>'], false)
            ->assertSeeInOrder(['data-test="planning-summary-epics"', '>1</span>'], false)
            ->assertSeeInOrder(['data-test="planning-summary-overdue"', '>0</span>'], false);
    }

    /**
     * A record whose end date has passed is late, and the tile says so in words rather than only in
     * colour. Dates are pinned because the factories randomise them.
     */
    public function test_the_overdue_tile_adds_up_the_late_projects_and_epics(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);

        Epic::factory()->for($project)->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-01',
        ]);

        // Same dates but archived and trashed, so neither is late active work.
        Project::factory()->for($customer)->archived()->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);
        Epic::factory()->for($project)->trashed()->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-04-01',
        ]);

        $this->travelTo('2026-10-06');

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSeeInOrder(['data-test="planning-summary-overdue"', '>2</span>'], false);
    }

    public function test_archived_and_trashed_records_never_reach_the_board(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create(['name' => 'Shown project']);

        Project::factory()->for($this->customer())->archived()->create(['name' => 'Hidden archived project']);
        Project::factory()->for($this->customer())->trashed()->create(['name' => 'Hidden trashed project']);
        Epic::factory()->archived()->for($project)->create(['name' => 'Hidden archived epic']);
        Epic::factory()->trashed()->for($project)->create(['name' => 'Hidden trashed epic']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('Shown project')
            ->assertDontSee('Hidden archived project')
            ->assertDontSee('Hidden trashed project')
            ->assertDontSee('Hidden archived epic')
            ->assertDontSee('Hidden trashed epic');
    }

    /**
     * A project of an archived customer is still active work, and deactivation does not cascade,
     * so it stays on the board with its customer named on the row.
     */
    public function test_an_active_project_of_an_archived_customer_still_shows_up(): void
    {
        $customer = Customer::factory()->archived()->create(['name' => 'Sleeping customer']);
        Project::factory()->for($customer)->create(['name' => 'Still running']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('Still running')
            ->assertSee('Sleeping customer');
    }

    public function test_rows_are_grouped_by_the_quarter_they_finish_in(): void
    {
        $customer = Customer::factory()->create();

        Project::factory()->for($customer)->create([
            'name' => 'Finishes in Q2',
            'start_date' => '2026-02-01',
            'end_date' => '2026-05-31',
        ]);

        Project::factory()->for($customer)->create([
            'name' => 'Finishes in Q4',
            'start_date' => '2026-09-01',
            'end_date' => '2026-11-30',
        ]);

        Project::factory()->for($customer)->state(['end_date' => null])->create(['name' => 'Undated project']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="planning-group-2026-2"',
                'Finishes in Q2',
            ], false)
            ->assertSeeInOrder([
                'data-test="planning-group-2026-4"',
                'Finishes in Q4',
            ], false)
            ->assertSeeInOrder([
                'data-test="planning-group-no-date"',
                'Undated project',
            ], false);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statuses(): array
    {
        return [
            'overdue' => ['overdue'],
            'on track' => ['ontrack'],
            'no date' => ['unscheduled'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_a_row_is_labelled_with_its_date_status(string $status): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create([
            'start_date' => '2026-01-01',
            'end_date' => $status === 'unscheduled' ? null : ($status === 'overdue' ? '2020-01-01' : '2099-01-01'),
        ]);

        $this->travelTo('2026-10-06');

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('data-test="planning-project-'.$project->id.'"', false)
            ->assertSee('data-status="'.$status.'"', false)
            ->assertSee(__(match ($status) {
                'overdue' => 'Overdue',
                'ontrack' => 'On track',
                default => 'No date',
            }));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function layouts(): array
    {
        return [
            'roadmap' => ['roadmap'],
            'timeline' => ['timeline'],
        ];
    }

    #[DataProvider('layouts')]
    public function test_both_layouts_render_the_same_rows(string $layout): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create(['name' => 'Portal rewrite']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['view' => $layout]))
            ->assertOk()
            ->assertSee('Portal rewrite')
            ->assertSee('data-test="planning-'.$layout.'-link"', false)
            ->assertSee(__('Active projects'))
            ->assertSee(__('Active epics'));
    }

    /**
     * The month axis is the whole difference between the two layouts, so it is asserted through the
     * column it fills: the timeline has a fourth column with a "Timeline" heading, the roadmap stops
     * at three. Both are matched on raw markup because Flux renders the heading through its own
     * classes in between.
     */
    public function test_the_timeline_layout_adds_a_month_axis_the_roadmap_does_not(): void
    {
        $customer = Customer::factory()->create();
        Project::factory()->for($customer)->create([
            'name' => 'Dated project',
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-20',
        ]);

        $this->travelTo('2026-10-06');

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['view' => 'timeline']))
            ->assertOk()
            ->assertSeeInOrder(['<th scope="col"', __('Timeline')], false)
            // The axis is one labelled block per month of the window, each sized as a share of it.
            ->assertSeeInOrder(['style="width: ', '>', '2026'], false);

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['view' => 'roadmap']))
            ->assertOk()
            ->assertSee(__('Dates'))
            ->assertDontSee('style="width: ', false);
    }

    /**
     * The layout is a URL parameter rather than a hidden panel, so a bad or missing value falls back
     * to the roadmap instead of failing, and the current one is marked for assistive technology.
     */
    public function test_an_unknown_layout_falls_back_to_the_roadmap(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('planning', ['view' => 'nonsense']))
            ->assertOk()
            ->assertSeeInOrder(['data-test="planning-roadmap-link"', 'aria-current="page"'], false);

        $this->get(route('planning'))
            ->assertOk()
            ->assertSeeInOrder(['data-test="planning-roadmap-link"', 'aria-current="page"'], false);
    }

    public function test_searching_narrows_the_board_to_the_matching_records(): void
    {
        $customer = Customer::factory()->create();
        Project::factory()->for($customer)->create(['name' => 'Alpha portal']);
        Project::factory()->for($customer)->create(['name' => 'Beta portal']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['search' => 'Alpha']))
            ->assertOk()
            ->assertSee('Alpha portal')
            ->assertDontSee('Beta portal');
    }

    /**
     * The tiles describe the whole active slice, so a search narrows the rows below them without
     * making the totals lie.
     */
    public function test_searching_does_not_shrink_the_summary_counts(): void
    {
        $customer = Customer::factory()->create();
        Project::factory()->for($customer)->create(['name' => 'Alpha portal']);
        Project::factory()->for($customer)->create(['name' => 'Beta portal']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['search' => 'Alpha']))
            ->assertOk()
            ->assertSeeInOrder(['data-test="planning-summary-projects"', '>2</span>'], false);
    }

    public function test_searching_finds_a_record_by_the_name_of_the_record_that_owns_it(): void
    {
        $customer = Customer::factory()->create(['name' => 'Northwind Traders']);
        $project = Project::factory()->for($customer)->create(['name' => 'Warehouse move']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Warehouse move');
    }

    public function test_the_search_term_survives_switching_layout(): void
    {
        // The href is compared escaped because Blade escapes the ampersand between the parameters.
        $timelineUrl = e(route('planning', ['search' => 'Portal', 'view' => 'timeline']));

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['search' => 'Portal']))
            ->assertOk()
            ->assertSee('href="'.$timelineUrl.'"', false);
    }

    public function test_a_search_without_matches_explains_that_and_offers_the_create_action(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['search' => 'Nothing here']))
            ->assertOk()
            ->assertSee(__('No record matches your search.'))
            ->assertSee('data-test="planning-empty-create"', false);
    }

    public function test_an_empty_portfolio_explains_itself(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSee(__('There is nothing to plan yet.'))
            ->assertSee('data-test="planning-empty-state"', false);
    }

    /**
     * The list is paginated so a large portfolio stays usable.
     */
    public function test_the_board_is_paginated(): void
    {
        $customer = Customer::factory()->create();
        Project::factory()->for($this->customer())->count(30)->for($customer)->create();

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('data-test="planning-pagination"', false);
    }
}
