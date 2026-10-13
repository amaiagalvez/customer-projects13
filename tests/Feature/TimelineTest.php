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
 * The timeline screen: every active project drawn as a row with its active epics nested
 * underneath, each record a bar on one shared month axis. What matters is that it only ever shows
 * active non-deleted records, that a search narrows the hierarchy rather than flattening it, that
 * every row says where it stands in words, and that the expander is a button that announces
 * itself rather than a bare icon.
 */
class TimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('timeline'))->assertRedirect(route('login'));
    }

    public function test_the_screen_offers_its_heading_a_search_box_and_the_create_action(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('timeline'))
            ->assertOk()
            ->assertSee('data-test="timeline-heading"', false)
            ->assertSee(__('Timeline'))
            ->assertSee(__('Active projects and their epics, over time.'))
            ->assertSee('data-test="timeline-search"', false)
            ->assertSee('href="'.route('projects.index', ['create' => 1]).'"', false)
            ->assertSee(__('New project'));
    }

    /**
     * The hierarchy is the point of the screen: a project row first, its epics below it in the
     * order they start, so the same names that appear on the Jira-style board read top down.
     */
    public function test_a_project_is_drawn_with_its_epics_underneath_in_start_date_order(): void
    {
        $customer = Customer::factory()->create(['name' => 'Travel booking app']);
        $project = Project::factory()->for($customer)->create(['name' => 'Basic trip booking']);

        Epic::factory()->for($project)->create([
            'name' => 'As a user I can share a trip',
            'start_date' => '2026-03-01',
            'end_date' => '2026-04-01',
        ]);
        Epic::factory()->for($project)->create([
            'name' => 'Setup dev and staging',
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-01',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="timeline-project-'.$project->id.'"',
                'Basic trip booking',
                'Setup dev and staging',
                'As a user I can share a trip',
            ], false);
    }

    /**
     * An epic with no dates has no bar to place, so it has no position on the axis either: it is
     * listed after the dated ones instead of pretending to a start it does not have.
     */
    public function test_an_epic_without_dates_is_listed_after_the_dated_ones(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create();

        Epic::factory()->for($project)->withoutDates()->create(['name' => 'Undated epic']);
        Epic::factory()->for($project)->create([
            'name' => 'Dated epic',
            'start_date' => '2026-03-01',
            'end_date' => '2026-04-01',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSeeInOrder(['Dated epic', 'Undated epic']);
    }

    public function test_archived_and_trashed_records_never_reach_the_board(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create(['name' => 'Shown project']);

        Project::factory()->for($this->customer())->archived()->create(['name' => 'Hidden archived project']);
        Project::factory()->for($this->customer())->trashed()->create(['name' => 'Hidden trashed project']);
        Epic::factory()->for($project)->archived()->create(['name' => 'Hidden archived epic']);
        Epic::factory()->for($project)->trashed()->create(['name' => 'Hidden trashed epic']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSee('Shown project')
            ->assertDontSee('Hidden archived project')
            ->assertDontSee('Hidden trashed project')
            ->assertDontSee('Hidden archived epic')
            ->assertDontSee('Hidden trashed epic');
    }

    /**
     * Deactivation does not cascade, so a project whose customer has been deactivated is still
     * active work and keeps its place on the board.
     */
    public function test_an_active_project_of_an_archived_customer_still_shows_up(): void
    {
        $customer = Customer::factory()->archived()->create(['name' => 'Sleeping customer']);
        Project::factory()->for($customer)->create(['name' => 'Still running']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSee('Still running')
            ->assertSee('Sleeping customer');
    }

    /**
     * Matching the project cascades to its children: the row is the whole project, so searching
     * for it must not hide the epics that belong to it.
     */
    public function test_searching_by_project_name_shows_the_project_and_all_of_its_epics(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create(['name' => 'Alpha portal']);

        Epic::factory()->for($project)->create(['name' => 'Checkout flow']);
        Epic::factory()->for($project)->create(['name' => 'Refunds']);
        Project::factory()->for($customer)->create(['name' => 'Beta portal']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline', ['search' => 'Alpha']))
            ->assertOk()
            ->assertSee('Alpha portal')
            ->assertSee('Checkout flow')
            ->assertSee('Refunds')
            ->assertDontSee('Beta portal');
    }

    /**
     * The other way round: only the epic matched, so the project appears purely as the context
     * that holds it and its other epics stay off the board.
     */
    public function test_searching_by_epic_name_keeps_the_project_and_only_the_matching_epic(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create(['name' => 'Warehouse move']);

        Epic::factory()->for($project)->create(['name' => 'Shelf audit']);
        Epic::factory()->for($project)->create(['name' => 'Trolley counting']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline', ['search' => 'Shelf']))
            ->assertOk()
            ->assertSee('Warehouse move')
            ->assertSee('Shelf audit')
            ->assertDontSee('Trolley counting');
    }

    public function test_searching_by_customer_name_shows_its_projects_and_their_epics(): void
    {
        $customer = Customer::factory()->create(['name' => 'Northwind Traders']);
        $project = Project::factory()->for($customer)->create(['name' => 'Warehouse move']);
        Epic::factory()->for($project)->create(['name' => 'Shelf audit']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Warehouse move')
            ->assertSee('Shelf audit');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statuses(): array
    {
        return [
            'overdue' => ['overdue', 'Overdue'],
            'on track' => ['ontrack', 'On track'],
            'no date' => ['unscheduled', 'No date'],
        ];
    }

    /**
     * Both rows of the pair carry the same dates, so the project row and the epic row below it
     * are labelled alike. Dates are pinned because the factories randomise them.
     */
    #[DataProvider('statuses')]
    public function test_every_row_says_where_it_stands_in_words(string $status, string $label): void
    {
        $customer = Customer::factory()->create();
        $dates = [
            'start_date' => '2026-01-01',
            'end_date' => match ($status) {
                'overdue' => '2020-01-01',
                'ontrack' => '2099-01-01',
                default => null,
            },
        ];

        $project = Project::factory()->for($customer)->create($dates);
        $epic = Epic::factory()->for($project)->create($dates);

        $this->travelTo('2026-10-06');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="timeline-project-'.$project->id.'"',
                'data-status="'.$status.'"',
                'data-test="timeline-epic-'.$epic->id.'"',
                'data-status="'.$status.'"',
            ], false)
            ->assertSee(__($label));

        $content = $response->getContent();
        $this->assertIsString($content);

        $this->assertSame(
            2,
            substr_count($content, 'data-status="'.$status.'"'),
            "Both the project and the epic row must be labelled [{$status}].",
        );
    }

    /**
     * The chart itself: a month axis across the top, a bar for the dated record on it, and the
     * line marking today. The bar is asserted through its own element, since its position is a
     * percentage the row cannot express any other way.
     */
    public function test_the_board_draws_a_month_axis_a_bar_and_the_today_line(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create([
            'name' => 'Dated project',
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-20',
        ]);

        $this->travelTo('2026-10-06');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSee('data-test="timeline-axis"', false)
            ->assertSee('data-test="timeline-today"', false)
            ->assertSee('data-test="timeline-bar-'.$project->id.'"', false);

        // One labelled block per month of the window, each sized as a share of it.
        $content = $response->getContent();
        $this->assertIsString($content);

        $this->assertMatchesRegularExpression('/data-test="timeline-axis".*?style="width: [0-9.]+%"/s', $content);
        $this->assertMatchesRegularExpression(
            '/data-test="timeline-bar-'.$project->id.'"[^>]*style="inset-inline-start: [0-9.]+%; width: [0-9.]+%"/',
            $content,
        );
    }

    /**
     * Collapsing is the one interactive part of the screen: the toggle is a real button that
     * announces whether the epics below are shown and which rows it controls, so the state is
     * readable without seeing it.
     */
    public function test_each_project_with_epics_carries_a_button_that_controls_them(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create();
        $first = Epic::factory()->for($project)->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-01',
        ]);
        $second = Epic::factory()->for($project)->create([
            'start_date' => '2026-03-01',
            'end_date' => '2026-04-01',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="timeline-toggle-'.$project->id.'"',
                'aria-expanded="true"',
            ], false)
            ->assertSee('aria-controls="timeline-epic-'.$first->id.' timeline-epic-'.$second->id.'"', false);
    }

    public function test_a_project_without_epics_has_no_expander(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create();

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSee('data-test="timeline-project-'.$project->id.'"', false)
            ->assertDontSee('data-test="timeline-toggle-'.$project->id.'"', false);
    }

    public function test_an_empty_portfolio_explains_itself(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('timeline'))
            ->assertOk()
            ->assertSee(__('There is nothing to plan yet.'))
            ->assertSee('data-test="timeline-empty-state"', false);
    }

    public function test_a_search_without_matches_explains_that_and_offers_the_create_action(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('timeline', ['search' => 'Nothing here']))
            ->assertOk()
            ->assertSee(__('No record matches your search.'))
            ->assertSee('data-test="timeline-empty-create"', false);
    }

    /**
     * The board is paginated by project, so a large portfolio keeps the chart readable instead
     * of squeezing two years of months into one page.
     */
    public function test_the_board_is_paginated(): void
    {
        $customer = Customer::factory()->create();
        Project::factory()->for($this->customer())->count(30)->for($customer)->create();

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSee('data-test="timeline-pagination"', false);
    }
}
