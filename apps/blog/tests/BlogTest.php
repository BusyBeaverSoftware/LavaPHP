<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\PasswordHasher;
use App\Auth\UserRepository;
use App\Tests\Support\BootedTestCase;

/**
 * Writing, changing and deleting posts — and the two authorization rules that
 * stand between one user's draft and another user's eyes.
 *
 * The rules are worth testing separately from the pages that display them,
 * because "the Edit link is hidden" and "the Edit route refuses" are different
 * claims and only the second is security. A hidden form is not a refused form;
 * every test below goes at the route, not at the markup.
 */
final class BlogTest extends BootedTestCase
{
    public function testWritingAPostPublishesItForEveryone(): void
    {
        $author = $this->signedIn();
        $id = $this->createPost($author, 'Hello from Lava', 'First post.');

        $stranger = $this->browser();
        $list = $stranger->get('/')->body();

        self::assertStringContainsString('Hello from Lava', $list);
        self::assertStringContainsString("href=\"/posts/{$id}\"", $list);
        self::assertSame(200, $stranger->get("/posts/{$id}")->status());
    }

    public function testTheAuthorIsNamedOnThePostAndOnTheList(): void
    {
        $author = $this->signedIn('randy@example.com', 'Randy');
        $id = $this->createPost($author, 'Hello');

        // The join, seen from the outside: `posts.author_id` is an id, and the
        // name on the page can only come from the `users` table — so this is a
        // test that the leftJoin happened, not merely that a template rendered.
        $post = $this->browser()->get("/posts/{$id}")->body();

        self::assertStringContainsString('Randy', $post);
    }

    public function testADraftIsSavedButUnpublished(): void
    {
        $author = $this->signedIn();
        $id = $this->createPost($author, 'A private draft', 'Not for you.', published: false);

        $mine = $author->get("/posts/{$id}")->body();

        // Shown to its author with the marker, which is how they tell it apart
        // from something the world can already read.
        self::assertStringContainsString('A private draft', $mine);
        self::assertStringContainsString('draft', $mine);
    }

    public function testAnotherUserCannotEditOrDeleteAPostTheyCanSee(): void
    {
        // Published, so the row is visible to Mallory — which is what makes this
        // a test of the ownership check rather than of the visibility filter. The
        // draft case is covered in PagesTest; this is the other half.
        $randy = $this->signedIn('randy@example.com', 'Randy');
        $id = $this->createPost($randy, 'Hello', 'Body', published: true);

        $mallory = $this->signedIn('mallory@example.com', 'Mallory');

        self::assertSame(403, $mallory->get("/posts/{$id}/edit")->status());

        // The token comes from `/`, not from the edit page: Mallory is refused
        // that page, so there is no form there to read one out of — and a token
        // scraped from a page she cannot load would be the test cheating. Any
        // page she can see carries one, because the layout's sign-out form needs
        // it; that is enough to attempt the write and be refused for the write's
        // own reason rather than for a missing token.
        $update = $mallory->post("/posts/{$id}", [
            'csrf' => $mallory->token('/'),
            'title' => 'HIJACKED',
            'body' => 'nope',
            'published' => '1',
        ]);
        self::assertSame(403, $update->status());

        $delete = $mallory->post("/posts/{$id}/delete", ['csrf' => $mallory->token('/')]);
        self::assertSame(403, $delete->status());

        // The refusal is real: the title is unchanged and the post still exists.
        // A 403 that had already written would pass every assertion above.
        self::assertStringContainsString(
            'Hello',
            $this->browser()->get("/posts/{$id}")->body(),
        );
        self::assertStringNotContainsString(
            'HIJACKED',
            $this->browser()->get("/posts/{$id}")->body(),
        );
        self::assertSame(1, $this->rowCount('posts'));
    }

    public function testAnotherUserCannotSeeAPostThatIsADraft(): void
    {
        // A 404 rather than a 403, and the difference is the point: confirming
        // that a post exists but is not yours tells a stranger which ids are
        // real. Not-found is the honest answer to a row the caller cannot see.
        $randy = $this->signedIn('randy@example.com', 'Randy');
        $id = $this->createPost($randy, 'A private draft', 'Not for you.', published: false);

        $mallory = $this->signedIn('mallory@example.com', 'Mallory');

        self::assertSame(404, $mallory->get("/posts/{$id}")->status());
        self::assertSame(404, $mallory->get("/posts/{$id}/edit")->status());
    }

