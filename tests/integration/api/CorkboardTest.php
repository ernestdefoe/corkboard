<?php

namespace ErnestDefoe\Corkboard\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Users: 1 admin, 2 normal (a member on no team), 3 ana (owner of every board), 4 marc (team
 * editor by name), 5 vol (in the Volunteers group, on board 2's team through it), 6 mod.
 * Boards: 1 public, 2 team, 3 private (marc by name, Volunteers as a group row that must NOT
 * count), 4 members, 5 completed team board.
 */
class CorkboardTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-corkboard');

        $now = Carbon::now();
        $board = fn (int $id, string $visibility, string $status = 'active') => [
            'id' => $id, 'owner_id' => 3, 'title' => "Board $id", 'slug' => "board-$id", 'color' => '#4d698e',
            'visibility' => $visibility, 'status' => $status, 'is_template' => false, 'card_count' => 1, 'done_count' => 0,
            'version' => 1, 'last_activity_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'ana', 'email' => 'ana@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'marc', 'email' => 'marc@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'vol', 'email' => 'vol@machine.local', 'is_email_confirmed' => 1],
                ['id' => 6, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
            ],
            Group::class => [
                ['id' => 10, 'name_singular' => 'Volunteer', 'name_plural' => 'Volunteers', 'is_hidden' => 0],
            ],
            'group_user' => [
                ['user_id' => 5, 'group_id' => 10],
                ['user_id' => 6, 'group_id' => Group::MODERATOR_ID],
            ],
            'corkboard_boards' => [$board(1, 'public'), $board(2, 'team'), $board(3, 'private'), $board(4, 'members'), $board(5, 'team', 'completed')],
            'corkboard_members' => [
                ['id' => 1, 'board_id' => 2, 'user_id' => 4, 'group_id' => null, 'role' => 'editor'],
                ['id' => 2, 'board_id' => 2, 'user_id' => null, 'group_id' => 10, 'role' => 'commenter'],
                ['id' => 3, 'board_id' => 3, 'user_id' => 4, 'group_id' => null, 'role' => 'editor'],
                ['id' => 4, 'board_id' => 3, 'user_id' => null, 'group_id' => 10, 'role' => 'editor'],
                ['id' => 5, 'board_id' => 5, 'user_id' => 4, 'group_id' => null, 'role' => 'editor'],
            ],
            'corkboard_columns' => array_merge(...array_map(fn ($b) => [
                ['id' => $b * 10 + 1, 'board_id' => $b, 'title' => 'To do', 'position' => 1024, 'is_done' => false],
                ['id' => $b * 10 + 2, 'board_id' => $b, 'title' => 'Done', 'position' => 2048, 'is_done' => true],
            ], [1, 2, 3, 4, 5])),
            'corkboard_cards' => array_map(fn ($b) => [
                'id' => $b * 100, 'board_id' => $b, 'column_id' => $b * 10 + 1, 'position' => 1024, 'title' => "SECRET-CARD-$b",
                'priority' => 'none', 'user_id' => 3, 'comment_count' => 0, 'checklist_total' => 0, 'checklist_done' => 0, 'due_all_day' => false,
                'created_at' => $now->copy()->subDay(), 'updated_at' => $now,
            ], [1, 2, 3, 4, 5]),
        ]);
    }

    /**
     * Any API request; $path starts after /api and may carry a query string.
     *
     * @param array<string, mixed>|null $body
     * @return array{int, array<string, mixed>|null, string}
     */
    private function api(string $method, string $path, ?int $as, ?array $body = null): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        if ($method !== 'GET') {
            $options['json'] = $body ?? [];
        }
        parse_str($query, $params);
        // A guest's write carries no session token: let it through to what decides (the endpoint).
        $response = $this->send($this->request($method, '/api'.$route, $options)->withQueryParams($params)->withAttribute('bypassCsrfToken', true));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /**
     * Corkboard's own actions (clone, pin, the version poll, widgets, the feed), under /api/corkboard.
     *
     * @param array<string, mixed> $body
     * @return array{int, array<string, mixed>|null, string}
     */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        return $this->api($method, '/corkboard'.$path, $as, $body);
    }

    /**
     * A JSON:API write to corkboard-<type> (a create has no id). Answers with the status and the
     * write's `view`, what the page redraws from.
     *
     * @param array<string, mixed> $attributes
     * @return array{int, array<string, mixed>|null, string}
     */
    private function write(string $method, string $type, ?int $id, ?int $as, array $attributes = []): array
    {
        $data = ['type' => "corkboard-$type", 'attributes' => $attributes] + ($id ? ['id' => (string) $id] : []);
        [$status, $json, $raw] = $this->api($method, "/corkboard-$type".($id ? "/$id" : ''), $as, $method === 'DELETE' ? null : ['data' => $data]);

        return [$status, $json['data']['attributes']['view'] ?? null, $raw];
    }

    /**
     * A JSON:API read of corkboard-<type>; $rest is "/{id}" or "?filter[…]=…".
     *
     * @return array{int, array<string, mixed>|null, string}
     */
    private function read(string $type, string $rest, ?int $as): array
    {
        return $this->api('GET', "/corkboard-$type$rest", $as);
    }

    /**
     * The ids a list shows.
     *
     * @return list<int>
     */
    private function ids(string $type, string $filter, ?int $as): array
    {
        [$status, $json, $raw] = $this->read($type, $filter, $as);
        $this->assertSame(200, $status, $raw);
        $ids = array_map(fn ($item) => (int) $item['id'], $json['data']);
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function visibleBoards(?int $as): array
    {
        return $this->ids('boards', '?filter[tab]=all', $as);
    }

    #[Test]
    public function each_visibility_shows_to_the_right_people(): void
    {
        $this->assertSame([1], $this->visibleBoards(null));
        $this->assertSame([1, 4], $this->visibleBoards(2));
        $this->assertSame([1, 2, 3, 4], $this->visibleBoards(3));
        $this->assertSame([1, 2, 3, 4], $this->visibleBoards(4));
        // Volunteers are on board 2 as a group; board 3 is private, where a group row counts for nothing.
        $this->assertSame([1, 2, 4], $this->visibleBoards(5));
        [$status] = $this->read('boards', '/3', 5);
        $this->assertSame(404, $status);
        $this->assertSame([1, 2, 3, 4], $this->visibleBoards(6));
    }

    #[Test]
    public function nothing_of_a_hidden_board_leaks_anywhere(): void
    {
        foreach ([null, 2] as $viewer) {
            $paths = [
                '/corkboard-boards?filter[tab]=all', '/corkboard/my-cards', '/corkboard/due-soon', '/corkboard-cards/200', '/corkboard-cards/300',
                '/corkboard-boards/2', '/corkboard/boards/2/version', '/corkboard/boards/2/activity', '/corkboard/progress/2', '/corkboard-comments?filter[card]=200',
                '/corkboard-cards', '/corkboard-cards?filter[board]=2', '/corkboard/boards/2/cards?column=21', '/corkboard/cards/200/activity',
            ];
            foreach ($paths as $path) {
                [, , $raw] = $this->api('GET', $path, $viewer);
                $this->assertStringNotContainsString('SECRET-CARD-2', $raw, $path);
                $this->assertStringNotContainsString('SECRET-CARD-3', $raw, $path);
            }
        }
    }

    #[Test]
    public function roles_decide_what_a_person_can_change(): void
    {
        // A viewer of a public board can't add cards.
        [$status] = $this->write('POST', 'cards', null, 2, ['boardId' => 1, 'columnId' => 11, 'title' => 'Nope']);
        $this->assertSame(403, $status);
        // A commenter (through Volunteers) can comment but not edit.
        [$status] = $this->write('POST', 'comments', null, 5, ['cardId' => 200, 'content' => 'I can help Thursday']);
        $this->assertSame(201, $status);
        [$status] = $this->write('PATCH', 'cards', 200, 5, ['title' => 'Changed']);
        $this->assertSame(403, $status);
        // An editor can.
        [$status, $json] = $this->write('PATCH', 'cards', 200, 4, ['title' => 'Book the venue']);
        $this->assertSame(200, $status);
        $this->assertSame('Book the venue', $json['card']['title']);
        // A completed board is read-only, even for its editors.
        [$status] = $this->write('PATCH', 'cards', 500, 4, ['title' => 'Too late']);
        $this->assertSame(403, $status);
        // Its owner (a manager) can reopen it.
        [$status, $json] = $this->write('PATCH', 'boards', 5, 3, ['status' => 'active']);
        $this->assertSame(200, $status);
        $this->assertSame('active', $json['board']['status']);
    }

    #[Test]
    public function a_board_from_a_template_gets_its_columns_and_labels(): void
    {
        [$status, $json, $raw] = $this->write('POST', 'boards', null, 2, ['title' => 'Spring Meetup 2027', 'template' => 'event']);
        $this->assertSame(201, $status, $raw);
        // Five columns (their names are translated; the test forum has no language pack).
        $this->assertCount(5, $json['columns']);
        $this->assertSame([false, false, false, false, true], array_column($json['columns'], 'isDone'));
        $this->assertTrue(end($json['columns'])['isDone']);
        $this->assertCount(6, $json['labels']);
        $this->assertSame('team', $json['board']['visibility']);
        $this->assertSame('manager', $json['role']);

        // A member can't make a public board.
        [$status] = $this->write('POST', 'boards', null, 2, ['title' => 'Open plan', 'visibility' => 'public']);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function moving_into_done_finishes_a_card_and_back_out_reopens_it(): void
    {
        [$status, $json] = $this->write('PATCH', 'cards', 200, 4, ['columnId' => 22, 'afterId' => null]);
        $this->assertSame(200, $status);
        $this->assertNotNull($json['card']['completedAt']);
        $this->assertSame(1, $json['doneCount']);

        [, $json] = $this->write('PATCH', 'cards', 200, 4, ['completed' => false]);
        $this->assertNull($json['card']['completedAt']);
        $this->assertSame(21, $json['card']['columnId']);

        [, $json] = $this->write('PATCH', 'cards', 200, 4, ['completed' => true]);
        $this->assertSame(22, $json['card']['columnId']);
    }

    #[Test]
    public function positions_keep_the_order_people_dropped_cards_in(): void
    {
        $ids = [];
        foreach (['A', 'B', 'C'] as $title) {
            [, $json] = $this->write('POST', 'cards', null, 4, ['boardId' => 2, 'columnId' => 21, 'title' => $title]);
            $ids[$title] = $json['card']['id'];
        }
        // C to the top, then B between C and A.
        $this->write('PATCH', 'cards', $ids['C'], 4, ['columnId' => 21, 'afterId' => null]);
        $this->write('PATCH', 'cards', $ids['B'], 4, ['columnId' => 21, 'afterId' => $ids['C']]);
        // Squeeze many cards into one gap: the column is renumbered when it closes.
        for ($i = 0; $i < 12; $i++) {
            [, $json] = $this->write('POST', 'cards', null, 4, ['boardId' => 2, 'columnId' => 21, 'title' => "x$i", 'afterId' => $ids['C']]);
        }
        $json = $this->read('boards', '/2', 4)[1]['data']['attributes']['view'];
        $titles = array_column(array_filter($json['cards'], fn ($c) => $c['columnId'] === 21), 'title');
        $this->assertSame('C', $titles[0]);
        $this->assertSame('x11', $titles[1]);
        $this->assertSame(['B', 'SECRET-CARD-2', 'A'], array_slice($titles, -3));
    }

    #[Test]
    public function assigning_tells_the_assignee_and_only_people_who_can_see_the_board(): void
    {
        // normal (2) can't see team board 2: they're not assigned.
        [$status, $json] = $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4, 2]]);
        $this->assertSame(200, $status);
        $this->assertSame([4], $json['card']['assigneeIds']);

        $alerts = $this->database()->table('notifications')->where('type', 'corkboardAssigned')->pluck('user_id')->all();
        $this->assertSame([4], array_map('intval', $alerts));

        // Assigning a second person keeps the first one's alert.
        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4, 5]]);
        $this->assertSame(2, $this->database()->table('notifications')->where('type', 'corkboardAssigned')->where('is_deleted', false)->count());

        // Both alerts list fine (the subject has an API resource).
        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 4]));
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_mention_in_a_comment_is_an_alert(): void
    {
        $xmlUser = '@"marc"#4';
        $this->write('POST', 'comments', null, 3, ['cardId' => 200, 'content' => "Can you take this, $xmlUser?"]);
        $this->assertSame(1, $this->database()->table('corkboard_comments')->count());
        $this->assertSame(1, (int) $this->database()->table('corkboard_cards')->where('id', 200)->value('comment_count'));
    }

    #[Test]
    public function a_board_costs_the_same_queries_for_one_card_or_three_hundred(): void
    {
        $count = function (int $as): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            [$status] = $this->read('boards', '/1', $as);
            $this->assertSame(200, $status);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        $this->read('boards', '/1', 3);
        $this->read('boards', '/1', 4);
        $one = [$count(3), $count(4)];

        $now = Carbon::now();
        $cards = $labels = $people = [];
        $this->database()->table('corkboard_labels')->insert([['id' => 1, 'board_id' => 1, 'name' => 'Food', 'color' => '#c2410c', 'position' => 1]]);
        for ($i = 1000; $i < 1300; $i++) {
            $cards[] = ['id' => $i, 'board_id' => 1, 'column_id' => $i % 2 ? 11 : 12, 'position' => $i, 'title' => "Card $i", 'priority' => 'normal', 'user_id' => 3, 'comment_count' => 2, 'checklist_total' => 3, 'checklist_done' => 1, 'due_all_day' => false, 'due_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            $labels[] = ['card_id' => $i, 'label_id' => 1];
            $people[] = ['card_id' => $i, 'user_id' => 3 + $i % 3, 'created_at' => $now];
        }
        foreach (array_chunk($cards, 100) as $chunk) {
            $this->database()->table('corkboard_cards')->insert($chunk);
        }
        $this->database()->table('corkboard_card_label')->insert($labels);
        $this->database()->table('corkboard_assignees')->insert($people);
        $this->database()->table('corkboard_boards')->where('id', 1)->update(['card_count' => 301]);

        $many = [$count(3), $count(4)];
        $this->assertSame($one, $many);
        $this->assertLessThanOrEqual(16, $many[1]);
    }

    #[Test]
    public function card_notifications_cost_the_same_queries_for_one_or_six(): void
    {
        $notify = function (array $cardIds): int {
            $db = $this->database();
            $db->table('notifications')->delete();
            foreach ($cardIds as $id) {
                $db->table('notifications')->insert(['user_id' => 4, 'from_user_id' => 3, 'type' => 'corkboardAssigned', 'subject_id' => $id, 'data' => '{"a":'.$id.'}', 'created_at' => Carbon::now()]);
            }
            $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 4]));
            $db->flushQueryLog();
            $db->enableQueryLog();
            $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 4]));
            $n = count($db->getQueryLog());
            $db->disableQueryLog();
            $this->assertSame(count($cardIds), count(json_decode((string) $response->getBody(), true)['data']));

            return $n;
        };

        $now = Carbon::now();
        foreach ([601, 602, 603, 604, 605] as $id) {
            $this->database()->table('corkboard_cards')->insert(['id' => $id, 'board_id' => 2, 'column_id' => 21, 'position' => $id, 'title' => "Card $id", 'priority' => 'none', 'user_id' => 3, 'comment_count' => 0, 'checklist_total' => 0, 'checklist_done' => 0, 'due_all_day' => false, 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->assertSame($notify([200]), $notify([200, 601, 602, 603, 604, 605]));
    }

    #[Test]
    public function a_change_is_pushed_once_to_the_team_from_the_queue(): void
    {
        $this->app();
        $fake = new class extends \Pusher\Pusher {
            /** @var list<array{array<string>, string, mixed}> */
            public array $sent = [];

            public function __construct()
            {
            }

            public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
            {
                $this->sent[] = [(array) $channels, $event, $data];

                return new \stdClass();
            }
        };
        $this->app()->getContainer()->instance(\Pusher\Pusher::class, $fake);
        // What Extend\Conditional does when flarum/realtime is enabled.
        $this->app()->getContainer()->make(\ErnestDefoe\Corkboard\Extensions::class)->switchOn('flarum-realtime');

        $this->write('PATCH', 'cards', 200, 3, ['columnId' => 22, 'afterId' => null]);
        $boards = array_values(array_filter($fake->sent, fn ($e) => $e[2]['t'] === 'board'));
        $this->assertNotEmpty($boards);
        [$channels, $event, $data] = end($boards);
        $this->assertSame('corkboard', $event);
        $this->assertSame(2, $data['b']);
        $this->assertSame((int) $this->database()->table('corkboard_boards')->where('id', 2)->value('version'), $data['v'], 'the last event has the committed version');
        sort($channels);
        // Ana owns it, Marc is on it by name, Vol through Volunteers. Only ids travel, never a title.
        $this->assertSame(['private-user=3', 'private-user=4', 'private-user=5'], $channels);
        $this->assertArrayNotHasKey('title', $data);
    }

    #[Test]
    public function a_second_start_discussion_while_one_is_running_is_told_to_wait(): void
    {
        $this->english();
        $lock = $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->lock('corkboard.discussion.200', 30);
        $this->assertTrue($lock->get());
        [$status, $json] = $this->call('POST', '/cards/200/discussion', 3);
        $lock->release();
        $this->assertSame(422, $status);
        $this->assertStringContainsString('Try again', json_encode($json) ?: '');
        $this->assertNull($this->database()->table('corkboard_cards')->where('id', 200)->value('discussion_id'));
    }

    #[Test]
    public function the_calendar_feed_lists_the_owners_due_cards(): void
    {
        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4], 'dueAt' => '2030-04-17T15:00:00Z']);
        [, $json] = $this->call('GET', '/feed-token', 4);
        $this->assertNull($json['url'], 'a GET makes no link');
        [, $json] = $this->call('POST', '/feed-token', 4);
        $token = substr($json['url'], -40);
        // Kept in Corkboard's own table, never in a column on users.
        $this->assertFalse($this->database()->getSchemaBuilder()->hasColumn('users', 'corkboard_feed_token'));
        $this->assertSame($token, $this->database()->table('corkboard_feed_tokens')->where('user_id', 4)->value('token'));
        [, $json] = $this->call('GET', '/feed-token', 4);
        $this->assertSame($token, substr($json['url'], -40), 'the link reads back');
        [$status, , $raw] = $this->call('GET', '/feed/'.$token, null);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('SUMMARY:SECRET-CARD-2', $raw);
        $this->assertStringContainsString('DTSTART:20300417T150000Z', $raw);

        // A new link retires the old one.
        $this->call('POST', '/feed-token', 4);
        [$status] = $this->call('GET', '/feed/'.$token, null);
        $this->assertSame(404, $status);
        $this->assertSame(1, $this->database()->table('corkboard_feed_tokens')->where('user_id', 4)->count());
    }

    #[Test]
    public function an_upgrade_from_1_0_moves_feed_links_off_the_users_table(): void
    {
        $this->app();
        $schema = $this->database()->getSchemaBuilder();
        // What Corkboard 1.0.0 left behind: a column on users holding marc's link.
        $schema->table('users', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->string('corkboard_feed_token', 40)->nullable()->unique());
        $this->database()->table('users')->where('id', 4)->update(['corkboard_feed_token' => str_repeat('a', 40)]);

        $migration = require __DIR__.'/../../../migrations/2026_11_19_000005_upgrade_from_corkboard_1_0.php';
        $migration['up']($schema);

        $this->assertFalse($schema->hasColumn('users', 'corkboard_feed_token'));
        $this->assertSame(str_repeat('a', 40), $this->database()->table('corkboard_feed_tokens')->where('user_id', 4)->value('token'));
        [$status] = $this->call('GET', '/feed/'.str_repeat('a', 40), null);
        $this->assertSame(200, $status, 'the old link keeps working');

        // MySQL commits around schema changes, so this test's rows outlive its transaction.
        $this->database()->table('corkboard_feed_tokens')->delete();
    }

    #[Test]
    public function erasing_a_member_takes_them_off_teams_and_retires_their_feed(): void
    {
        $this->call('POST', '/feed-token', 4);
        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4]]);
        $c = $this->app()->getContainer();
        $type = new \ErnestDefoe\Corkboard\Gdpr\CorkboardData(
            User::query()->findOrFail(4), null, $c->make(\Illuminate\Contracts\Filesystem\Factory::class),
            $c->make(\Flarum\Settings\SettingsRepositoryInterface::class), $c->make(\Flarum\Http\UrlGenerator::class),
            $c->make(\Flarum\Locale\TranslatorInterface::class),
        );
        $this->assertStringContainsString('SECRET-CARD', json_encode($type->export()) ?: '');
        $type->anonymize();
        $this->assertSame(0, $this->database()->table('corkboard_members')->where('user_id', 4)->count());
        $this->assertSame(0, $this->database()->table('corkboard_assignees')->where('user_id', 4)->count());
        $this->assertSame(0, $this->database()->table('corkboard_feed_tokens')->where('user_id', 4)->count());
    }

    #[Test]
    public function the_forum_payload_says_what_the_viewer_may_do(): void
    {
        $guest = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true)['data']['attributes'];
        $this->assertFalse($guest['corkboardCanCreate']);
        $this->assertTrue($guest['corkboardCanView']);
        $this->assertSame('team', $guest['corkboardDefaultVisibility']);
        $this->assertSame(['public', 'members', 'team', 'private'], $guest['corkboardVisibilities']);

        $member = json_decode((string) $this->send($this->request('GET', '/api', ['authenticatedAs' => 3]))->getBody(), true)['data']['attributes'];
        $this->assertTrue($member['corkboardCanCreate']);
        $this->assertFalse($member['corkboardCanModerate']);
    }

    #[Test]
    public function private_boards_drop_group_rows_and_refuse_new_ones(): void
    {
        [$status] = $this->write('POST', 'members', null, 3, ['boardId' => 3, 'groupId' => 10, 'role' => 'editor']);
        $this->assertSame(422, $status);
        $this->write('PATCH', 'boards', 2, 3, ['visibility' => 'private']);
        $this->assertSame(0, $this->database()->table('corkboard_members')->where('board_id', 2)->whereNotNull('group_id')->count());
    }

    #[Test]
    public function a_column_with_cards_is_not_deleted(): void
    {
        [$status] = $this->write('DELETE', 'columns', 21, 3);
        $this->assertSame(422, $status);
        [$status, $json] = $this->write('POST', 'columns', null, 3, ['boardId' => 2, 'title' => 'Waiting', 'afterId' => 21]);
        $this->assertSame(201, $status);
        $this->assertSame(['To do', 'Waiting', 'Done'], array_column($json['columns'], 'title'));
    }

    #[Test]
    public function the_discussion_chip_shows_only_cards_the_viewer_may_see(): void
    {
        $this->database()->table('discussions')->insert(['id' => 7, 'title' => 'Venue ideas', 'slug' => 'venue-ideas', 'comment_count' => 1, 'participant_count' => 1, 'created_at' => Carbon::now(), 'user_id' => 3, 'is_private' => false]);
        // Before any card links a discussion, the field isn't there at all (no query).
        $response = $this->send($this->request('GET', '/api/discussions/7', ['authenticatedAs' => 2]));
        $this->assertArrayNotHasKey('corkboardCards', json_decode((string) $response->getBody(), true)['data']['attributes']);

        $this->write('PATCH', 'cards', 100, 3, ['discussionId' => 7]);
        $this->write('PATCH', 'cards', 200, 3, ['discussionId' => 7]);

        $chips = fn (?int $as) => array_column(json_decode((string) $this->send($this->request('GET', '/api/discussions', $as ? ['authenticatedAs' => $as] : []))->getBody(), true)['data'][0]['attributes']['corkboardCards'] ?? [], 'board');
        // Board 1 is public; board 2 is a team board the normal user isn't on.
        $this->assertSame(['Board 1'], $chips(null));
        $this->assertSame(['Board 1'], $chips(2));
        $this->assertSame(['Board 1', 'Board 2'], $chips(4));
    }

    #[Test]
    public function due_soon_and_overdue_are_sent_once_to_the_assignees(): void
    {
        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4], 'dueAt' => Carbon::now()->addHours(5)->toIso8601String()]);
        $this->write('PATCH', 'cards', 100, 3, ['dueAt' => Carbon::now()->subHours(5)->toIso8601String()]);
        $this->database()->table('notifications')->delete();

        $run = function () {
            $command = $this->app()->getContainer()->make(\ErnestDefoe\Corkboard\Console\DueCommand::class);
            $command->setLaravel($this->app()->getContainer());
            $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());
        };
        $run();
        $run();
        $rows = $this->database()->table('notifications')->orderBy('type')->get(['type', 'user_id'])->map(fn ($r) => $r->type.':'.$r->user_id)->all();
        // Card 200 is Marc's (assigned), due in 5 hours; card 100 has nobody on it, so its owner Ana hears it's overdue.
        $this->assertSame(['corkboardDueSoon:4', 'corkboardOverdue:3'], $rows);
    }

    #[Test]
    public function old_done_cards_are_archived_and_their_boards_recounted(): void
    {
        $db = $this->database();
        $db->table('corkboard_columns')->whereIn('id', [12, 22])->update(['auto_archive_days' => 7]);
        $old = Carbon::now()->subDays(10);
        $db->table('corkboard_cards')->whereIn('id', [100, 200])->update(['column_id' => $db->raw('column_id + 1'), 'completed_at' => $old]);
        $db->table('corkboard_cards')->insert(['id' => 701, 'board_id' => 2, 'column_id' => 22, 'position' => 9, 'title' => 'Fresh', 'priority' => 'none', 'user_id' => 3, 'comment_count' => 0, 'checklist_total' => 0, 'checklist_done' => 0, 'due_all_day' => false, 'completed_at' => Carbon::now(), 'created_at' => $old, 'updated_at' => $old]);

        $command = $this->app()->getContainer()->make(\ErnestDefoe\Corkboard\Console\PurgeCommand::class);
        $command->setLaravel($this->app()->getContainer());
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());

        $this->assertNotNull($db->table('corkboard_cards')->where('id', 100)->value('archived_at'));
        $this->assertNotNull($db->table('corkboard_cards')->where('id', 200)->value('archived_at'));
        $this->assertNull($db->table('corkboard_cards')->where('id', 701)->value('archived_at'), 'done today: stays');
        $board2 = $db->table('corkboard_boards')->where('id', 2)->first();
        $this->assertSame([1, 1, 2], [(int) $board2->card_count, (int) $board2->done_count, (int) $board2->version]);
        $this->assertSame(0, (int) $db->table('corkboard_boards')->where('id', 1)->value('card_count'));
        $this->assertSame(1, (int) $db->table('corkboard_boards')->where('id', 3)->value('version'), 'untouched boards keep their version');
    }

    #[Test]
    public function a_card_takes_at_most_twenty_people_and_only_people_who_can_see_the_board(): void
    {
        [$status] = $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => range(1000, 1020)]);
        $this->assertSame(422, $status);
        // Board 2 (team): marc by name, vol through Volunteers, mod by permission; the normal user can't see it.
        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [2, 4, 5, 6]]);
        $ids = $this->database()->table('corkboard_assignees')->where('card_id', 200)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([4, 5, 6], $ids);
        // Board 3 is private: the Volunteers group row doesn't count.
        $this->write('PATCH', 'cards', 300, 3, ['assigneeIds' => [4, 5]]);
        $this->assertSame([4], $this->database()->table('corkboard_assignees')->where('card_id', 300)->pluck('user_id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function updates_wait_in_the_outbox_and_go_as_one_email(): void
    {
        // Assigned is emailed by default; Overdue isn't (the defaults the scope gives).
        $this->database()->table('users')->where('id', 5)->update(['preferences' => json_encode(['notify_corkboardAssigned_email' => false])]);
        foreach ([200, 100 + 100] as $card) {
            $this->write('PATCH', 'cards', $card, 3, ['assigneeIds' => []]);
            $this->write('PATCH', 'cards', $card, 3, ['assigneeIds' => [4]]);
        }
        [, $json] = $this->write('POST', 'cards', null, 3, ['boardId' => 2, 'columnId' => 21, 'title' => 'Order the banners']);
        $this->write('PATCH', 'cards', $json['card']['id'], 3, ['assigneeIds' => [4]]);
        $this->assertSame(3, $this->database()->table('corkboard_outbox')->where('user_id', 4)->count());
        // Someone who switched the email off gets the alert and no email.
        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4, 5]]);
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 5)->count());
        $this->assertSame(0, $this->database()->table('corkboard_outbox')->where('user_id', 5)->count());

        $outbox = $this->app()->getContainer()->make(\ErnestDefoe\Corkboard\Mail\Outbox::class);
        // Still arriving (the newest is seconds old): it waits.
        $this->assertSame(0, $outbox->flush());
        $this->assertSame(1, $outbox->flush(true));
        $this->assertSame(0, $this->database()->table('corkboard_outbox')->count());
    }

    #[Test]
    public function a_projects_boards_are_listed_by_project(): void
    {
        $this->database()->table('corkboard_boards')->whereIn('id', [1, 2])->update(['project_id' => 9]);
        $ids = fn (?int $as) => $this->ids('boards', '?filter[tab]=all&filter[project]=9', $as);
        $this->assertSame([1], $ids(2));
        $this->assertEqualsCanonicalizing([1, 2], $ids(4));
    }

    #[Test]
    public function a_flood_of_comments_is_slowed_down(): void
    {
        $statuses = [];
        for ($i = 0; $i < 31; $i++) {
            [$statuses[]] = $this->write('POST', 'comments', null, 4, ['cardId' => 200, 'content' => "Note $i"]);
        }
        $this->assertSame(201, $statuses[28]);
        $this->assertSame(429, $statuses[30]);
        // Moderators aren't limited.
        [$status] = $this->write('POST', 'comments', null, 6, ['cardId' => 200, 'content' => 'Still here']);
        $this->assertSame(201, $status);
    }

    #[Test]
    public function nobody_reaches_another_boards_things_by_changing_an_id(): void
    {
        // Volunteers (user 5) can't see private board 3: its card, column and members are 404s.
        $this->assertSame(404, $this->write('PATCH', 'cards', 300, 5, ['title' => 'x'])[0]);
        $this->assertSame(404, $this->write('POST', 'comments', null, 5, ['cardId' => 300, 'content' => 'x'])[0]);
        $this->assertSame(404, $this->write('PATCH', 'columns', 31, 5, ['title' => 'x'])[0]);
        $this->assertSame(404, $this->write('PATCH', 'members', 3, 5, ['role' => 'manager'])[0]);
        // A viewer of public board 1 can't change its structure, cards or checklist.
        $this->assertSame(403, $this->write('DELETE', 'columns', 12, 2)[0]);
        $this->assertSame(403, $this->write('POST', 'checklist-items', null, 2, ['cardId' => 100, 'text' => 'x'])[0]);
        $this->assertSame(403, $this->write('POST', 'labels', null, 2, ['boardId' => 1, 'name' => 'x'])[0]);
        // An editor can't move a card into another board's column, or label it with another board's label.
        $this->assertSame(422, $this->write('PATCH', 'cards', 200, 4, ['columnId' => 11])[0]);
        $this->database()->table('corkboard_labels')->insert(['id' => 90, 'board_id' => 1, 'name' => 'Other', 'color' => '#1971c2', 'position' => 0]);
        [, $json] = $this->write('PATCH', 'cards', 200, 4, ['labelIds' => [90]]);
        $this->assertSame([], $json['card']['labelIds']);
        // Someone else's comment can't be edited, only deleted by a manager.
        [, $json] = $this->write('POST', 'comments', null, 4, ['cardId' => 200, 'content' => 'Mine']);
        $id = $json['comments'][0]['id'];
        $this->assertSame(403, $this->write('PATCH', 'comments', $id, 5, ['content' => 'Changed'])[0]);
        $this->assertSame(403, $this->write('DELETE', 'comments', $id, 5)[0]);
        $this->assertSame(204, $this->write('DELETE', 'comments', $id, 3)[0]);
    }

    #[Test]
    public function guests_can_write_nothing(): void
    {
        $db = $this->database();
        $db->table('corkboard_labels')->insert(['id' => 1, 'board_id' => 1, 'name' => 'Food', 'color' => '#c2410c', 'position' => 1]);
        $db->table('corkboard_members')->insert(['id' => 9, 'board_id' => 1, 'user_id' => 4, 'group_id' => null, 'role' => 'editor']);
        $db->table('corkboard_comments')->insert(['id' => 1, 'card_id' => 100, 'user_id' => 3, 'content' => '<t>Hi</t>', 'created_at' => Carbon::now()]);
        $db->table('corkboard_checklist')->insert(['id' => 1, 'card_id' => 100, 'text' => 'Item', 'is_done' => false, 'position' => 1024]);
        // Everything here is on public board 1, which guests may read.
        $creates = [
            'boards' => ['title' => 'Mine now'], 'columns' => ['boardId' => 1, 'title' => 'x'], 'labels' => ['boardId' => 1, 'name' => 'x'],
            'cards' => ['boardId' => 1, 'columnId' => 11, 'title' => 'x'], 'comments' => ['cardId' => 100, 'content' => 'x'],
            'checklist-items' => ['cardId' => 100, 'text' => 'x'], 'members' => ['boardId' => 1, 'userId' => 2, 'role' => 'editor'],
        ];
        $existing = ['boards' => 1, 'columns' => 11, 'labels' => 1, 'cards' => 100, 'comments' => 1, 'checklist-items' => 1, 'members' => 9];
        $changes = ['boards' => ['title' => 'x'], 'columns' => ['title' => 'x'], 'labels' => ['name' => 'x'], 'cards' => ['title' => 'x'], 'comments' => ['content' => 'x'], 'checklist-items' => ['text' => 'x'], 'members' => ['role' => 'manager']];
        foreach ($creates as $type => $attributes) {
            [$status, , $raw] = $this->write('POST', $type, null, null, $attributes);
            $this->assertSame(401, $status, "create $type: $raw");
            $this->assertSame(200, $this->read($type, '/'.$existing[$type], null)[0], "read $type");
            $this->assertSame(401, $this->write('PATCH', $type, $existing[$type], null, $changes[$type])[0], "update $type");
            $this->assertSame(401, $this->write('DELETE', $type, $existing[$type], null)[0], "delete $type");
        }
        $this->assertSame(1, $db->table('corkboard_boards')->where('id', 1)->where('title', 'Board 1')->count());
        $this->assertSame(1, $db->table('corkboard_comments')->count());
        // The non-resource writes too (a guest is never an editor, so cloning is a 403).
        $boards = $db->table('corkboard_boards')->count();
        $cards = $db->table('corkboard_cards')->count();
        foreach (['/boards/1/clone', '/boards/1/pin', '/cards/100/clone', '/cards/100/discussion', '/feed-token'] as $path) {
            $this->assertContains($this->call('POST', $path, null)[0], [401, 403], $path);
        }
        $this->assertSame([$boards, $cards, 0], [$db->table('corkboard_boards')->count(), $db->table('corkboard_cards')->count(), $db->table('corkboard_pins')->where('board_id', 1)->count()]);
    }

    #[Test]
    public function lists_and_reads_never_show_a_hidden_boards_things(): void
    {
        $db = $this->database();
        foreach ([100, 200, 300, 400] as $i => $card) {
            $db->table('corkboard_comments')->insert(['id' => $i + 1, 'card_id' => $card, 'user_id' => 3, 'content' => "<t>Note on $card</t>", 'created_at' => Carbon::now()]);
            $db->table('corkboard_checklist')->insert(['id' => $i + 1, 'card_id' => $card, 'text' => "Item on $card", 'is_done' => false, 'position' => 1024]);
            $db->table('corkboard_labels')->insert(['id' => $i + 1, 'board_id' => $card / 100, 'name' => 'L', 'color' => '#c2410c', 'position' => 1]);
        }

        // The normal user sees public board 1 and members board 4 only.
        $this->assertSame([11, 12, 41, 42], $this->ids('columns', '', 2));
        $this->assertSame([], $this->ids('columns', '?filter[board]=2', 2));
        $this->assertSame([100, 400], $this->ids('cards', '', 2));
        $this->assertSame([], $this->ids('cards', '?filter[board]=3', 2));
        $this->assertSame([1, 4], $this->ids('comments', '', 2));
        $this->assertSame([], $this->ids('comments', '?filter[card]=200', 2));
        // Guests: board 1 only.
        $this->assertSame([11, 12], $this->ids('columns', '', null));
        $this->assertSame([100], $this->ids('cards', '', null));
        $this->assertSame([1], $this->ids('comments', '', null));
        // Volunteers (5) are on team board 2 through their group, never on private board 3.
        $this->assertSame([11, 12, 21, 22, 41, 42], $this->ids('columns', '', 5));
        $this->assertSame([1, 2, 4], $this->ids('comments', '', 5));

        foreach (['boards' => 2, 'columns' => 21, 'labels' => 2, 'cards' => 200, 'comments' => 2, 'checklist-items' => 2, 'members' => 1] as $type => $id) {
            $this->assertSame(404, $this->read($type, "/$id", 2)[0], "$type $id");
        }
        foreach (['boards' => 3, 'columns' => 31, 'labels' => 3, 'cards' => 300, 'comments' => 3, 'checklist-items' => 3, 'members' => 3] as $type => $id) {
            $this->assertSame(404, $this->read($type, "/$id", 5)[0], "$type $id");
            $this->assertSame(200, $this->read($type, "/$id", 4)[0], "$type $id");
        }
        // A deleted board takes its columns, cards and comments with it.
        $db->table('corkboard_boards')->where('id', 1)->update(['deleted_at' => Carbon::now()]);
        $this->assertSame([41, 42], $this->ids('columns', '', 2));
        $this->assertSame([400], $this->ids('cards', '', 2));
        $this->assertSame([4], $this->ids('comments', '', 2));
        $this->assertSame(404, $this->read('cards', '/100', 2)[0]);
    }

    #[Test]
    public function the_boards_list_pages_and_pins(): void
    {
        $now = Carbon::now();
        $rows = [];
        for ($i = 10; $i < 45; $i++) {
            $rows[] = ['id' => $i, 'owner_id' => 4, 'title' => "Extra $i", 'slug' => "extra-$i", 'color' => '#4d698e', 'visibility' => 'public', 'status' => 'active', 'is_template' => false, 'card_count' => 0, 'done_count' => 0, 'version' => 1, 'last_activity_at' => $now->copy()->subMinutes($i), 'created_at' => $now, 'updated_at' => $now];
        }
        $this->database()->table('corkboard_boards')->insert($rows);
        $this->call('POST', '/boards/2/pin', 4, ['pinned' => true]);

        [, $first] = $this->read('boards', '?filter[tab]=mine', 4);
        $this->assertCount(30, $first['data']);
        // A tile carries what the browser also writes (declared once for both).
        $tile = collect($first['data'])->firstWhere('attributes.title', 'Extra 10')['attributes'];
        $this->assertSame(['public', '#4d698e', 'active', false], [$tile['visibility'], $tile['color'], $tile['status'], $tile['isTemplate']]);
        $this->assertSame(4, $tile['ownerId']);
        $this->assertArrayHasKey('next', $first['links']);
        $this->assertSame([2], array_column($first['meta']['pinned'], 'id'));
        $tile = $first['meta']['pinned'][0];
        $this->assertTrue($tile['pinned']);
        $this->assertSame('editor', $tile['role']);
        // The team's pictures come with the tile: the owner first, then the people on it by name.
        $this->assertSame(['ana', 'marc'], array_column($tile['people'], 'username'));

        [, $second] = $this->read('boards', '?filter[tab]=mine&page[offset]=30', 4);
        $this->assertArrayNotHasKey('next', $second['links'] ?? []);
        $this->assertSame([], $second['meta']['pinned']);
        // Mine: 35 extra boards of marc's own, and boards 2 and 3 (on the team); 4 and 1 aren't his.
        $this->assertCount(37, array_merge($first['data'], $second['data']));
        // A word in the title.
        $this->assertSame([12], $this->ids('boards', '?filter[tab]=all&filter[q]=Extra 12', 4));
    }

    /**
     * The test forum has no language pack, so nothing is translated: load Corkboard's English
     * (and anything else given) to see real sentences.
     *
     * @param array<string, array<string, string>> $more locale => key => text
     */
    private function english(array $more = []): \Flarum\Locale\TranslatorInterface
    {
        $translator = $this->app()->getContainer()->make(\Flarum\Locale\TranslatorInterface::class);
        $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
        $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
        $translator->addResource('yaml', __DIR__.'/../../../locale/en.yml', 'en');
        foreach ($more as $locale => $messages) {
            $translator->addResource('array', $messages, $locale);
        }

        return $translator;
    }

    /** How many queries a request (or anything) costs. */
    private function queries(callable $fn): int
    {
        $db = $this->database();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $fn();
        $n = count($db->getQueryLog());
        if (getenv('CK_DUMP')) {
            fwrite(STDERR, implode("\n", array_column($db->getQueryLog(), 'query'))."\n-----\n");
        }
        $db->disableQueryLog();

        return $n;
    }

    /**
     * Cards on board 2 (team: ana owns it, marc by name, vol through Volunteers).
     *
     * @param array<string, mixed> $extra
     */
    private function cards(int $from, int $count, array $extra = []): void
    {
        $now = Carbon::now();
        $rows = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $rows[] = $extra + ['id' => $i, 'board_id' => 2, 'column_id' => 21, 'position' => $i * 1024, 'title' => "Card $i", 'priority' => 'none', 'user_id' => 3, 'comment_count' => 0, 'checklist_total' => 0, 'checklist_done' => 0, 'due_all_day' => false, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            $this->database()->table('corkboard_cards')->insert($chunk);
        }
    }

    #[Test]
    public function my_cards_and_due_soon_are_filters_on_the_cards_list(): void
    {
        $soon = Carbon::now()->addHours(5);
        $this->cards(1000, 3, ['due_at' => $soon]);
        $this->database()->table('corkboard_assignees')->insert([['card_id' => 1000, 'user_id' => 4], ['card_id' => 1001, 'user_id' => 4], ['card_id' => 300, 'user_id' => 4], ['card_id' => 500, 'user_id' => 4]]);
        $this->database()->table('corkboard_cards')->where('id', 1001)->update(['completed_at' => Carbon::now()]);

        // Marc's open cards on open boards (board 5 is completed), with where they are and who's on them.
        [$status, $json, $raw] = $this->read('cards', '?filter[mine]=1', 4);
        $this->assertSame(200, $status, $raw);
        $this->assertSame(['1000', '300'], array_column($json['data'], 'id'), 'due first');
        $card = $json['data'][0]['attributes'];
        $this->assertSame(['Card 1000', 'Board 2', 'To do', '#4d698e'], [$card['title'], $card['boardTitle'], $card['columnTitle'], $card['boardColor']]);
        $this->assertSame(['marc'], array_column($card['people'], 'username'));
        $this->assertSame(2, $json['meta']['page']['total']);
        // Same due date: by priority, then newest first.
        $this->assertSame(['1001', '1000', '300'], array_column($this->read('cards', '?filter[mine]=1&filter[done]=1', 4)[1]['data'], 'id'));
        // Owned: ana started every card.
        $this->assertCount(7, $this->read('cards', '?filter[mine]=1&filter[owned]=1&filter[done]=1', 3)[1]['data']);
        $this->assertSame([], $this->read('cards', '?filter[mine]=1', null)[1]['data'], 'guests have no cards');

        // Due within three days on the boards marc is on; one board for anyone who may see it.
        $this->assertSame(['1000', '1002'], array_column($this->read('cards', '?filter[dueWithin]=3', 4)[1]['data'], 'id'));
        $this->assertSame([], $this->read('cards', '?filter[dueWithin]=3', 2)[1]['data'], 'the normal user is on no team');
        $this->assertSame([], $this->read('cards', '?filter[dueWithin]=3', null)[1]['data']);
        $this->database()->table('corkboard_cards')->where('id', 100)->update(['due_at' => $soon]);
        $this->assertSame(['100'], array_column($this->read('cards', '?filter[dueWithin]=3&filter[board]=1', null)[1]['data'], 'id'));
        $this->assertSame([], $this->read('cards', '?filter[dueWithin]=3&filter[board]=2', 2)[1]['data'], 'not a board they may see');
    }

    #[Test]
    public function a_card_list_costs_the_same_queries_for_one_card_or_a_hundred(): void
    {
        $list = fn () => $this->queries(fn () => $this->assertSame(200, $this->read('cards', '?filter[mine]=1&filter[done]=1&page[limit]=100', 4)[0]));
        $this->database()->table('corkboard_assignees')->insert(['card_id' => 200, 'user_id' => 4]);
        $list();
        $one = $list();
        $this->cards(1000, 99);
        $this->database()->table('corkboard_labels')->insert(['id' => 1, 'board_id' => 2, 'name' => 'L', 'color' => '#c2410c', 'position' => 1]);
        $people = $labels = [];
        for ($i = 1000; $i < 1099; $i++) {
            $people[] = ['card_id' => $i, 'user_id' => 4];
            $people[] = ['card_id' => $i, 'user_id' => [3, 5, 6][$i % 3]];
            $labels[] = ['card_id' => $i, 'label_id' => 1];
        }
        $this->database()->table('corkboard_assignees')->insertOrIgnore($people);
        $this->database()->table('corkboard_card_label')->insert($labels);
        [, $json] = $this->read('cards', '?filter[mine]=1&filter[done]=1&page[limit]=100', 4);
        $this->assertCount(100, $json['data']);
        $this->assertSame([1], $json['data'][1]['attributes']['labelIds']);
        $this->assertSame($one, $list());
    }

    #[Test]
    public function a_big_columns_next_cards_and_the_archived_ones_are_filters_too(): void
    {
        $this->cards(1000, 5);
        $this->database()->table('corkboard_cards')->where('id', 1004)->update(['archived_at' => Carbon::now()]);
        // After the card at position 1001 * 1024: the rest of the column, as the board draws them.
        [, $json] = $this->read('cards', '?filter[column]=21&filter[after]='.(1001 * 1024), 4);
        $this->assertSame(['1002', '1003'], array_column($json['data'], 'id'));
        $this->assertSame(21, $json['data'][0]['attributes']['columnId']);
        $this->assertSame(1002 * 1024, $json['data'][0]['attributes']['position']);
        $this->assertSame([], $json['data'][0]['attributes']['assigneeIds']);
        [, $json] = $this->read('cards', '?filter[board]=2&filter[archived]=1', 4);
        $this->assertSame(['1004'], array_column($json['data'], 'id'));
        $this->assertNotNull($json['data'][0]['attributes']['archivedAt']);
        $this->assertSame([], $this->read('cards', '?filter[column]=21', 2)[1]['data'], 'a board they can\'t see');
    }

    #[Test]
    public function board_and_card_history_is_a_read_only_resource(): void
    {
        $this->write('PATCH', 'cards', 200, 3, ['title' => 'Renamed']);
        $this->write('PATCH', 'boards', 2, 3, ['description' => 'Notes']);
        [$status, $json] = $this->read('activity', '?filter[board]=2', 4);
        $this->assertSame(200, $status);
        $this->assertSame(['board_updated', 'card_renamed'], array_column(array_column($json['data'], 'attributes'), 'type'));
        $this->assertSame('Renamed', $json['meta']['names']['cards'][200]);
        [, $json] = $this->read('activity', '?filter[card]=200', 4);
        $this->assertSame(['card_renamed'], array_column(array_column($json['data'], 'attributes'), 'type'));
        // Older than a line already shown.
        [, $json] = $this->read('activity', '?filter[board]=2&filter[before]='.$json['data'][0]['id'], 4);
        $this->assertSame([], $json['data']);
        $this->assertSame(404, $this->read('activity', '?filter[board]=2', 2)[0]);
        $this->assertSame(404, $this->read('activity', '?filter[card]=200', 2)[0]);
        $this->assertSame(404, $this->read('activity', '', 4)[0], 'a board or a card, always');
        // Read only.
        $this->assertSame(405, $this->api('POST', '/corkboard-activity', 3, ['data' => ['type' => 'corkboard-activity', 'attributes' => ['type' => 'x']]])[0]);
    }

    #[Test]
    public function templates_are_a_forum_attribute_and_a_boards_tab(): void
    {
        $forum = json_decode((string) $this->send($this->request('GET', '/api', ['authenticatedAs' => 2]))->getBody(), true)['data']['attributes'];
        $this->assertSame(['simple', 'event', 'content', 'raid'], array_column($forum['corkboardTemplates'], 'key'));
        $this->assertCount(5, $forum['corkboardTemplates'][1]['columns']);
        $guest = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true)['data']['attributes'];
        $this->assertArrayNotHasKey('corkboardTemplates', $guest);

        $this->database()->table('corkboard_boards')->whereIn('id', [1, 5])->update(['is_template' => true]);
        [, $json] = $this->read('boards', '?filter[tab]=templates', 2);
        // Board 5 is completed: not a template to start from.
        $this->assertSame(['1'], array_column($json['data'], 'id'));
        $this->assertSame(['To do', 'Done'], $json['data'][0]['attributes']['columns']);
    }

    #[Test]
    public function template_boards_are_only_listed_to_people_who_may_use_them(): void
    {
        // Before anyone's permissions are read in this request cycle.
        $this->database()->table('group_permission')->where('permission', 'corkboard.useTemplates')->delete();
        $this->database()->table('corkboard_boards')->where('id', 1)->update(['is_template' => true]);
        $this->assertSame([], $this->ids('boards', '?filter[tab]=templates', 2));
        $this->assertSame([1], $this->ids('boards', '?filter[tab]=templates', 1), 'admins may');
    }

    #[Test]
    public function cloning_a_board_copies_its_cards_in_a_fixed_number_of_queries_per_hundred(): void
    {
        $db = $this->database();
        $db->table('corkboard_labels')->insert([['id' => 1, 'board_id' => 2, 'name' => 'Food', 'color' => '#c2410c', 'position' => 1], ['id' => 2, 'board_id' => 2, 'name' => 'Venue', 'color' => '#1971c2', 'position' => 2]]);
        $db->table('corkboard_card_label')->insert([['card_id' => 200, 'label_id' => 2]]);
        $db->table('corkboard_checklist')->insert([['card_id' => 200, 'text' => 'Ask', 'is_done' => true, 'position' => 1024], ['card_id' => 200, 'text' => 'Pay', 'is_done' => false, 'position' => 2048]]);
        $clone = fn () => $this->queries(fn () => $this->assertSame(200, $this->call('POST', '/boards/2/clone', 3, ['cards' => true])[0]));
        $clone();
        $one = $clone();

        $this->cards(1000, 99);
        $pivot = $items = [];
        for ($i = 1000; $i < 1099; $i++) {
            $pivot[] = ['card_id' => $i, 'label_id' => 1 + $i % 2];
            $items[] = ['card_id' => $i, 'text' => "Item $i", 'is_done' => false, 'position' => 1024];
        }
        $db->table('corkboard_card_label')->insert($pivot);
        $db->table('corkboard_checklist')->insert($items);
        $this->assertSame($one, $clone(), 'a hundred cards cost what one does');

        // The copy of card 200: its label (the copy's own), its checklist unticked, the cloner's.
        $copy = $db->table('corkboard_cards')->where('title', 'SECRET-CARD-2')->orderByDesc('id')->first();
        $this->assertNotSame(2, (int) $copy->board_id);
        $label = $db->table('corkboard_card_label')->where('card_id', $copy->id)->value('label_id');
        $this->assertSame(['Venue', (int) $copy->board_id], [$db->table('corkboard_labels')->where('id', $label)->value('name'), (int) $db->table('corkboard_labels')->where('id', $label)->value('board_id')]);
        $this->assertSame(['Ask', 'Pay'], $db->table('corkboard_checklist')->where('card_id', $copy->id)->orderBy('position')->pluck('text')->all());
        $this->assertSame(0, $db->table('corkboard_checklist')->where('card_id', $copy->id)->where('is_done', true)->count());
        // Every copy kept its own label: Card 1000 had Food, Card 1001 Venue.
        foreach (['Card 1000' => 'Food', 'Card 1001' => 'Venue'] as $title => $name) {
            $id = $db->table('corkboard_cards')->where('board_id', $copy->board_id)->where('title', $title)->value('id');
            $this->assertSame($name, $db->table('corkboard_labels')->where('id', $db->table('corkboard_card_label')->where('card_id', $id)->value('label_id'))->value('name'));
        }
        $this->assertSame(100, $db->table('corkboard_cards')->where('board_id', $copy->board_id)->count());
    }

    #[Test]
    public function the_digest_is_written_in_each_recipients_language(): void
    {
        $c = $this->app()->getContainer();
        $translator = $this->english(['fr' => ['ernestdefoe-corkboard.email.line.corkboardAssigned' => 'FR: {card}']]);
        $mailer = new class extends \Flarum\Notification\NotificationMailer {
            /** @var array<int, list<string>> */
            public array $sent = [];

            public function __construct()
            {
            }

            public function send(\Flarum\Notification\MailableInterface&\Flarum\Notification\Blueprint\BlueprintInterface $blueprint, User $user): void
            {
                /** @var \ErnestDefoe\Corkboard\Notification\DigestBlueprint $blueprint */
                $this->sent[$user->id] = array_column($blueprint->lines, 'text');
            }
        };
        $c->instance(\Flarum\Notification\NotificationMailer::class, $mailer);
        $this->database()->table('users')->where('id', 4)->update(['preferences' => json_encode(['locale' => 'fr'])]);

        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4, 5]]);
        $c->make(\ErnestDefoe\Corkboard\Mail\Outbox::class)->flush(true);

        $this->assertSame(['FR: SECRET-CARD-2'], $mailer->sent[4]);
        $this->assertStringStartsWith('ana gave you', $mailer->sent[5][0], 'the next person is in the forum\'s language again');
        $this->assertSame('en', $translator->getLocale(), 'the locale is put back');
    }

    #[Test]
    public function the_hourly_reminders_cost_the_same_queries_for_one_card_or_twenty(): void
    {
        $run = function (): int {
            $command = $this->app()->getContainer()->make(\ErnestDefoe\Corkboard\Console\DueCommand::class);
            $command->setLaravel($this->app()->getContainer());

            return $this->queries(fn () => $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput()));
        };
        $soon = Carbon::now()->addHours(3);
        $this->cards(1000, 1, ['due_at' => $soon]);
        $this->database()->table('corkboard_assignees')->insert(['card_id' => 1000, 'user_id' => 4]);
        $run();
        $this->database()->table('notifications')->delete();
        $this->database()->table('corkboard_cards')->update(['due_notified_at' => null]);
        $one = $run();

        $this->database()->table('notifications')->delete();
        $this->database()->table('corkboard_cards')->update(['due_notified_at' => null]);
        $this->cards(2000, 19, ['due_at' => $soon]);
        $rows = [];
        for ($i = 2000; $i < 2019; $i++) {
            $rows[] = ['card_id' => $i, 'user_id' => 4 + $i % 2];
        }
        $this->database()->table('corkboard_assignees')->insert($rows);
        $twenty = $run();
        $this->assertSame(20, $this->database()->table('notifications')->where('type', 'corkboardDueSoon')->count());
        // Core's NotificationSyncer runs three queries per card (find, compare, insert); Corkboard's
        // own lookups (people, groups, team rows, assignees, outbox) don't grow with the cards.
        $this->assertLessThanOrEqual(3, ($twenty - $one) / 19, "$one queries for one card, $twenty for twenty");
    }

    #[Test]
    public function a_page_of_comments_works_out_roles_once(): void
    {
        $db = $this->database();
        $now = Carbon::now();
        $list = fn () => $this->queries(fn () => $this->assertSame(200, $this->read('comments', '', 4)[0]));
        $db->table('corkboard_comments')->insert(['card_id' => 200, 'user_id' => 3, 'content' => '<t>One</t>', 'created_at' => $now]);
        $list();
        $one = $list();
        // Comments on four boards marc can see.
        foreach ([100, 300, 400] as $card) {
            $db->table('corkboard_comments')->insert(['card_id' => $card, 'user_id' => 3, 'content' => '<t>Note</t>', 'created_at' => $now]);
        }
        $this->assertSame($one, $list());
        [, $json] = $this->read('comments', '', 4);
        $this->assertSame([false, false, false, false], array_column(array_column($json['data'], 'attributes'), 'canDelete'), 'marc manages none of them');
        [, $json] = $this->read('comments', '', 3);
        $this->assertSame([true, true, true, true], array_column(array_column($json['data'], 'attributes'), 'canDelete'), 'ana owns them all');
    }

    #[Test]
    public function a_refusal_arrives_translated_on_its_field(): void
    {
        $this->english();
        [$status, , $raw] = $this->write('PATCH', 'cards', 200, 3, ['title' => '   ']);
        $this->assertSame(422, $status);
        $error = json_decode($raw, true)['errors'][0];
        $this->assertSame('Give the card a title of up to 160 characters.', $error['detail']);
        $this->assertSame('/data/attributes/title', $error['source']['pointer']);
        $this->assertSame('validation_error', $error['code']);
    }

    #[Test]
    public function a_due_date_that_isnt_a_date_is_a_422(): void
    {
        [$status, , $raw] = $this->write('PATCH', 'cards', 200, 3, ['dueAt' => 'next tuesday-ish']);
        $this->assertSame(422, $status, $raw);
        $this->assertNull($this->database()->table('corkboard_cards')->where('id', 200)->value('due_at'));
        $this->assertSame(200, $this->write('PATCH', 'cards', 200, 3, ['dueAt' => '2030-04-17T15:00:00Z'])[0]);
    }

    #[Test]
    public function only_the_throttled_kinds_of_change_count_toward_the_flood_limit(): void
    {
        // Forty drags in the last minute: reorganising never blocks adding a card.
        $rows = array_fill(0, 40, ['board_id' => 2, 'card_id' => 200, 'user_id' => 4, 'type' => 'card_moved', 'created_at' => Carbon::now()]);
        $this->database()->table('corkboard_activity')->insert($rows);
        $this->assertSame(201, $this->write('POST', 'cards', null, 4, ['boardId' => 2, 'columnId' => 21, 'title' => 'Still allowed'])[0]);
        $rows = array_fill(0, 30, ['board_id' => 2, 'card_id' => 200, 'user_id' => 4, 'type' => 'comment_added', 'created_at' => Carbon::now()]);
        $this->database()->table('corkboard_activity')->insert($rows);
        $this->assertSame(429, $this->write('POST', 'cards', null, 4, ['boardId' => 2, 'columnId' => 21, 'title' => 'Too many'])[0]);
    }

    #[Test]
    public function hidden_groups_are_named_on_a_team_to_moderators_only(): void
    {
        $this->database()->table('groups')->where('id', 10)->update(['is_hidden' => true]);
        [, $json] = $this->read('boards', '/2', 4);
        $this->assertSame([null, null, true], [$json['data']['attributes']['view']['groups'][10]['name'], $json['data']['attributes']['view']['groups'][10]['count'], $json['data']['attributes']['view']['groups'][10]['hidden']]);
        $this->assertStringNotContainsString('Volunteers', $this->read('boards', '/2', 3)[2]);
        $this->write('PATCH', 'members', 2, 3, ['role' => 'editor']);
        $this->assertStringNotContainsString('Volunteers', $this->read('activity', '?filter[board]=2', 4)[2]);
        [, $json] = $this->read('boards', '/2', 6);
        $this->assertSame(['Volunteers', 1], [$json['data']['attributes']['view']['groups'][10]['name'], $json['data']['attributes']['view']['groups'][10]['count']]);
    }

    #[Test]
    public function a_board_change_with_a_status_is_all_or_nothing(): void
    {
        // Marc edits board 2 but can't complete it: nothing of the change is kept.
        [$status] = $this->write('PATCH', 'boards', 2, 4, ['status' => 'completed', 'title' => 'Renamed']);
        $this->assertSame(403, $status);
        $this->assertSame(['Board 2', 'active'], array_values((array) $this->database()->table('corkboard_boards')->where('id', 2)->first(['title', 'status'])));
        // A transfer to someone who can't see it fails after the title was set: rolled back.
        [$status] = $this->write('PATCH', 'boards', 2, 3, ['title' => 'Renamed', 'ownerId' => 2]);
        $this->assertSame(422, $status);
        $this->assertSame('Board 2', $this->database()->table('corkboard_boards')->where('id', 2)->value('title'));
        // Ana renames and completes it in one go; and reopens and renames the closed board 5.
        $this->assertSame(200, $this->write('PATCH', 'boards', 2, 3, ['title' => 'Done and dusted', 'status' => 'completed'])[0]);
        $this->assertSame(['Done and dusted', 'completed'], array_values((array) $this->database()->table('corkboard_boards')->where('id', 2)->first(['title', 'status'])));
        $this->assertSame(200, $this->write('PATCH', 'boards', 5, 3, ['status' => 'active', 'title' => 'Back on'])[0]);
        $this->assertSame(['Back on', 'active'], array_values((array) $this->database()->table('corkboard_boards')->where('id', 5)->first(['title', 'status'])));
    }

    #[Test]
    public function a_forum_from_before_the_split_migrations_keeps_its_tables(): void
    {
        $this->app();
        $schema = $this->database()->getSchemaBuilder();
        $this->database()->table('migrations')->insert([
            ['migration' => '2026_11_13_000000_create_corkboard_tables', 'extension' => 'ernestdefoe-corkboard'],
            ['migration' => '2026_11_13_000001_add_corkboard_feed_token_to_users', 'extension' => 'ernestdefoe-corkboard'],
        ]);
        $this->database()->table('corkboard_cards')->where('id', 200)->update(['title' => 'Kept']);
        // Every table already exists: each split migration leaves it (and its rows) alone.
        foreach (glob(__DIR__.'/../../../migrations/2026_11_12_*.php') ?: [] as $file) {
            (require $file)['up']($schema);
        }
        $this->assertSame('Kept', $this->database()->table('corkboard_cards')->where('id', 200)->value('title'));
        // The removed files' rows are forgotten, so a rollback never asks for a file that's gone:
        // every row Corkboard has in the migrations table names a file that exists.
        (require __DIR__.'/../../../migrations/2026_11_19_000005_upgrade_from_corkboard_1_0.php')['up']($schema);
        foreach ($this->database()->table('migrations')->where('extension', 'ernestdefoe-corkboard')->pluck('migration') as $name) {
            $this->assertFileExists(__DIR__."/../../../migrations/$name.php");
        }
    }

    #[Test]
    public function a_linked_discussion_shows_only_what_cores_api_shows_the_viewer(): void
    {
        $now = Carbon::now();
        $post = fn (int $id, int $discussion, int $number, string $words, array $more = []) => $more + [
            'id' => $id, 'discussion_id' => $discussion, 'number' => $number, 'type' => 'comment', 'user_id' => 3,
            'content' => "<t><p>$words</p></t>", 'created_at' => $now->copy()->addMinutes($id), 'is_private' => false,
        ];
        $this->prepareDatabase([
            'discussions' => [
                ['id' => 7, 'title' => 'Venue ideas', 'slug' => 'venue-ideas', 'comment_count' => 3, 'participant_count' => 1, 'last_post_number' => 3, 'created_at' => $now, 'user_id' => 3, 'first_post_id' => 70, 'is_private' => false],
                ['id' => 8, 'title' => 'SECRET-TITLE', 'slug' => 'secret', 'comment_count' => 2, 'participant_count' => 1, 'last_post_number' => 2, 'created_at' => $now, 'user_id' => 3, 'first_post_id' => 80, 'is_private' => true],
            ],
            'posts' => [
                $post(70, 7, 1, 'Opening'),
                $post(71, 7, 2, 'VISIBLE-REPLY'),
                $post(72, 7, 3, 'HIDDEN-REPLY', ['hidden_at' => $now, 'hidden_user_id' => 1]),
                $post(80, 8, 1, 'Opening'),
                $post(81, 8, 2, 'PRIVATE-REPLY'),
            ],
        ]);
        $this->app();
        $this->database()->table('corkboard_cards')->where('id', 200)->update(['discussion_id' => 7]);

        [$status, $json, $raw] = $this->read('cards', '/200', 4);
        $this->assertSame(200, $status, $raw);
        $discussion = $json['data']['attributes']['view']['discussion'];
        $this->assertSame('Venue ideas', $discussion['title']);
        $this->assertSame(['VISIBLE-REPLY'], array_column($discussion['lastReplies'], 'excerpt'), 'a hidden reply never comes along');
        $this->assertSame('ana', $discussion['lastReplies'][0]['user']['username']);
        $this->assertStringNotContainsString('HIDDEN-REPLY', $raw);

        // A private discussion the viewer can't see: no title, no replies, nothing.
        $this->database()->table('corkboard_cards')->where('id', 200)->update(['discussion_id' => 8]);
        [, $json, $raw] = $this->read('cards', '/200', 4);
        $this->assertNull($json['data']['attributes']['view']['discussion']);
        $this->assertStringNotContainsString('SECRET-TITLE', $raw);
        $this->assertStringNotContainsString('PRIVATE-REPLY', $raw);
    }

    #[Test]
    public function the_endpoints_ask_the_models_policies(): void
    {
        $this->app();
        $user = fn (int $id) => User::query()->findOrFail($id);
        $board = fn (int $id) => \ErnestDefoe\Corkboard\Board::query()->findOrFail($id);
        $card = fn (int $id) => \ErnestDefoe\Corkboard\Card::query()->findOrFail($id);
        // Marc edits board 2's cards but doesn't manage it; the normal user only sees board 1.
        $this->assertTrue($user(4)->can('addCards', $board(2)));
        $this->assertFalse($user(4)->can('manage', $board(2)));
        $this->assertFalse($user(4)->can('update', $board(2)));
        $this->assertTrue($user(3)->can('update', $board(2)));
        $this->assertFalse($user(2)->can('update', $card(100)));
        $this->assertTrue($user(2)->can('watch', $card(100)));
        $this->assertFalse($user(4)->can('update', $card(500)), 'a completed board is read-only');
        $this->assertTrue($user(4)->can('delete', \ErnestDefoe\Corkboard\Member::query()->findOrFail(1)), 'anyone may leave a team');
        $this->assertFalse($user(4)->can('delete', \ErnestDefoe\Corkboard\Member::query()->findOrFail(2)));
        $this->assertTrue($user(6)->can('delete', $board(2)), 'moderators may delete any board');
        $this->assertFalse($user(4)->can('delete', $board(2)), 'a team member may not');
        // Following a public board's card is a viewer's write; changing it isn't.
        $this->assertSame(200, $this->write('PATCH', 'cards', 100, 2, ['watching' => true])[0]);
        $this->assertSame(403, $this->write('PATCH', 'cards', 100, 2, ['title' => 'Mine now'])[0]);
        $this->assertSame(403, $this->write('POST', 'cards', null, 2, ['boardId' => 1, 'columnId' => 11, 'title' => 'x'])[0]);
        $this->assertSame(404, $this->write('POST', 'cards', null, 2, ['boardId' => 2, 'columnId' => 21, 'title' => 'x'])[0]);
        $this->assertSame(422, $this->write('POST', 'cards', null, 4, ['columnId' => 21, 'title' => 'x'])[0], 'no board: the create\'s own validation');
    }

    #[Test]
    public function a_team_holds_one_row_per_person_or_group(): void
    {
        $this->assertSame(201, $this->write('POST', 'members', null, 3, ['boardId' => 2, 'userId' => 5, 'role' => 'viewer'])[0]);
        $this->assertSame(201, $this->write('POST', 'members', null, 3, ['boardId' => 2, 'userId' => 5, 'role' => 'editor'])[0]);
        $this->assertSame(['editor'], $this->database()->table('corkboard_members')->where('board_id', 2)->where('user_id', 5)->pluck('role')->all());
        $this->write('POST', 'members', null, 3, ['boardId' => 2, 'groupId' => 10, 'role' => 'manager']);
        $this->assertSame(['manager'], $this->database()->table('corkboard_members')->where('board_id', 2)->where('group_id', 10)->pluck('role')->all());
        // The database keeps it so, even for a write that skips the check.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->database()->table('corkboard_members')->insert(['board_id' => 2, 'user_id' => 5, 'role' => 'viewer']);
    }

    #[Test]
    public function changing_your_own_role_answers_with_your_new_role(): void
    {
        $this->write('PATCH', 'members', 1, 3, ['role' => 'manager']);
        [$status, $json] = $this->write('PATCH', 'members', 1, 4, ['role' => 'viewer']);
        $this->assertSame(200, $status);
        $this->assertSame('viewer', $json['role']);
        $this->assertFalse($json['abilities']['manage']);
    }

    #[Test]
    public function a_board_copy_shifts_due_dates_by_a_sane_number_of_days(): void
    {
        $this->assertSame(422, $this->call('POST', '/boards/2/clone', 3, ['due' => 99999999])[0]);
        $this->assertSame(422, $this->call('POST', '/boards/2/clone', 3, ['due' => 'soon'])[0]);
        $this->assertSame(422, $this->call('POST', '/boards/2/clone', 3, ['due' => 1.5])[0]);
        $this->database()->table('corkboard_cards')->where('id', 200)->update(['due_at' => '2030-01-01 12:00:00']);
        [$status, $json] = $this->call('POST', '/boards/2/clone', 3, ['due' => -3650]);
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('2020-01-0', (string) $this->database()->table('corkboard_cards')->where('board_id', $json['id'])->value('due_at'));
    }

    #[Test]
    public function erasing_a_member_by_deletion_removes_their_comments(): void
    {
        $this->write('POST', 'comments', null, 4, ['cardId' => 200, 'content' => 'Mine']);
        $this->write('POST', 'comments', null, 3, ['cardId' => 200, 'content' => 'Ana\'s']);
        $c = $this->app()->getContainer();
        $type = new \ErnestDefoe\Corkboard\Gdpr\CorkboardData(
            User::query()->findOrFail(4), null, $c->make(\Illuminate\Contracts\Filesystem\Factory::class),
            $c->make(\Flarum\Settings\SettingsRepositoryInterface::class), $c->make(\Flarum\Http\UrlGenerator::class),
            $c->make(\Flarum\Locale\TranslatorInterface::class),
        );
        $type->delete();
        $this->assertSame(0, $this->database()->table('corkboard_comments')->where('content', 'like', '%Mine%')->count());
        $this->assertSame(1, $this->database()->table('corkboard_comments')->where('card_id', 200)->count(), 'everyone else\'s stay');
        $this->assertSame(1, (int) $this->database()->table('corkboard_cards')->where('id', 200)->value('comment_count'));
        $this->assertSame(0, $this->database()->table('corkboard_members')->where('user_id', 4)->count());
    }

    #[Test]
    public function the_moving_hint_never_refuses_a_fidgety_drag(): void
    {
        foreach (range(1, 8) as $i) {
            $this->assertSame(204, $this->call('POST', '/boards/2/moving', 4, ['cardId' => 200])[0]);
        }
        $this->assertSame(8, (int) $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->get('corkboard-moving.4'), 'counted: past five in ten seconds, none is sent');
    }

    #[Test]
    public function the_outbox_leaves_out_boards_a_recipient_can_no_longer_see(): void
    {
        $sent = [];
        $c = $this->app()->getContainer();
        $c->instance(\Flarum\Notification\NotificationMailer::class, new class($sent) extends \Flarum\Notification\NotificationMailer {
            public function __construct(private array &$sent)
            {
            }

            public function send(\Flarum\Notification\MailableInterface&\Flarum\Notification\Blueprint\BlueprintInterface $blueprint, User $user): void
            {
                $this->sent[] = $user->id;
            }
        });
        $this->write('PATCH', 'cards', 200, 3, ['assigneeIds' => [4, 5]]);
        $this->write('PATCH', 'cards', 100, 3, ['assigneeIds' => [4]]);
        // Marc is taken off board 2's team before the email goes; Vol is still on it through Volunteers.
        $this->database()->table('corkboard_members')->where('id', 1)->delete();
        $c->make(\ErnestDefoe\Corkboard\Mail\Outbox::class)->flush(true);
        sort($sent);
        $this->assertSame([4, 5], $sent, 'Marc still hears about board 1');
        $this->assertSame(0, $this->database()->table('corkboard_outbox')->count());
    }
}
