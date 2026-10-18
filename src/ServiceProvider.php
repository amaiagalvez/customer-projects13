<?php

namespace EntityProjects13;

use EntityProjects13\Integrations\EntityCountsIntegration;
use EntityProjects13\Integrations\EntityProjectsIntegration;
use entities13\Contracts\EntityIntegration;
use entities13\Models\Entity;
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
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'entity-projects13');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'entity-projects13');
        $this->loadJsonTranslationsFrom(__DIR__ . '/../resources/lang');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/entity-projects13'),
        ], 'entity-projects13-views');

        $this->publishes([
            __DIR__ . '/../resources/js' => resource_path('js/vendor/entity-projects13'),
            __DIR__ . '/../resources/css' => resource_path('css/vendor/entity-projects13'),
        ], 'entity-projects13-assets');

        $this->publishes([
            __DIR__ . '/../resources/lang' => lang_path('vendor/entity-projects13'),
        ], 'entity-projects13-lang');

        $this->declareIntegrationViews();
        $this->declareProjectEntityRelations();
        $this->blockEntityDeletionWithProjects();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/entity-projects13.php', 'entity-projects13');

        // Both bindings are unconditional: this package is the one that knows how a project and a
        // entity fit together, and the base packages keep whatever they bound when they found
        // nobody else to ask. Composer's auto-discovery registers providers in dependency order,
        // which puts this one last, but the base packages use `singletonIf` as well so the outcome
        // does not depend on that order being right.
        //
        // Since PHP doesn't support multiple inheritance, we create a single instance and bind it
        // to all three abstract class contracts. The instance has all required methods because
        // EntityProjectsIntegration implements the full interface surface.
        $integration = new EntityProjectsIntegration;
        $this->app->instance(ProjectValidationIntegration::class, $integration);
        $this->app->instance(ProjectQueryIntegration::class, $integration);
        $this->app->instance(ProjectDisplayIntegration::class, $integration);
        $this->app->singleton(EntityIntegration::class, EntityCountsIntegration::class);
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
                'fields' => 'entity-projects13::projects.form-fields',
                'state' => ['entity_id', 'entity_name', 'entity_active', 'entityCreating', 'entityCreateError'],
                'submit_guard' => 'Boolean(form.entityCreating)',
            ],
            'projects13.views.project_list' => [
                'columns' => 'entity-projects13::projects.list-columns',
                'cells' => 'entity-projects13::projects.list-cells',
                'count' => 1,
            ],
            'projects13.views.epic_list' => [
                'columns' => 'entity-projects13::epics.list-columns',
                'cells' => 'entity-projects13::epics.list-cells',
                'count' => 1,
            ],

            'entities13.views.entity_list' => [
                'columns' => 'entity-projects13::entities.list-columns',
                'cells' => 'entity-projects13::entities.list-cells',
                'count' => 3,
            ],
        ]);
    }

    /**
     * `Project::entity()`, `Entity::projects()` and `Entity::epics()` are resolved here rather
     * than declared on the models, because the columns behind them only exist once this package is
     * installed. That keeps `withCount`, `withExists` and `Project::factory()->for($entity)`
     * working without either base package naming the other.
     */
    private function declareProjectEntityRelations(): void
    {
        // The keys are spelled out because `belongsTo()` and `hasMany()` guess them from the name
        // of the function that calls them, and inside a closure there is no such name to guess from.
        Project::resolveRelationUsing(
            'entity',
            fn(Project $project): BelongsTo => $project
                ->belongsTo(Entity::class, 'entity_id', 'id')
                ->withTrashed(),
        );

        Entity::resolveRelationUsing(
            'projects',
            fn(Entity $entity): HasMany => $entity->hasMany(Project::class, 'entity_id', 'id'),
        );

        Entity::resolveRelationUsing(
            'epics',
            fn(Entity $entity): HasManyThrough => $entity->hasManyThrough(
                Epic::class,
                Project::class,
                'entity_id',
                'project_id',
            ),
        );
    }

    /**
     * A entity cannot reach the trash while it has projects; trashed projects count too.
     *
     * The lock is taken in a transaction of its own, which is only safe because `Entity::delete()`
     * opens an enclosing one on the model's connection: the lock stays held until the soft delete is
     * actually written, so a project that appears between the check and the delete makes the delete
     * wait and then fail. Without the outer transaction the guarantee would only hold for callers
     * that remembered to open one.
     */
    private function blockEntityDeletionWithProjects(): void
    {
        Entity::deleting(function (Entity $entity): bool {
            $locked = DB::transaction(static fn(): Entity => Entity::withTrashed()
                ->whereKey($entity->getKey())
                ->lockForUpdate()
                ->firstOrFail());

            return ! Project::withTrashed()->where('entity_id', $locked->getKey())->exists();
        });
    }
}
