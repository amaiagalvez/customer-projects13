<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use Basics13\Support\Validation\MaxLength;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

/**
 * The info icon next to a field label lists what the form asks of that field: the validations the
 * request applies, plus whatever the control itself offers, so that what the form promises and what
 * the server asks for are the same thing.
 */
class ValidationNoticeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_entity_name_notice_lists_every_rule_of_the_field(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('entities.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="entity-name-info"',
                __('Use between :min and :max characters.', ['min' => 4, 'max' => MaxLength::string()]),
                __('Must be unique.'),
            ], false);
    }

    public function test_the_project_end_date_notice_explains_the_rule_against_the_start_date(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="project-end-date-info"',
                __('Must be on or after :field.', ['field' => mb_strtolower(__('Start date'))]),
            ], false);
    }

    public function test_the_epic_start_date_notice_says_when_it_is_asked_for(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('epics.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="epic-start-date-info"',
                __('Required when :field is filled in.', ['field' => mb_strtolower(__('End date'))]),
            ], false);
    }

    public function test_the_project_entity_notice_explains_that_a_new_one_can_be_created(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="project-entity-info"',
                __('Choose a entity from the list or create a new one.'),
            ], false);
    }

    public function test_every_field_with_something_to_announce_carries_its_notice(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $forms = [
            'entities' => [route('entities.index'), [
                'entity-name-info',
                'entity-notes-info',
            ]],
            'projects' => [route('projects.index'), [
                'project-name-info',
                'project-end-date-info',
                'project-entity-info',
                'project-notes-info',
            ]],
            'epics' => [route('epics.index'), [
                'epic-name-info',
                'epic-start-date-info',
                'epic-end-date-info',
                'epic-notes-info',
                'epic-comment-body-info',
                'epic-comment-notes-info',
            ]],
        ];

        foreach ($forms as $resource => [$url, $notices]) {
            $content = $this->get($url)->assertOk()->getContent() ?: '';

            foreach ($notices as $notice) {
                $this->assertStringContainsString(
                    'data-test="'.$notice.'"',
                    $content,
                    "The {$resource} form is missing the notice of a field.",
                );
            }
        }
    }

    /**
     * A start date asked for with the asterisk has nothing left to announce, so no icon is drawn
     * next to its label.
     */
    public function test_a_field_without_anything_to_announce_carries_no_notice(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('data-test="project-start-date-info"', false);
    }
}
