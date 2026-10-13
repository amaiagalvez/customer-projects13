<?php

namespace CustomerProjects13\Tests\Feature\Epics;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use Basics13\Support\Validation\MaxLength;
use CustomerProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;
use Projects13\Queries\Epics\EpicListQuery;

class EpicCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_comment_on_an_epic_and_author_and_time_are_stored(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create(['name' => 'Ane Author']);
        $epic = Epic::factory()->create();

        $this->actingAs($user)
            ->from(route('epics.index'))
            ->post(route('epics.comments.store', $epic), ['body' => '  First comment  '])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHas('status', __('Comment added successfully.'))
            ->assertSessionHas('commented_epic_id', $epic->id);

        $comment = EpicComment::query()->sole();
        $this->assertSame('First comment', $comment->body);

        $commentEpic = $comment->epic;
        $commentAuthor = $comment->user;
        $commentCreatedAt = $comment->created_at;

        if ($commentEpic === null || $commentAuthor === null || $commentCreatedAt === null) {
            self::fail('The comment must reference its epic, its author and its creation time.');
        }

        $this->assertTrue($commentEpic->is($epic));
        $this->assertTrue($commentAuthor->is($user));
        $this->assertTrue($commentCreatedAt->equalTo(now()));
    }

    /**
     * The drawer payload is resolved by id, so an epic outside the current page still gets it and the
     * drawer reopens after a comment. Before this, the view looked the epic up among the rows of the
     * page it was rendering, which silently failed for page 2 and beyond.
     */
    public function test_a_comment_reopens_the_drawer_for_an_epic_outside_the_first_page(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        for ($index = 1; $index <= ListQueryBase::PER_PAGE + 1; $index++) {
            Epic::factory()->for($project)->create(['name' => sprintf('Filler epic %02d', $index)]);
        }

        $target = Epic::factory()->for($project)->create(['name' => 'Commented epic']);
        EpicComment::factory()->for($target)->create(['body' => 'Saved on a later page']);

        // The epic is on page 2, so the redirect that follows the comment lands on a list that does not
        // contain it. The payload must still be resolved from the id in the session.
        $this->post(route('epics.comments.store', $target), ['body' => 'Another comment'])
            ->assertSessionHas('commented_epic_id', $target->id);

        $this->followingRedirects()
            ->post(route('epics.comments.store', $target), ['body' => 'Yet another comment'])
            ->assertOk()
            // The epic is not a row of the rendered page; only its drawer payload carries the name.
            ->assertViewHas('drawerEpic', fn (mixed $payload): bool => is_array($payload)
                && $payload['id'] === $target->id
                && $payload['name'] === 'Commented epic'
                && $payload['commentsCount'] === 3);
    }

    public function test_epic_comments_are_loaded_on_demand_with_author_and_date(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();
        EpicComment::factory()->for($epic)->for(User::factory()->state(['name' => 'Ane Author']))->create([
            'body' => 'Visible comment',
            'created_at' => '2026-10-01 09:30:00',
        ]);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertDontSee('Visible comment')
            ->assertDontSee('Ane Author');

        $this->getJson(route('epics.comments.index', $epic))
            ->assertOk()
            ->assertJsonPath('comments.0.body', 'Visible comment')
            ->assertJsonPath('comments.0.author', 'Ane Author')
            ->assertJsonPath('comments.0.dateTime', '2026-10-01T09:30:00+00:00');
    }

    public function test_comment_endpoint_shows_deleted_user_when_the_author_is_trashed(): void
    {
        $actingUser = User::factory()->create();
        // The author must be a user of its own: the factory picks whichever user it finds
        // first, which is the signed-in one, and deleting it would end the request.
        $author = User::factory()->create();
        $this->actingAs($actingUser);
        $epic = Epic::factory()->create();
        $comment = EpicComment::factory()->for($epic)->for($author, 'user')->create(['body' => 'Comment by deleted user']);

        $author->delete();

        $this->getJson(route('epics.comments.index', $epic))
            ->assertOk()
            ->assertJsonPath('comments.0.author', __('Deleted user'))
            ->assertJsonPath('comments.0.body', 'Comment by deleted user');
    }

    public function test_comment_endpoint_returns_only_the_latest_comments_for_the_requested_epic(): void
    {
        $this->actingAs(User::factory()->create());
        $limit = EpicListQuery::RECENT_COMMENTS_LIMIT;
        $epic = Epic::factory()->create();
        $otherEpic = Epic::factory()->create();

        EpicComment::factory()->for($epic)->create([
            'body' => 'Oldest hidden comment',
            'created_at' => now()->subDays(2),
        ]);

        foreach (range(1, $limit) as $index) {
            EpicComment::factory()->for($epic)->create([
                'body' => 'Recent comment '.$index,
                'created_at' => now()->subMinutes($limit - $index),
            ]);
        }

        EpicComment::factory()->for($otherEpic)->create(['body' => 'Other epic comment']);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertDontSee('Oldest hidden comment')
            ->assertDontSee('Recent comment 1')
            ->assertDontSee('Other epic comment')
            ->assertSee('epic-comments-count-'.$epic->id, false);

        $this->getJson(route('epics.comments.index', $epic))
            ->assertOk()
            ->assertJsonCount($limit, 'comments')
            ->assertJsonMissing(['body' => 'Oldest hidden comment'])
            ->assertJsonMissing(['body' => 'Other epic comment'])
            ->assertJsonPath('comments.0.body', 'Recent comment '.$limit)
            ->assertJsonPath('comments.'.($limit - 1).'.body', 'Recent comment 1');
    }

    public function test_comment_body_is_required(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();

        $this->from(route('epics.index'))
            ->post(route('epics.comments.store', $epic), ['body' => '   '])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrorsIn('comment', ['body']);

        $this->assertDatabaseCount('PRO_epic_comments', 0);
    }

    public function test_comment_notes_are_optional_and_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();

        $this->post(route('epics.comments.store', $epic), ['body' => 'Comment without notes'])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertNull(EpicComment::query()->sole()->notes);

        $this->post(route('epics.comments.store', $epic), [
            'body' => 'Comment with notes',
            'notes' => 'Mentioned in the stand-up',
        ])->assertRedirect(route('epics.index'));

        $this->assertDatabaseHas('PRO_epic_comments', [
            'body' => 'Comment with notes',
            'notes' => 'Mentioned in the stand-up',
        ]);
    }

    public function test_comment_notes_longer_than_the_configured_maximum_are_rejected_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();

        $this->from(route('epics.index'))
            ->post(route('epics.comments.store', $epic), [
                'body' => 'Comment with long notes',
                'notes' => str_repeat('x', $this->longtextLimit() + 1),
            ])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrorsIn('comment', ['notes']);

        $this->assertDatabaseCount('PRO_epic_comments', 0);
    }

    public function test_comment_body_at_the_configured_maximum_is_accepted_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();
        $body = str_repeat('x', $this->longtextLimit());

        $this->from(route('epics.index'))
            ->post(route('epics.comments.store', $epic), ['body' => $body])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epic_comments', ['epic_id' => $epic->id, 'body' => $body]);
    }

    public function test_comment_body_longer_than_the_configured_maximum_is_rejected_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();

        $this->from(route('epics.index'))
            ->post(route('epics.comments.store', $epic), [
                'body' => str_repeat('x', $this->longtextLimit() + 1),
            ])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrorsIn('comment', ['body']);

        $this->assertDatabaseCount('PRO_epic_comments', 0);
    }

    public function test_deleted_epics_cannot_receive_comments(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->trashed()->create();

        $this->post(route('epics.comments.store', $epic), ['body' => 'Late comment'])
            ->assertNotFound();

        $this->assertDatabaseCount('PRO_epic_comments', 0);
    }

    public function test_guests_cannot_comment(): void
    {
        $epic = Epic::factory()->create();

        $this->post(route('epics.comments.store', $epic), ['body' => 'Anonymous'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('PRO_epic_comments', 0);
    }

    public function test_guests_cannot_load_epic_comments(): void
    {
        $epic = Epic::factory()->create();

        $this->get(route('epics.comments.index', $epic))
            ->assertRedirect(route('login'));
    }

    public function test_trashed_epics_do_not_expose_their_comments(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->trashed()->create();

        $this->getJson(route('epics.comments.index', $epic))
            ->assertNotFound();
    }

    public function test_comments_keep_existing_when_their_author_is_deleted(): void
    {
        $comment = EpicComment::factory()->create();
        $commentAuthor = $comment->user;

        if ($commentAuthor === null) {
            self::fail('The comment must have an author.');
        }

        $commentAuthor->delete();

        $freshComment = $comment->fresh();

        if ($freshComment === null) {
            self::fail('The comment must still exist after its author is deleted.');
        }

        $this->assertSame($commentAuthor->id, $freshComment->user_id);
        $this->assertNull($freshComment->user);
    }

    public function test_epics_with_comments_cannot_be_deleted(): void
    {
        $epic = Epic::factory()->create();
        $comment = EpicComment::factory()->for($epic)->create();

        $this->assertFalse($epic->delete());

        $this->assertModelExists($epic);
        $this->assertModelExists($comment);

        $freshComment = $comment->fresh();

        if ($freshComment === null) {
            self::fail('The comment must still exist after epic deletion is refused.');
        }

        $commentEpic = $freshComment->epic;

        if ($commentEpic === null) {
            self::fail('The comment must still resolve its trashed epic.');
        }

        $this->assertTrue($commentEpic->is($epic));
    }

    /**
     * Read from the config so the boundary keeps testing the limit the rules actually apply,
     * whatever it is set to.
     */
    private function longtextLimit(): int
    {
        return MaxLength::longText();
    }
}
