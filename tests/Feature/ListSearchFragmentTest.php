<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ListSearchFragmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route => the prefix of the resource it lists.
     *
     * @var array<string, string>
     */
    private const ROUTES = [
        'entities.index' => 'entity',
        'entities.trash.index' => 'entity',
        'projects.index' => 'project',
        'projects.trash.index' => 'project',
        'epics.index' => 'epic',
        'epics.trash.index' => 'epic',
    ];

    public function test_all_list_routes_return_only_the_results_fragment_for_fragment_requests(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (self::ROUTES as $route => $prefix) {
            $response = $this->get(route($route), ['X-List-Fragment' => 'true']);

            $response
                ->assertOk()
                ->assertSee('data-list-results')
                ->assertSee('data-test="'.$prefix.'-search"', false)
                ->assertDontSee('<!DOCTYPE html>');
        }
    }

    /**
     * The page header holds the breadcrumbs, the state tabs and the create action, and it stays
     * outside the results fragment so the whole heading survives the morph triggered by searching.
     */
    public function test_page_header_stays_outside_the_results_fragment(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['entity', 'project', 'epic'] as $prefix) {
            $routePrefix = $prefix === 'entity' ? 'entities' : $prefix.'s';
            $this->get(route($routePrefix.'.index'), ['X-List-Fragment' => 'true'])
                ->assertOk()
                ->assertSee('data-test="'.$prefix.'-search"', false)
                ->assertDontSee('data-test="'.$prefix.'-breadcrumbs"', false)
                ->assertDontSee('data-test="'.$prefix.'-tabs"', false)
                ->assertDontSee('data-test="'.$prefix.'-create-button"', false);
        }
    }

    public function test_page_header_carries_the_create_action_on_full_page_responses(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['entity', 'project', 'epic'] as $prefix) {
            $routePrefix = $prefix === 'entity' ? 'entities' : $prefix.'s';
            $this->get(route($routePrefix.'.index'))
                ->assertOk()
                ->assertSee('data-test="'.$prefix.'-breadcrumbs"', false)
                ->assertSee('data-test="'.$prefix.'-tabs"', false)
                ->assertSee('data-test="'.$prefix.'-create-button"', false);

            $this->get(route($routePrefix.'.trash.index'))
                ->assertOk()
                ->assertSee('data-test="'.$prefix.'-tabs"', false)
                ->assertDontSee('data-test="'.$prefix.'-create-button"', false);
        }
    }

    public function test_current_list_breadcrumb_links_to_the_full_list_url(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (array_keys(self::ROUTES) as $route) {
            $url = route($route, ['search' => 'reload-check']);

            $this->get($url)
                ->assertSee('href="'.$url.'"', false);
        }
    }
}
