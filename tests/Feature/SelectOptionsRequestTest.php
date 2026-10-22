<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use Entities13\Http\Requests\EntitySelectOptionsRequest;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Http\Requests\ProjectSelectOptionsRequest;
use Projects13\Models\Project;

/**
 * Both select-options endpoints share one request base, so their validation behaves the same. The
 * per-resource option tests only covered the happy path and the length limit.
 */
class SelectOptionsRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: non-empty-string, 1: class-string<EntitySelectOptionsRequest|ProjectSelectOptionsRequest>}>
     */
    public static function optionsRequests(): array
    {
        return [
            'entities' => ['entities.options', EntitySelectOptionsRequest::class],
            'projects' => ['projects.options', ProjectSelectOptionsRequest::class],
        ];
    }

    /**
     * @param  class-string<EntitySelectOptionsRequest|ProjectSelectOptionsRequest>  $requestClass
     */
    #[DataProvider('optionsRequests')]
    public function test_the_term_is_trimmed_before_matching(string $routeName, string $requestClass): void
    {
        $this->actingAs(User::factory()->create());
        $name = $routeName === 'entities.options' ? 'Trimmed entity' : 'Trimmed project';

        $this->recordFor($routeName, $name);

        $this->getJson(route($routeName, ['q' => '  Trimmed  ']))
            ->assertOk()
            ->assertJsonPath('results.0.text', $this->expectedText($routeName, $name));
    }

    /**
     * A term that matches nothing returns an empty set rather than an error.
     *
     * @param  class-string<EntitySelectOptionsRequest|ProjectSelectOptionsRequest>  $requestClass
     */
    #[DataProvider('optionsRequests')]
    public function test_a_term_that_matches_nothing_returns_no_options(string $routeName, string $requestClass): void
    {
        $this->actingAs(User::factory()->create());
        $this->recordFor($routeName, 'Something else');

        $this->getJson(route($routeName, ['q' => 'no such thing']))
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    /**
     * Without a term the endpoint still answers the first page of active records.
     *
     * @param  class-string<EntitySelectOptionsRequest|ProjectSelectOptionsRequest>  $requestClass
     */
    #[DataProvider('optionsRequests')]
    public function test_the_options_are_limited_to_the_shared_page_size(string $routeName, string $requestClass): void
    {
        $this->actingAs(User::factory()->create());

        for ($index = 1; $index <= ListQueryBase::PER_PAGE + 1; $index++) {
            $this->recordFor($routeName, sprintf('Option %02d', $index));
        }

        $this->getJson(route($routeName))
            ->assertOk()
            ->assertJsonCount(ListQueryBase::PER_PAGE, 'results');
    }

    /**
     * A project is labelled "name (entity)", so it is created under a entity whose name is known.
     */
    private function recordFor(string $routeName, string $name): Entity|Project
    {
        if ($routeName === 'entities.options') {
            return Entity::factory()->create(['name' => $name]);
        }

        return Project::factory()->for($this->entity())
            ->for(Entity::factory()->create(['name' => $name]))
            ->create(['name' => $name]);
    }

    private function expectedText(string $routeName, string $name): string
    {
        return $routeName === 'entities.options' ? $name : $name.' ('.$name.')';
    }
}
