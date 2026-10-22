<?php

namespace EntityProjects13\Tests;

use App\Models\User;
use Basics13\Models\Role;
use Basics13\Support\Testing\ValidatesFormRequests;
use Basics13\Tests\TestCase as Basics13TestCase;
use Carbon\CarbonImmutable;
use Entities13\Models\Entity;
use Entities13\ServiceProvider as entities13ServiceProvider;
use EntityProjects13\ServiceProvider as EntityProjects13ServiceProvider;
use Flux\FluxServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Projects13\ServiceProvider as Projects13ServiceProvider;

/**
 * The integration's own harness. Both base packages are registered here and none of this package's
 * code is stubbed: the tests exercise the real models, queries and views of both, because what is
 * being tested is how they fit together.
 *
 * The other three installation modes are not simulated here. They are the base packages' own
 * suites, which run with neither of the other two installed and are guarded by their
 * PackageIsolationTest.
 */
abstract class TestCase extends Basics13TestCase
{
    use ValidatesFormRequests;

    /**
     * Create a test entity for integration tests.
     */
    protected function entity(): Entity
    {
        return Entity::factory()->create();
    }

    protected function signIn(): User
    {
        $user = User::query()->create([
            'name' => 'Test author',
            'email' => 'author@example.test',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
        $user->assignRole(Role::query()->where('slug', 'admin')->firstOrFail());

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            FluxServiceProvider::class,
            entities13ServiceProvider::class,
            Projects13ServiceProvider::class,
            EntityProjects13ServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        Date::use(CarbonImmutable::class);

        // Allow overriding the database connection for CI (e.g., MySQL).
        // The foreign key that stops a entity from being deleted with live projects is part of
        // what is under test, so SQLite has to enforce it rather than accept the column silently.
        $app['config']->set('database.default', getenv('DB_CONNECTION') ?: 'sqlite');
        $app['config']->set('database.connections.sqlite.foreign_key_constraints', true);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('view.compiled', dirname(__DIR__).'/storage/framework/views');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The host application's layout, which these tests do not have.
        Blade::anonymousComponentPath(__DIR__.'/Fixtures/views/layouts', 'layouts');
    }
}
