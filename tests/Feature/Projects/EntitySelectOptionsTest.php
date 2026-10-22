<?php

namespace EntityProjects13\Tests\Feature\Projects;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use DOMDocument;
use DOMXPath;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EntitySelectOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_form_does_not_preload_every_entity(): void
    {
        $this->actingAs(User::factory()->create());

        $total = ListQueryBase::PER_PAGE + 1;

        for ($index = 1; $index <= $total; $index++) {
            Entity::factory()->create([
                'name' => sprintf('Option-only entity %02d', $index),
            ]);
        }

        $response = $this->get(route('projects.index'))->assertOk();
        $content = $response->getContent();
        $this->assertIsString($content);

        $document = new DOMDocument;
        $document->loadHTML($content, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($document);
        $entitySelect = $xpath->query('//select[@data-project-entity-select]');
        $entityOptions = $xpath->query('//select[@data-project-entity-select]/option[normalize-space(.) != ""]');

        $this->assertNotFalse($entitySelect);
        $this->assertSame(1, $entitySelect->length);
        $this->assertNotFalse($entityOptions);
        $this->assertSame(0, $entityOptions->length);
    }

    public function test_project_list_offers_form_action_when_no_active_entities_exist(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee(__('No active entities are available. Open the entity form to create one.'))
            ->assertSee(__('Open entity form'))
            ->assertSee('href="'.route('entities.index', ['create' => 1]).'"', false)
            ->assertSee('project-no-related-form-button');
    }
}
