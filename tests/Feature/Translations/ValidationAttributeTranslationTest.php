<?php

namespace EntityProjects13\Tests\Feature\Translations;

use EntityProjects13\Tests\TestCase;
use entities13\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ValidationAttributeTranslationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every field that can display a validation error in an application form.
     *
     * @var list<string>
     */
    private const FORM_FIELDS = [
        'name',
        'notes',
        'start_date',
        'end_date',
        'entity_id',
        'project_id',
        'body',
        'email',
        'password',
        'password_confirmation',
        'current_password',
        'code',
        'recovery_code',
        'token',
        'reuse_deleted_name',
    ];

    public function test_validation_errors_use_translated_field_names_in_every_locale(): void
    {
        $originalLocale = app()->getLocale();

        try {
            foreach ($this->locales() as $locale) {
                app()->setLocale($locale);

                $attributes = __('validation.attributes');
                $this->assertIsArray($attributes);

                foreach (self::FORM_FIELDS as $field) {
                    $validator = Validator::make([], [$field => ['required']]);
                    $validator->fails();

                    $expected = __('validation.required', [
                        'attribute' => $attributes[$field],
                    ]);

                    $this->assertSame(
                        $expected,
                        $validator->errors()->first($field),
                        "{$field} should use its translated label in {$locale}.",
                    );
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_unique_validation_errors_use_the_translated_name_in_every_locale(): void
    {
        Entity::factory()->create(['name' => 'Existing entity']);
        $originalLocale = app()->getLocale();

        try {
            foreach ($this->locales() as $locale) {
                app()->setLocale($locale);

                $attributes = __('validation.attributes');
                $this->assertIsArray($attributes);

                $validator = Validator::make(
                    ['name' => 'Existing entity'],
                    ['name' => [Rule::unique(Entity::class)->whereNull('deleted_at')]],
                );
                $validator->fails();

                $this->assertSame(
                    __('validation.unique', [
                        'attribute' => $attributes['name'],
                    ]),
                    $validator->errors()->first('name'),
                    "The unique name error should use its translated label in {$locale}.",
                );
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return ['en', 'es', 'eu', 'fr'];
    }
}
