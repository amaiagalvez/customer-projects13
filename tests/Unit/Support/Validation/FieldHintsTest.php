<?php

namespace EntityProjects13\Tests\Unit\Support\Validation;

use Basics13\Support\Validation\FieldHints;
use Basics13\Support\Validation\MaxLength;
use Entities13\Http\Requests\EntityRequest;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Projects13\Http\Requests\EpicCommentRequest;
use Projects13\Http\Requests\EpicRequest;
use Projects13\Http\Requests\ProjectRequest;

class FieldHintsTest extends TestCase
{
    public function test_the_name_notice_spells_out_every_rule_the_request_applies(): void
    {
        $hints = (new FieldHints(EntityRequest::class))->for('name');

        $this->assertSame([
            __('Use between :min and :max characters.', ['min' => 4, 'max' => MaxLength::string()]),
            __('Must be unique.'),
        ], $hints);
    }

    public function test_an_optional_field_only_mentions_the_rules_a_person_can_act_on(): void
    {
        $hints = (new FieldHints(EntityRequest::class))->for('notes');

        $this->assertSame([
            __('Use at most :max characters.', ['max' => MaxLength::longText()]),
        ], $hints);
    }

    /**
     * A field with only one of the two limits has no range to announce, so it keeps its own
     * sentence instead of a range with a missing end.
     */
    public function test_a_field_bounded_at_one_end_only_announces_that_end(): void
    {
        $hints = new FieldHints(BoundedLengthRequest::class);

        $this->assertSame([
            __('Use at least :min characters.', ['min' => 2]),
        ], $hints->for('code'));
        $this->assertSame([
            __('Use at most :max characters.', ['max' => 8]),
        ], $hints->for('nickname'));
    }

    /**
     * The minimum and the maximum of a field bounded at both ends are one sentence, so the notice
     * does not read as two separate demands.
     */
    public function test_a_field_bounded_at_both_ends_announces_the_whole_range_once(): void
    {
        $hints = (new FieldHints(BoundedLengthRequest::class))->for('slug');

        $this->assertSame([
            __('Use between :min and :max characters.', ['min' => 3, 'max' => 12]),
        ], $hints);
    }

    public function test_the_bounds_are_announced_where_the_request_declares_them(): void
    {
        $hints = (new FieldHints(BoundedLengthRequest::class))->for('title');

        $this->assertSame([
            __('Use between :min and :max characters.', ['min' => 5, 'max' => 20]),
            __('Must be unique.'),
        ], $hints);
    }

    public function test_a_selector_only_offers_the_records_it_accepts_so_it_announces_nothing(): void
    {
        $hints = new FieldHints(ProjectRequest::class);

        $this->assertSame([], $hints->for('entity_id'));
        $this->assertTrue($hints->isRequired('entity_id'), 'the asterisk still marks it as asked for.');
    }

    public function test_a_date_notice_names_the_other_date_the_way_the_form_labels_it(): void
    {
        $hints = (new FieldHints(ProjectRequest::class, ['start_date' => 'Hasiera-data']))->for('end_date');

        $this->assertSame([
            __('Must be on or after :field.', ['field' => 'hasiera-data']),
        ], $hints);
    }

    public function test_a_date_can_be_required_only_together_with_another_one(): void
    {
        $hints = (new FieldHints(EpicRequest::class, ['end_date' => 'Amaiera-data']))->for('start_date');

        $this->assertSame([
            __('Required when :field is filled in.', ['field' => 'amaiera-data']),
        ], $hints);
    }

    public function test_the_end_date_of_an_epic_must_be_later_than_its_start_date(): void
    {
        $hints = (new FieldHints(EpicRequest::class, ['start_date' => 'Hasiera-data']))->for('end_date');

        $this->assertSame([
            __('Must be later than :field.', ['field' => 'hasiera-data']),
        ], $hints);
    }

    public function test_the_asterisk_marks_the_fields_that_are_always_asked_for(): void
    {
        $epic = new FieldHints(EpicRequest::class);

        $this->assertTrue($epic->isRequired('name'));
        $this->assertTrue($epic->isRequired('project_id'));
        $this->assertFalse($epic->isRequired('start_date'), 'required_with is not asked for every time.');
        $this->assertFalse($epic->isRequired('end_date'));
        $this->assertFalse($epic->isRequired('notes'));
    }

    public function test_a_field_the_request_does_not_validate_has_nothing_to_announce(): void
    {
        $hints = new FieldHints(EpicCommentRequest::class);

        $this->assertSame([], $hints->for('unknown_field'));
        $this->assertFalse($hints->isRequired('unknown_field'));
    }

    public function test_an_unlabelled_field_is_announced_with_a_readable_name(): void
    {
        $hints = (new FieldHints(ProjectRequest::class))->for('end_date');

        $this->assertContains(
            __('Must be on or after :field.', ['field' => 'start date']),
            $hints,
        );
    }

    /**
     * A numeric bound counts units rather than characters, so the notice stays out of the way of a
     * field that is not text.
     */
    public function test_a_field_that_is_not_text_announces_no_length(): void
    {
        $hints = new FieldHints(BoundedLengthRequest::class);

        $this->assertSame([], $hints->for('quantity'));
    }
}

/**
 * The forms of length a request can declare, gathered in one place so the notice for each of them is
 * covered without waiting for a real form to grow every shape.
 */
class BoundedLengthRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'slug' => ['string', 'min:3', 'max:12'],
            'code' => ['string', 'min:2'],
            'nickname' => ['string', 'max:8'],
            'title' => ['string', 'min:5', 'max:20', Rule::unique('titles')],
            'quantity' => ['integer', 'min:1', 'max:99'],
        ];
    }
}
