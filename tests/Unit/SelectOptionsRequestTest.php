<?php

namespace EntityProjects13\Tests\Unit;

use Basics13\Support\Validation\MaxLength;
use Entities13\Http\Requests\EntitySelectOptionsRequest;
use EntityProjects13\Tests\TestCase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Http\Requests\ProjectSelectOptionsRequest;

/**
 * Both select-options endpoints share one request base, so their validation behaves the same. The
 * per-resource option tests only covered the happy path and the length limit.
 */
class SelectOptionsRequestTest extends TestCase
{
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
     * The rules have to reject an array term and an over-long one.
     *
     * @param  class-string<EntitySelectOptionsRequest|ProjectSelectOptionsRequest>  $requestClass
     */
    #[DataProvider('optionsRequests')]
    public function test_an_invalid_term_is_rejected(string $routeName, string $requestClass): void
    {
        $rules = $this->rulesOf($requestClass);
        $limit = MaxLength::string();

        $this->assertTrue(Validator::make([], $rules)->passes());
        $this->assertTrue(Validator::make(['q' => 'Ane'], $rules)->passes());
        $this->assertTrue(Validator::make(['q' => ['Ane']], $rules)->fails());
        $this->assertTrue(Validator::make(['q' => str_repeat('a', $limit + 1)], $rules)->fails());
        $this->assertTrue(Validator::make(['q' => str_repeat('a', $limit)], $rules)->passes());
    }

    /**
     * The rules are read off the request instance, where the shared base declares them.
     *
     * @param  class-string<EntitySelectOptionsRequest|ProjectSelectOptionsRequest>  $requestClass
     * @return array<string, array<int, string>>
     */
    private function rulesOf(string $requestClass): array
    {
        return match ($requestClass) {
            EntitySelectOptionsRequest::class => EntitySelectOptionsRequest::create('/', 'GET')->rules(),
            default => ProjectSelectOptionsRequest::create('/', 'GET')->rules(),
        };
    }
}
