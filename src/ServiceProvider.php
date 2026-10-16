<?php

namespace CustomerProjects13;

use CustomerProjects13\Integrations\CustomerCountsIntegration;
use CustomerProjects13\Integrations\CustomerProjectsIntegration;
use Customers13\Contracts\CustomerIntegration;
use Customers13\Models\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Projects13\Contracts\ProjectDisplayIntegration;
use Projects13\Contracts\ProjectQueryIntegration;
use Projects13\Contracts\ProjectValidationIntegration;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'customer-projects13');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'customer-projects13');
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/customer-projects13'),
        ], 'customer-projects13-views');

        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js/vendor/customer-projects13'),
            __DIR__.'/../resources/css' => resource_path('css/vendor/customer-projects13'),
        ], 'customer-projects13-assets');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/customer-projects13'),
        ], 'customer-projects13-lang');

        $this->declareIntegrationViews();
        $this->declareProjectCustomerRelations();
        $this->blockCustomerDeletionWithProjects();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/customer-projects13.php', 'customer-projects13');

        // Both bindings are unconditional: this package is the one that knows how a project and a
        // customer fit together, and the base packages keep whatever they bound when they found
        // nobody else to ask. Composer's auto-discovery registers providers in dependency order,
        // which puts this one last, but the base packages use `singletonIf` as well so the outcome
        // does not depend on that order being right.
        //
        // Since PHP doesn't support multiple inheritance, we create a single instance and bind it
        // to all three abstract class contracts. The instance has all required methods because
        // CustomerProjectsIntegration implements the full interface surface.
        $integration = new CustomerProjectsIntegration;
        $this->app->instance(ProjectValidationIntegration::class, $integration);
        $this->app->instance(ProjectQueryIntegration::class, $integration);
        $this->app->instance(ProjectDisplayIntegration::class, $integration);
        $this->app->singleton(CustomerIntegration::class, CustomerCountsIntegration::class);
    }

    /**
     * Where each base package looks for the views this package fills in. The base packages only know
     * a nullable view name and how many columns it renders, which is what lets their tables span an
     * empty row correctly without knowing what the columns are.
     */
    private function declareIntegrationViews(): void
    {
        config([
            'projects13.views.project_form' => [
                'fields' => 'customer-projects13::projects.form-fields',
                'state' => ['customer_id', 'customer_name', 'customerCreating', 'customerCreateError'],
                'submit_guard' => 'Boolean(form.customerCreating)',
            ],
            'projects13.views.project_list' => [
                'columns' => 'customer-projects13::projects.list-columns',
                'cells' => 'customer-projects13::projects.list-cells',
                'count' => 1,
            ],
            'projects13.views.epic_list' => [
                'columns' => 'customer-projects13::epics.list-columns',
                'cells' => 'customer-projects13::epics.list-cells',
                'count' => 1,
            ],

            'customers13.views.customer_list' => [
                'columns' => 'customer-projects13::customers.list-columns',
                'cells' => 'customer-projects13::customers.list-cells',
                'count' => 3,
            ],
        ]);
    }

    /**
     * `Project::customer()`, `Customer::projects()` and `Customer::epics()` are resolved here rather
     * than declared on the models, because the columns behind them only exist once this package is
     * installed. That keeps `withCount`, `withExists` and `Project::factory()->for($customer)`
     * working without either base package naming the other.
     */
    private function declareProjectCustomerRelations(): void
    {
        // The keys are spelled out because `belongsTo()` and `hasMany()` guess them from the name
        // of the function that calls them, and inside a closure there is no such name to guess from.
        Project::resolveRelationUsing(
            'customer',
            fn (Project $project): BelongsTo => $project
                ->belongsTo(Customer::class, 'customer_id', 'id')
                ->withTrashed(),
        );

        Customer::resolveRelationUsing(
            'projects',
            fn (Customer $customer): HasMany => $customer->hasMany(Project::class, 'customer_id', 'id'),
        );

        Customer::resolveRelationUsing(
            'epics',
            fn (Customer $customer): HasManyThrough => $customer->hasManyThrough(
                Epic::class,
                Project::class,
                'customer_id',
                'project_id',
            ),
        );
    }

    /**
     * A customer cannot reach the trash while it has projects; trashed projects count too.
     *
     * The lock is taken in a transaction of its own, which is only safe because `Customer::delete()`
     * opens an enclosing one on the model's connection: the lock stays held until the soft delete is
     * actually written, so a project that appears between the check and the delete makes the delete
     * wait and then fail. Without the outer transaction the guarantee would only hold for callers
     * that remembered to open one.
     */
    private function blockCustomerDeletionWithProjects(): void
    {
        Customer::deleting(function (Customer $customer): bool {
            $locked = DB::transaction(static fn (): Customer => Customer::withTrashed()
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->firstOrFail());

            return ! $locked->projects()->withTrashed()->exists();
        });
    }
}
