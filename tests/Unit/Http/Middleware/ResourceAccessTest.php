<?php

namespace EntityProjects13\Tests\Unit\Http\Middleware;

use App\Models\User;
use EntityProjects13\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ResourceAccessTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function resources(): array
    {
        return [
            'entities' => ['entities.index', 'entities.trash.index', 'entities.archived.index'],
            'projects' => ['projects.index', 'projects.trash.index', 'projects.archived.index'],
            'epics' => ['epics.index', 'epics.trash.index', 'epics.archived.index'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function indexRoutes(): array
    {
        return [
            'entities' => ['entities.index'],
            'projects' => ['projects.index'],
            'epics' => ['epics.index'],
        ];
    }

    /**
     * @param  non-empty-string  $indexRoute
     * @param  non-empty-string  $trashRoute
     * @param  non-empty-string  $archivedRoute
     */
    #[DataProvider('resources')]
    public function test_guests_are_redirected_from_resource_lists_to_login(
        string $indexRoute,
        string $trashRoute,
        string $archivedRoute,
    ): void {
        $this->get(route($indexRoute))->assertRedirect(route('login'));
        $this->get(route($trashRoute))->assertRedirect(route('login'));
        $this->get(route($archivedRoute))->assertRedirect(route('login'));
    }

    /** @param non-empty-string $indexRoute */
    #[DataProvider('indexRoutes')]
    public function test_unverified_users_are_redirected_to_email_verification(string $indexRoute): void
    {
        $this->actAsInMemoryUser(unverified: true)
            ->get(route($indexRoute))
            ->assertRedirect(route('verification.notice'));
    }

    /** @param non-empty-string $indexRoute */
    #[DataProvider('indexRoutes')]
    public function test_invalid_search_input_is_rejected(string $indexRoute): void
    {
        $this->actAsInMemoryUser()
            ->get(route($indexRoute, ['search' => ['Ane']]))
            ->assertSessionHasErrors(['search']);
    }

    private function actAsInMemoryUser(bool $unverified = false): static
    {
        $user = $unverified
            ? User::factory()->unverified()->make()
            : User::factory()->make();
        $user->forceFill(['id' => 1]);

        return $this->actingAs($user);
    }
}
