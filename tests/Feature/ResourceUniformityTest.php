<?php

namespace EntityProjects13\Tests\Feature;

use Basics13\Http\Controllers\ArchivedController as BasicsArchivedController;
use Basics13\Http\Requests\RestoreRequest;
use Basics13\Http\Requests\SearchableListRequest;
use Basics13\Http\Requests\TrashDestroyRequest;
use Basics13\Queries\ListQueryBase;
use Basics13\Support\Validation\MaxLength;
use Closure;
use EntityProjects13\Tests\TestCase;
use entities13\Http\Requests\EntityListRequest;
use entities13\Http\Requests\EntityRequest;
use entities13\Models\Entity;
use entities13\Policies\EntityPolicy;
use entities13\Queries\Entities\EntityListQuery;
use entities13\Transformers\EntityListTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Http\Requests\EpicListRequest;
use Projects13\Http\Requests\EpicRequest;
use Projects13\Http\Requests\ProjectListRequest;
use Projects13\Http\Requests\ProjectRequest;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Policies\EpicPolicy;
use Projects13\Policies\ProjectPolicy;
use Projects13\Queries\Epics\EpicListQuery;
use Projects13\Queries\Projects\ProjectListQuery;
use Projects13\Transformers\EpicListTransformer;
use Projects13\Transformers\ProjectListTransformer;
use ReflectionClass;
use ReflectionMethod;

class ResourceUniformityTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_requests_share_ordered_name_rules_and_deleted_name_flag(): void
    {
        $entityRequest = EntityRequest::create('/', 'POST', ['name' => 'Uniform entity']);
        $projectRequest = ProjectRequest::create('/', 'POST', ['name' => 'Uniform project']);
        $epicRequest = EpicRequest::create('/', 'POST', [
            'name' => 'Uniform epic',
            'project_id' => 1,
        ]);
        $entityRules = $this->requestRules($entityRequest, static fn(): array => $entityRequest->rules());
        $projectRules = $this->requestRules($projectRequest, static fn(): array => $projectRequest->rules());
        $epicRules = $this->requestRules($epicRequest, static fn(): array => $epicRequest->rules());

        // `entity_type_ids` is the multi-select entities13 owns, so it sits among the fields of
        // the resource rather than after the shared deleted-name flag.
        $this->assertSame(
            ['name', 'notes', 'entity_type_ids', 'entity_type_ids.*', 'reuse_deleted_name'],
            array_keys($entityRules),
        );
        // `entity_id` is contributed by amaia/entity-projects13 through the contract, so it
        // lands after the fields the base package declares rather than among them.
        $this->assertSame(
            ['name', 'start_date', 'end_date', 'notes', 'reuse_deleted_name', 'entity_id'],
            array_keys($projectRules),
        );
        $this->assertSame(
            ['name', 'start_date', 'end_date', 'project_id', 'notes', 'reuse_deleted_name'],
            array_keys($epicRules),
        );

        foreach ([$entityRules, $projectRules, $epicRules] as $rules) {
            $this->assertSame(
                ['required', 'string', 'min:4', 'max:' . MaxLength::string()],
                array_slice($rules['name'], 0, 4),
            );
            $this->assertArrayHasKey('reuse_deleted_name', $rules);
        }
    }

    public function test_list_requests_share_search_rules_and_normalization(): void
    {
        $entityRules = $this->listRequestRules(EntityListRequest::class);
        $this->assertSame($entityRules, $this->listRequestRules(ProjectListRequest::class));
        $this->assertSame($entityRules, $this->listRequestRules(EpicListRequest::class));

        $cases = [
            [null, ''],
            ['', ''],
            ['  x  ', 'x'],
            ['   ', ''],
        ];

        foreach ([EntityListRequest::class, ProjectListRequest::class, EpicListRequest::class] as $requestClass) {
            foreach ($cases as [$input, $expected]) {
                $this->assertSame($expected, $this->normalizedSearch($requestClass, $input));
            }
        }
    }

    /**
     * The shared rules have to actually reject what they claim to: an omitted term passes, an array
     * and an over-long term do not. This replaces the three per-resource ListRequest test files,
     * whose four methods were identical copies.
     *
     * @param  class-string<SearchableListRequest>  $requestClass
     */
    #[DataProvider('listRequestClasses')]
    public function test_list_requests_reject_an_invalid_search_term(string $requestClass): void
    {
        $rules = $this->listRequestRules($requestClass);

        $this->assertTrue(Validator::make([], $rules)->passes());
        $this->assertTrue(Validator::make(['search' => 'Ane'], $rules)->passes());
        $this->assertTrue(Validator::make(['search' => ['Ane']], $rules)->fails());
        $limit = MaxLength::string();
        $this->assertTrue(Validator::make(['search' => str_repeat('a', $limit + 1)], $rules)->fails());
        $this->assertTrue(Validator::make(['search' => str_repeat('a', $limit)], $rules)->passes());
    }

    /**
     * @return array<string, array{0: class-string<SearchableListRequest>}>
     */
    public static function listRequestClasses(): array
    {
        return [
            'entities' => [EntityListRequest::class],
            'projects' => [ProjectListRequest::class],
            'epics' => [EpicListRequest::class],
        ];
    }

    public function test_policy_abilities_are_uniform_and_have_http_call_sites(): void
    {
        $entityAbilities = $this->policyAbilities(EntityPolicy::class);
        $projectAbilities = $this->policyAbilities(ProjectPolicy::class);
        $epicAbilities = $this->policyAbilities(EpicPolicy::class);
        $expectedEpicAbilities = [...$entityAbilities, 'comment'];
        sort($expectedEpicAbilities);

        $this->assertSame($entityAbilities, $projectAbilities);
        $this->assertSame($expectedEpicAbilities, $epicAbilities);

        // Read the authorization call sites per resource, so dropping the check on one controller
        // is caught even while the other two still call it. The shared base classes
        // (InactiveController, RestoreRequest, TrashDestroyRequest...) hold call sites that apply to
        // every resource, so they count towards all three.
        $models = ['Entity', 'Project', 'Epic'];
        $byResource = array_fill_keys($models, '');
        $shared = '';

        foreach (
            [
                RestoreRequest::class,
                TrashDestroyRequest::class,
            ] as $requestClass
        ) {
            $path = (new ReflectionClass($requestClass))->getFileName();
            $this->assertIsString($path);
            $shared .= File::get($path);
        }

        $entityRequestPath = (new ReflectionClass(EntityRequest::class))->getFileName();
        $this->assertIsString($entityRequestPath);
        $projectRequestPath = (new ReflectionClass(ProjectRequest::class))->getFileName();
        $this->assertIsString($projectRequestPath);
        $archivedControllerPath = (new ReflectionClass(BasicsArchivedController::class))->getFileName();
        $this->assertIsString($archivedControllerPath);
        $httpPaths = [
            dirname($entityRequestPath, 2),
            dirname($projectRequestPath, 2),
            dirname($archivedControllerPath),
        ];

        foreach ($httpPaths as $httpPath) {
            foreach (File::allFiles($httpPath) as $file) {
                $contents = (string) file_get_contents($file->getPathname());
                $matched = false;

                foreach ($models as $model) {
                    if (str_contains($file->getFilename(), $model)) {
                        $byResource[$model] .= $contents;
                        $matched = true;
                    }
                }

                if (! $matched) {
                    $shared .= $contents;
                }
            }
        }

        foreach ($byResource as $model => $source) {
            $byResource[$model] = $source . $shared;
        }

        foreach ([...$entityAbilities, 'comment'] as $ability) {
            $pattern = '/->(?:authorize|can)\(\s*[\'\"]' . preg_quote($ability, '/') . '[\'\"]/';
            $missing = [];

            foreach ($byResource as $model => $source) {
                // `comment` only exists on the epic policy, so the other two are exempt.
                if ($ability === 'comment' && $model !== 'Epic') {
                    continue;
                }

                if (preg_match($pattern, $source) === 0) {
                    $missing[] = $model;
                }
            }

            $this->assertSame([], $missing, "No HTTP call site for [{$ability}] in: " . implode(', ', $missing));
        }
    }

    /**
     * Every soft-deleting model must offer the trashed factory state, which is what the test suites
     * build a trash record with. The user is soft deletable too, so it belongs in the list.
     */
    public function test_soft_deletable_factories_share_trashed_and_optional_date_states(): void
    {
        foreach ([Entity::class, Project::class, Epic::class] as $modelClass) {
            $this->assertContains(SoftDeletes::class, class_uses_recursive($modelClass));
            $this->assertTrue(method_exists($modelClass::factory(), 'trashed'));
        }

        $epic = Epic::factory()->withoutDates()->make();

        $this->assertNull($epic->start_date);
        $this->assertNull($epic->end_date);
    }

    /**
     * Every model whose table carries the active flag must cast it and offer the factory state, so
     * the three resources and the user cannot drift apart on activation.
     */
    public function test_every_model_with_an_active_flag_offers_the_archived_state(): void
    {
        foreach ([Entity::class, Project::class, Epic::class] as $modelClass) {
            $this->assertArrayHasKey(
                'active',
                (new $modelClass)->getCasts(),
                "{$modelClass} has an active flag and must declare it in casts()",
            );
            $this->assertTrue(
                method_exists($modelClass::factory(), 'archived'),
                "{$modelClass} has an active flag and must offer the archived() factory state",
            );
        }
    }

    public function test_resource_factories_generate_unique_names(): void
    {
        $entityNames = array_map(
            fn(Entity $entity): string => $this->factoryName($entity),
            Entity::factory()->count(20)->make()->all(),
        );
        $projectNames = array_map(
            fn(Project $project): string => $this->factoryName($project),
            Project::factory()->for($this->entity())->count(20)->make()->all(),
        );
        $epicNames = array_map(
            fn(Epic $epic): string => $this->factoryName($epic),
            Epic::factory()->count(20)->make()->all(),
        );

        $this->assertCount(count($entityNames), array_unique($entityNames));
        $this->assertCount(count($projectNames), array_unique($projectNames));
        $this->assertCount(count($epicNames), array_unique($epicNames));
    }

    public function test_list_queries_share_page_size_and_trashed_id_tiebreaking(): void
    {
        $this->travelTo('2026-10-03 12:00:00');
        $entity = Entity::factory()->create();
        $firstEntity = Entity::factory()->trashed()->create(['name' => 'Same deleted entity']);
        $secondEntity = Entity::factory()->trashed()->create(['name' => 'Same deleted entity']);

        $firstProject = Project::factory()->for($entity)->trashed()->create(['name' => 'Same deleted project']);
        $secondProject = Project::factory()->for($entity)->trashed()->create(['name' => 'Same deleted project']);

        $project = Project::factory()->for($this->entity())->create();
        $firstEpic = Epic::factory()->for($project)->trashed()->create(['name' => 'Same deleted epic']);
        $secondEpic = Epic::factory()->for($project)->trashed()->create(['name' => 'Same deleted epic']);

        $entityQuery = app(EntityListQuery::class);
        $projectQuery = app(ProjectListQuery::class);
        $epicQuery = app(EpicListQuery::class);
        $paginators = [
            $entityQuery->active(''),
            $entityQuery->trashed(''),
            $projectQuery->active(''),
            $projectQuery->trashed(''),
            $epicQuery->active(''),
            $epicQuery->trashed(''),
        ];

        foreach ($paginators as $paginator) {
            $this->assertSame(ListQueryBase::PER_PAGE, $paginator->perPage());
        }

        $this->assertSame(
            [$firstEntity->id, $secondEntity->id],
            $entityQuery->trashed('')->pluck('id')->all(),
        );
        $this->assertSame(
            [$firstProject->id, $secondProject->id],
            $projectQuery->trashed('')->pluck('id')->all(),
        );
        $this->assertSame(
            [$firstEpic->id, $secondEpic->id],
            $epicQuery->trashed('')->pluck('id')->all(),
        );
    }

    public function test_resource_transformers_share_action_envelopes_and_allowlisted_edit_payloads(): void
    {
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create();
        Epic::factory()->for($project)->create();

        $entityList = app(EntityListTransformer::class)->active(
            app(EntityListQuery::class)->active(''),
            '',
        );
        $projectList = app(ProjectListTransformer::class)->active(
            app(ProjectListQuery::class)->active(''),
            '',
        );
        $epicList = app(EpicListTransformer::class)->active(
            app(EpicListQuery::class)->active(''),
            '',
        );

        /** @var list<array{actions: list<array<string, mixed>>}> $entityRows */
        $entityRows = $entityList['rows'];
        /** @var list<array{actions: list<array<string, mixed>>}> $projectRows */
        $projectRows = $projectList['rows'];
        /** @var list<array{actions: list<array<string, mixed>>}> $epicRows */
        $epicRows = $epicList['rows'];

        // The types travel as string ids, the values the multiselect binds to.
        $this->assertRowActionContract($entityRows[0], 'entity', ['id', 'name', 'notes', 'entity_type_ids']);
        // The last two are the integration's, merged into the drawer payload after the base keys.
        $this->assertRowActionContract($projectRows[0], 'project', [
            'id',
            'name',
            'notes',
            'start_date',
            'end_date',
            'entity',
            'entity_id',
            'entity_name',
            'entity_active',
        ]);
        $this->assertRowActionContract($epicRows[0], 'epic', [
            'id',
            'name',
            'notes',
            'start_date',
            'end_date',
            'project_id',
            'project_label',
            'project_active',
            'commentAction',
            'commentsUrl',
            'commentsCount',
            'entity',
            'entity_active',
        ]);
    }

    public function test_row_name_button_and_edit_action_share_the_same_payload(): void
    {
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create();
        Epic::factory()->for($project)->create();

        $entityList = app(EntityListTransformer::class)->active(
            app(EntityListQuery::class)->active(''),
            '',
        );
        $projectList = app(ProjectListTransformer::class)->active(
            app(ProjectListQuery::class)->active(''),
            '',
        );
        $epicList = app(EpicListTransformer::class)->active(
            app(EpicListQuery::class)->active(''),
            '',
        );

        /** @var array<string, list<array{actions: list<array<string, mixed>>, editPayload: array<string, mixed>}>> $rows */
        $rows = [
            'entity' => $entityList['rows'],
            'project' => $projectList['rows'],
            'epic' => $epicList['rows'],
        ];

        foreach ($rows as $resource => $resourceRows) {
            $row = $resourceRows[0];

            $this->assertSame(
                $row['actions'][0]['payload'],
                $row['editPayload'],
                "{$resource}: the row name button and the edit action must open the same form data.",
            );
        }
    }

    /**
     * @param  Closure(): array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private function requestRules(FormRequest $request, Closure $rules): array
    {
        $route = new Route($request->getMethod(), '/', static fn(): null => null);
        $route->bind($request);
        $request->setRouteResolver(static fn(): Route => $route);

        return $rules();
    }

    /**
     * @param  class-string<SearchableListRequest>  $requestClass
     * @return array<string, array<int, string>>
     */
    private function listRequestRules(string $requestClass): array
    {
        $request = $requestClass::create('/', 'GET');
        $request->setRouteResolver(static fn(): null => null);

        return $request->rules();
    }

    /**
     * @param  class-string<SearchableListRequest>  $requestClass
     */
    private function normalizedSearch(string $requestClass, mixed $input): string
    {
        $request = $requestClass::create('/', 'GET', ['search' => $input]);
        $request->setRouteResolver(static fn(): null => null);

        return $request->search();
    }

    /**
     * @param  class-string  $policyClass
     * @return list<string>
     */
    private function policyAbilities(string $policyClass): array
    {
        $reflection = new ReflectionClass($policyClass);
        $abilities = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $policyClass) {
                $abilities[] = $method->getName();
            }
        }

        sort($abilities);

        return $abilities;
    }

    /**
     * @param  array{actions: list<array<string, mixed>>}  $row
     * @param  list<string>  $editPayloadKeys
     */
    private function assertRowActionContract(array $row, string $resourceKey, array $editPayloadKeys): void
    {
        $actions = $row['actions'];
        $this->assertCount(2, $actions);
        $this->assertSame(['type', 'label', 'icon', 'test', 'payload'], array_keys($actions[0]));
        $this->assertIsArray($actions[0]['payload']);
        $this->assertSame($editPayloadKeys, array_keys($actions[0]['payload']));
        $expectedConfirmKeys = [
            'type',
            'label',
            'icon',
            'test',
            'danger',
            'action',
            'method',
            'confirmTitle',
            'confirmText',
            'confirmLabel',
        ];

        if ($resourceKey !== 'epic') {
            $expectedConfirmKeys[] = 'hint';
            $this->assertSame(__('basics13::messages.archive_instead_of_delete'), $actions[1]['hint']);
        }

        $this->assertSame($expectedConfirmKeys, array_keys($actions[1]));
    }

    private function factoryName(Model $model): string
    {
        $name = $model->getAttribute('name');

        if (! is_string($name)) {
            throw new \UnexpectedValueException('Resource factories must generate string names.');
        }

        return $name;
    }
}
