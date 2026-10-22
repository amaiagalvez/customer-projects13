<?php

namespace EntityProjects13\Tests\Unit\Translations;

use EntityProjects13\Tests\TestCase;
use Illuminate\Support\Facades\Validator;

class ValidationAttributeTranslationTest extends TestCase
{
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

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return ['en', 'es', 'eu', 'fr'];
    }
}