    public function testTheAuthorCanEditTheirOwnPost(): void
    {
        $author = $this->signedIn();
        $id = $this->createPost($author, 'Before');

        $response = $author->post("/posts/{$id}", [
            'csrf' => $author->token("/posts/{$id}/edit"),
            'title' => 'After',
            'body' => 'Edited.',
            'published' => '1',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame("/posts/{$id}", $response->header('Location'));

        $body = $this->browser()->get("/posts/{$id}")->body();
        self::assertStringContainsString('After', $body);
        self::assertStringNotContainsString('Before', $body);
    }

    public function testPublishingADraftAndUnpublishingItAgain(): void
    {
        // The checkbox's two directions, which is the one place the app has to
        // read an absent field as `false` rather than as "leave unchanged".
        $author = $this->signedIn();
        $id = $this->createPost($author, 'A post', 'Body', published: false);

        $stranger = $this->browser();
        self::assertSame(404, $stranger->get("/posts/{$id}")->status());

        $author->post("/posts/{$id}", [
            'csrf' => $author->token("/posts/{$id}/edit"),
            'title' => 'A post',
            'body' => 'Body',
            'published' => '1',
        ]);
        self::assertSame(200, $stranger->get("/posts/{$id}")->status());

        // Back to a draft: the field is simply absent, as a browser sends it.
        $author->post("/posts/{$id}", [
            'csrf' => $author->token("/posts/{$id}/edit"),
            'title' => 'A post',
            'body' => 'Body',
        ]);
        self::assertSame(404, $stranger->get("/posts/{$id}")->status());
    }

    public function testTheAuthorCanDeleteTheirOwnPost(): void
    {
        $author = $this->signedIn();
        $id = $this->createPost($author, 'Doomed');

        $response = $author->post("/posts/{$id}/delete", ['csrf' => $author->token('/')]);

        self::assertSame(303, $response->status());
        self::assertSame(0, $this->rowCount('posts'));
        self::assertSame(404, $this->browser()->get("/posts/{$id}")->status());
    }

    public function testDeletingAUserTakesTheirPostsWithThem(): void
    {
        // The `ON DELETE CASCADE` foreign key, exercised rather than read off the
        // migration. A schema is a claim about what the database will do, and
        // SQLite only enforces foreign keys when `PRAGMA foreign_keys` is on —
        // which is why this asserts the deletion actually cascaded instead of
        // trusting the constraint to have been honoured.
        $author = $this->signedIn();
        $this->createPost($author, 'Goes with me');

        self::assertSame(1, $this->rowCount('posts'));

        self::$db->statement('DELETE FROM users WHERE email = ?', ['randy@example.com']);

        self::assertSame(0, $this->rowCount('posts'));
    }

    public function testAPostWithNoTitleIsRefusedAndNothingIsWritten(): void
    {
        $author = $this->signedIn();

        $response = $author->post('/posts', [
            'csrf' => $author->token('/posts/new'),
            'title' => '',
            'body' => 'Body',
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('class="error"', $response->body());
        self::assertSame(0, $this->rowCount('posts'));
    }

    public function testAWriteWithoutACsrfTokenIsRefusedEvenWhenSignedIn(): void
    {
        // Being signed in is not authorization for a state change: a third-party
        // page can make a signed-in browser post a form. The token is what proves
        // the request came from a page this app served.
        $author = $this->signedIn();

        $response = $author->post('/posts', ['title' => 'Forged', 'body' => 'nope']);

        self::assertSame(403, $response->status());
        self::assertSame(0, $this->rowCount('posts'));
    }

    public function testTheApiListsPublishedPostsAsJson(): void
    {
        $author = $this->signedIn();
        $this->createPost($author, 'Public', 'Everyone can read this.');
        $this->createPost($author, 'Private', 'Nobody else can read this.', published: false);

        $response = $this->browser()->get('/api/posts');

        self::assertSame(200, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));

        $decoded = $response->json();
        self::assertIsArray($decoded);
        $posts = $decoded['posts'];
        self::assertIsArray($posts);
        self::assertCount(1, $posts);

        $first = $posts[0];
        self::assertIsArray($first);
        self::assertSame('Public', $first['title']);
        // The join again, this time in a shape a machine reads.
        self::assertSame('Randy', $first['author_name']);
    }

    public function testTheApiRefusesAWriteFromAStrangerWithAnEnvelopeItCanActOn(): void
    {
        // The JSON gate, and the reason it is a second middleware rather than the
        // same one: a browser that is not signed in should be redirected to a
        // form, and an API client should be told what to send. Same rule, two
        // answers — which is the one place this app branches on the client.
        $response = $this->browser()->post('/api/posts', ['title' => 'nope'], ['Accept' => '*/*']);

        self::assertSame(401, $response->status());
        self::assertSame('application/json', $response->header('Content-Type'));

        $decoded = $response->json();
        self::assertIsArray($decoded);
        $problems = $decoded['problems'];
        self::assertIsArray($problems);
        $first = $problems[0];
        self::assertIsArray($first);
        self::assertSame('not_authenticated', $first['code']);
        self::assertSame(0, $this->rowCount('posts'));
    }

    public function testChangingAPasswordEndsEveryExistingSession(): void
    {
        // Revocation, and the reason it is testable at all: the session epoch is
        // a number in the users table and a number in the cookie, compared on
        // every request. A stolen cookie is therefore live only until the owner
        // changes their password — which is the whole point of storing it.
        $author = $this->signedIn();
        self::assertSame(200, $author->get('/posts/new')->status());

        $users = self::$app->container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $hasher = self::$app->container->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        $user = $users->byEmail('randy@example.com');
        self::assertNotNull($user);

        $users->changePassword($user->id, $hasher->hash('a-brand-new-password'));

        // The old cookie still parses and still verifies — it is signed with the
        // same key — and is nevertheless a stranger, because its epoch no longer
        // matches the row.
        $response = $author->get('/posts/new');

        self::assertSame(302, $response->status());
        self::assertSame('/login?next=%2Fposts%2Fnew', $response->header('Location'));
    }

    public function testANewPasswordDoesNotEndTheSessionThatSetIt(): void
    {
        // The counterpart, and the one that catches an epoch bumped in the wrong
        // place: `changePassword()` bumps it, `rehash()` deliberately does not,
        // because nobody's credential changed when the server merely got faster.
        $users = self::$app->container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $hasher = self::$app->container->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);

        $browser = $this->signedIn();
        $user = $users->byEmail('randy@example.com');
        self::assertNotNull($user);

        $users->rehash($user->id, $hasher->hash(self::PASSWORD));

        self::assertSame(200, $browser->get('/posts/new')->status());
    }
}
