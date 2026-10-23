<?php

namespace EntityProjects13\Tests\Unit;

use Basics13\Concerns\TracksAuditColumns;
use Basics13\Concerns\TransactionalDelete;
use Basics13\Http\Controllers\ArchivedController as BasicsArchivedController;
use Basics13\Http\Requests\RestoreRequest;
use Basics13\Http\Requests\SearchableListRequest;
use Basics13\Http\Requests\TrashDestroyRequest;
use Basics13\Support\Validation\MaxLength;
use Entities13\Http\Requests\EntityListRequest;
use Entities13\Http\Requests\EntityRequest;
use Entities13\Models\Entity;
use Entities13\Policies\EntityPolicy;
use EntityProjects13\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Http\Requests\EpicListRequest;
use Projects13\Http\Requests\ProjectListRequest;
use Projects13\Http\Requests\ProjectRequest;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;
use Projects13\Policies\EpicPolicy;
use Projects13\Policies\ProjectPolicy;
use ReflectionClass;
use ReflectionMethod;

class ResourceUniformityTest extends TestCase
{
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
        // Every policy carries the entity abilities; only these are specific to one resource.
        $entityAbilities = $this->policyAbilities(EntityPolicy::class);
        $abilitiesByResource = [
            'Entity' => $entityAbilities,
            'Project' => $this->policyAbilities(ProjectPolicy::class),
            'Epic' => $this->policyAbilities(EpicPolicy::class),
        ];
        $extraAbilities = [
            'Entity' => [],
            'Project' => ['manageMembers'],
            'Epic' => ['comment', 'viewComments'],
        ];

        foreach ($extraAbilities as $model => $extras) {
            $expected = [...$entityAbilities, ...$extras];
            sort($expected);
            $this->assertSame($expected, $abilitiesByResource[$model], "{$model} policy abilities drifted");
        }

        // Read the authorization call sites per resource, so dropping the check on one controller
        // is caught even while the other two still call it. The shared base classes
        // (InactiveController, RestoreRequest, TrashDestroyRequest...) hold call sites that apply to
        // every resource, so they count towards all three.
        $models = array_keys($abilitiesByResource);
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
            $byResource[$model] = $source.$shared;
        }

        $missing = [];

        foreach ($abilitiesByResource as $model => $abilities) {
            foreach ($abilities as $ability) {
                // `inspect()` may pick the ability with a ternary, so the literal can follow other arguments.
                $pattern = '/->(?:authorize|can|inspect)\([^;]*?[\'\"]'.preg_quote($ability, '/').'[\'\"]/';

                if (preg_match($pattern, $byResource[$model]) === 0) {
                    $missing[] = "{$model}::{$ability}";
                }
            }
        }

        $this->assertSame([], $missing, 'No HTTP call site for: '.implode(', ', $missing));
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

    /**
     * All resource models must share the same set of essential traits:
     * - SoftDeletes
     * - TracksAuditColumns
     * - TransactionalDelete
     *
     * This ensures consistency across resources and prevents drifts like
     * the EpicComment gap that was previously CRITICAL-001.
     */
    public function test_all_resource_models_have_uniform_traits(): void
    {
        $resourceModels = [Entity::class, Project::class, Epic::class, EpicComment::class];
        $requiredTraits = [SoftDeletes::class, TracksAuditColumns::class, TransactionalDelete::class];

        foreach ($resourceModels as $modelClass) {
            $traits = class_uses_recursive($modelClass);

            foreach ($requiredTraits as $trait) {
                $this->assertContains(
                    $trait,
                    $traits,
                    $modelClass.'::class must use '.$trait.' trait',
                );
            }
        }
    }

    /**
     * @param  class-string<SearchableListRequest>  $requestClass
     * @return array<string, array<int, string>>
     */
    private function listRequestRules(string $requestClass): array
    {
        $request = $requestClass::create('/', 'GET');
        $request->setRouteResolver(static fn (): null => null);

        return $request->rules();
    }

    /**
     * @param  class-string<SearchableListRequest>  $requestClass
     */
    private function normalizedSearch(string $requestClass, mixed $input): string
    {
        $request = $requestClass::create('/', 'GET', ['search' => $input]);
        $request->setRouteResolver(static fn (): null => null);

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
}
