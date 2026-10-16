<?php

namespace CustomerProjects13\Tests;

use App\Models\User;
use Basics13\ServiceProvider as Basics13ServiceProvider;
use Basics13\Support\Testing\ValidatesFormRequests;
use Carbon\CarbonImmutable;
use CustomerProjects13\ServiceProvider as CustomerProjects13ServiceProvider;
use Customers13\Models\Customer;
use Customers13\ServiceProvider as Customers13ServiceProvider;
use Flux\FluxServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;
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
abstract class TestCase extends TestbenchTestCase
{
    use RefreshDatabase, ValidatesFormRequests;

    /**
     * Create a test customer for integration tests.
     */
    protected function customer(): Customer
    {
        return Customer::factory()->create();
    }

    protected function signIn(): User
    {
        $user = User::query()->create([
            'name' => 'Test author',
            'email' => 'author@example.test',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            FluxServiceProvider::class,
            Basics13ServiceProvider::class,
            Customers13ServiceProvider::class,
            Projects13ServiceProvider::class,
            CustomerProjects13ServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        Date::use(CarbonImmutable::class);

        $app['config']->set('database.default', getenv('DB_CONNECTION') ?: 'sqlite');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        // The foreign key that stops a customer from being deleted with live projects is part of
        // what is under test, so SQLite has to enforce it rather than accept the column silently.
        $app['config']->set('database.connections.sqlite.foreign_key_constraints', true);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('view.compiled', dirname(__DIR__).'/storage/framework/views');
    }

    protected function defineRoutes($router): void
    {
        Route::get('login', static fn (): string => 'Login')->name('login');
        Route::get('email/verify', static fn (): string => 'Verify email')->name('verification.notice');
        Route::get('dashboard', static fn (): string => 'Dashboard')->name('dashboard');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The host application's layout, which these tests do not have.
        Blade::anonymousComponentPath(__DIR__.'/Fixtures/views/layouts', 'layouts');
    }
}
