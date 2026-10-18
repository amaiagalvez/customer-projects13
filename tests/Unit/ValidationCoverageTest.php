<?php

namespace EntityProjects13\Tests\Unit;

use Basics13\Support\Validation\MaxLength;
use EntityProjects13\Tests\TestCase;
use entities13\Http\Requests\EntityRequest;
use entities13\Models\Entity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Foundation\Http\FormRequest;
use Projects13\Http\Requests\EpicCommentRequest;
use Projects13\Http\Requests\EpicRequest;
use Projects13\Http\Requests\ProjectRequest;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;
use ReflectionClass;

class ValidationCoverageTest extends TestCase
{
    /**
     * Models whose user input is validated by a project FormRequest.
     * User is excluded: registration and profile updates are handled by Fortify.
     *
     * @var array<class-string, class-string>
     */
    private const PAIRS = [
        Entity::class => EntityRequest::class,
        Project::class => ProjectRequest::class,
        Epic::class => EpicRequest::class,
        EpicComment::class => EpicCommentRequest::class,
    ];

    public function test_every_fillable_attribute_has_a_validation_rule(): void
    {
        foreach (self::PAIRS as $model => $request) {
            $fillable = $this->fillableAttributes($model);
            $this->assertNotEmpty($fillable, "{$model} must declare #[Fillable]");

            $rules = $this->rulesOf($request);

            foreach ($fillable as $attribute) {
                $this->assertArrayHasKey(
                    $attribute,
                    $rules,
                    "{$model}::\${$attribute} is mass assignable but has no rule in {$request}",
                );
            }
        }
    }

    /**
     * Covers direct string()/boolean() reads with literal keys, not other input APIs or helpers.
     */
    public function test_direct_literal_string_and_boolean_inputs_have_form_request_rules(): void
    {
        $controllerFiles = [
            ...(glob(dirname($this->requestDirectory(EntityRequest::class)) . '/Controllers/*.php') ?: []),
            ...(glob(dirname($this->requestDirectory(ProjectRequest::class)) . '/Controllers/*.php') ?: []),
        ];

        foreach ($controllerFiles as $file) {
            if (basename($file) === 'Controller.php') {
                continue;
            }

            $source = (string) file_get_contents($file);
            $blocks = preg_split('/(?=public function )/', $source) ?: [];

            foreach ($blocks as $block) {
                if (! preg_match('/public function (\w+)\s*\(/', $block, $method)) {
                    continue;
                }

                if (! preg_match_all('/\$request->(?:string|boolean)\(\s*[\'"]([^\'"]+)[\'"]/', $block, $keys)) {
                    continue;
                }

                if (preg_match('/(\w+Request)\s+\$request\b/', $block, $request) !== 1) {
                    $this->fail(
                        basename($file) . '::' . $method[1] . ' reads input without declaring a FormRequest',
                    );
                }

                if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                    $this->fail('Missing namespace declaration in ' . basename($file));
                }

                $requestNamespace = str_replace('\\Controllers', '\\Requests', $namespace[1]);
                $rules = $this->rulesOf($requestNamespace . '\\' . $request[1]);

                foreach (array_unique($keys[1]) as $key) {
                    $this->assertArrayHasKey(
                        $key,
                        $rules,
                        basename($file) . '::' . $method[1] . " reads \${$key} but {$request[1]} has no rule for it",
                    );
                }
            }
        }
    }

    /**
     * The string length limits live in config/validation.php, reached through MaxLength, so that
     * changing one reaches the rules, the maxlength attributes of the forms and the boundary tests
     * at once. A rule that spells its own number is what such a change silently leaves behind, so
     * the literal is what this test looks for.
     */
    public function test_string_length_limits_are_not_written_out_in_the_rules(): void
    {
        $literals = [];

        foreach (
            [
                $this->requestDirectory(EntityRequest::class),
                $this->requestDirectory(ProjectRequest::class),
            ] as $directory
        ) {
            foreach (glob($directory . '/*.php') ?: [] as $path) {
                preg_match_all('/\'(max:\d+)\'/', (string) file_get_contents($path), $matches);

                foreach ($matches[1] as $limit) {
                    $literals[] = basename($path) . ' hardcodes max:' . $limit;
                }
            }
        }

        $this->assertSame(
            [],
            $literals,
            'A string length limit belongs in config/validation.php, read through MaxLength.',
        );
    }

    /**
     * MaxLength hands the limit over as an int, and it refuses a key that is missing or is not one,
     * so reaching both of them here means every rule can be built.
     */
    public function test_the_config_declares_a_usable_limit_for_each_text_size(): void
    {
        $this->assertGreaterThan(0, MaxLength::string());
        $this->assertGreaterThan(0, MaxLength::longText());
    }

    /**
     * @param  class-string  $model
     * @return array<int, string>
     */
    private function fillableAttributes(string $model): array
    {
        $attributes = (new ReflectionClass($model))
            ->getAttributes(Fillable::class);

        return $attributes !== [] ? $attributes[0]->getArguments()[0] : [];
    }

    /** @param class-string<FormRequest> $request */
    private function requestDirectory(string $request): string
    {
        $path = (new ReflectionClass($request))->getFileName();
        $this->assertIsString($path);

        return dirname($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesOf(string $request): array
    {
        /** @var FormRequest $instance */
        $instance = new $request;
        $instance->setRouteResolver(fn() => null);

        if (! method_exists($instance, 'rules')) {
            $this->fail("{$request} must define rules()");
        }

        $rules = $instance->rules();
        $this->assertIsArray($rules);
        $this->assertNotSame([], $rules, "{$request}::rules() must not be empty");

        return $rules;
    }
}
