<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every route that writes must be closed to guests and to users who have not verified their email.
 *
 * ResourceAccessTest only walks the two list routes of each resource, so store, update, destroy,
 * deactivate, reactivate, restore and force delete had no access test at all.
 */
class ResourceWriteAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: 'entity'|'project'|'epic', 1: non-empty-string}>
     */
    public static function writeRoutes(): array
    {
        $routes = [];

        foreach (['entity', 'project', 'epic'] as $singular) {
            $plural = $singular === 'entity' ? 'entities' : $singular.'s';

            $routes["{$singular} store"] = [$singular, $plural.'.store'];
            $routes["{$singular} update"] = [$singular, $plural.'.update'];
            $routes["{$singular} destroy"] = [$singular, $plural.'.destroy'];
            $routes["{$singular} deactivate"] = [$singular, $plural.'.archive'];
            $routes["{$singular} reactivate"] = [$singular, $plural.'.archived.activate'];
            $routes["{$singular} restore"] = [$singular, $plural.'.trash.restore'];
            $routes["{$singular} force delete"] = [$singular, $plural.'.trash.destroy'];
        }

        return $routes;
    }

    /**
     * @param  'entity'|'project'|'epic'  $singular
     * @param  non-empty-string  $routeName
     */
    #[DataProvider('writeRoutes')]
    public function test_guests_cannot_write(string $singular, string $routeName): void
    {
        $this->attempt($routeName, $singular)
            ->assertRedirect(route('login'));
    }

    /**
     * @param  'entity'|'project'|'epic'  $singular
     * @param  non-empty-string  $routeName
     */
    #[DataProvider('writeRoutes')]
    public function test_unverified_users_cannot_write(string $singular, string $routeName): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->attempt($routeName, $singular)
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * The select-options endpoints answer JSON, so a guest is refused with 401, not redirected.
     */
    #[DataProvider('selectOptionsRoutes')]
    public function test_guests_cannot_read_the_select_options(string $routeName): void
    {
        $this->getJson(route($routeName))->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: non-empty-string}>
     */
    public static function selectOptionsRoutes(): array
    {
        return [
            'entities' => ['entities.options'],
            'projects' => ['projects.options'],
        ];
    }

    /**
     * @param  non-empty-string  $routeName
     * @param  'entity'|'project'|'epic'  $singular
     */
    /**
     * @param  non-empty-string  $routeName
     * @param  'entity'|'project'|'epic'  $singular
     * @return TestResponse<Response>
     */
    private function attempt(string $routeName, string $singular)
    {
        $isTrashRoute = str_contains($routeName, '.trash.');
        $record = $this->record($singular, trashed: $isTrashRoute);
        $url = route($routeName, $record);

        return match (true) {
            str_ends_with($routeName, '.store') => $this->post($url, $this->payloadFor($singular)),
            str_ends_with($routeName, '.destroy'), str_ends_with($routeName, 'trash.destroy') => $this->delete($url),
            default => $this->patch($url, $this->payloadFor($singular)),
        };
    }

    private function record(string $singular, bool $trashed = false): Entity|Project|Epic
    {
        return match ([$singular, $trashed]) {
            ['entity', false] => Entity::factory()->create(),
            ['entity', true] => Entity::factory()->trashed()->create(),
            ['project', false] => Project::factory()->for($this->entity())->create(),
            ['project', true] => Project::factory()->for($this->entity())->trashed()->create(),
            default => $trashed
                ? Epic::factory()->trashed()->create()
                : Epic::factory()->create(),
        };
    }

    /**
     * @param  'entity'|'project'|'epic'  $singular
     * @return array<string, mixed>
     */
    private function payloadFor(string $singular): array
    {
        return match ($singular) {
            'entity' => ['name' => 'Rejected entity'],
            'project' => ['name' => 'Rejected project', 'start_date' => '2045-07-09'],
            default => ['name' => 'Rejected epic'],
        };
    }
}
