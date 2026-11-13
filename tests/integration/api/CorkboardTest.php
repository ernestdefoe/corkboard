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

    /** @return array{int, array<string, mixed>|null, string} */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        parse_str($query, $params);
        $response = $this->send($this->request($method, '/api/corkboard'.$route, $options)->withQueryParams($params));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /** @return list<int> */
    private function visibleBoards(?int $as): array
    {
        [$status, $json] = $this->call('GET', '/boards?tab=all', $as);
        $this->assertSame(200, $status);
        $ids = array_map(fn ($b) => $b['id'], $json['boards']);
        sort($ids);

        return $ids;
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
        [$status] = $this->call('GET', '/boards/3', 5);
        $this->assertSame(404, $status);
        $this->assertSame([1, 2, 3, 4], $this->visibleBoards(6));
    }

    #[Test]
    public function nothing_of_a_hidden_board_leaks_anywhere(): void
    {
        foreach ([null, 2] as $viewer) {
            foreach (['/boards?tab=all', '/my-cards', '/due-soon', '/cards/200', '/cards/300', '/boards/2', '/boards/2/version', '/boards/2/activity', '/progress/2', '/cards/200/comments'] as $path) {
                [, , $raw] = $this->call('GET', $path, $viewer);
                $this->assertStringNotContainsString('SECRET-CARD-2', $raw, $path);
                $this->assertStringNotContainsString('SECRET-CARD-3', $raw, $path);
            }
        }
    }

    #[Test]
    public function roles_decide_what_a_person_can_change(): void
    {
        // A viewer of a public board can't add cards.
        [$status] = $this->call('POST', '/boards/1/cards', 2, ['columnId' => 11, 'title' => 'Nope']);
        $this->assertSame(403, $status);
        // A commenter (through Volunteers) can comment but not edit.
        [$status] = $this->call('POST', '/cards/200/comments', 5, ['content' => 'I can help Thursday']);
        $this->assertSame(200, $status);
        [$status] = $this->call('PATCH', '/cards/200', 5, ['title' => 'Changed']);
        $this->assertSame(403, $status);
        // An editor can.
        [$status, $json] = $this->call('PATCH', '/cards/200', 4, ['title' => 'Book the venue']);
        $this->assertSame(200, $status);
        $this->assertSame('Book the venue', $json['card']['title']);
        // A completed board is read-only, even for its editors.
        [$status] = $this->call('PATCH', '/cards/500', 4, ['title' => 'Too late']);
        $this->assertSame(403, $status);
        // Its owner (a manager) can reopen it.
        [$status, $json] = $this->call('PATCH', '/boards/5', 3, ['status' => 'active']);
        $this->assertSame(200, $status);
        $this->assertSame('active', $json['board']['status']);
    }

    #[Test]
    public function a_board_from_a_template_gets_its_columns_and_labels(): void
    {
        [$status, $json] = $this->call('POST', '/boards', 2, ['title' => 'Spring Meetup 2027', 'template' => 'event']);
        $this->assertSame(200, $status, json_encode($json));
        // Five columns (their names are translated; the test forum has no language pack).
        $this->assertCount(5, $json['columns']);
        $this->assertSame([false, false, false, false, true], array_column($json['columns'], 'isDone'));
        $this->assertTrue(end($json['columns'])['isDone']);
        $this->assertCount(6, $json['labels']);
        $this->assertSame('team', $json['board']['visibility']);
        $this->assertSame('manager', $json['role']);

        // A member can't make a public board.
        [$status] = $this->call('POST', '/boards', 2, ['title' => 'Open plan', 'visibility' => 'public']);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function moving_into_done_finishes_a_card_and_back_out_reopens_it(): void
    {
        [$status, $json] = $this->call('PATCH', '/cards/200', 4, ['columnId' => 22, 'afterId' => null]);
        $this->assertSame(200, $status);
        $this->assertNotNull($json['card']['completedAt']);
        $this->assertSame(1, $json['doneCount']);

        [, $json] = $this->call('PATCH', '/cards/200', 4, ['completed' => false]);
        $this->assertNull($json['card']['completedAt']);
        $this->assertSame(21, $json['card']['columnId']);

        [, $json] = $this->call('PATCH', '/cards/200', 4, ['completed' => true]);
        $this->assertSame(22, $json['card']['columnId']);
    }

    #[Test]
    public function positions_keep_the_order_people_dropped_cards_in(): void
    {
        $ids = [];
        foreach (['A', 'B', 'C'] as $title) {
            [, $json] = $this->call('POST', '/boards/2/cards', 4, ['columnId' => 21, 'title' => $title]);
            $ids[$title] = $json['card']['id'];
        }
        // C to the top, then B between C and A.
        $this->call('PATCH', '/cards/'.$ids['C'], 4, ['columnId' => 21, 'afterId' => null]);
        $this->call('PATCH', '/cards/'.$ids['B'], 4, ['columnId' => 21, 'afterId' => $ids['C']]);
        // Squeeze many cards into one gap: the column is renumbered when it closes.
        for ($i = 0; $i < 12; $i++) {
            [, $json] = $this->call('POST', '/boards/2/cards', 4, ['columnId' => 21, 'title' => "x$i", 'afterId' => $ids['C']]);
        }
        [, $json] = $this->call('GET', '/boards/2', 4);
        $titles = array_column(array_filter($json['cards'], fn ($c) => $c['columnId'] === 21), 'title');
        $this->assertSame('C', $titles[0]);
        $this->assertSame('x11', $titles[1]);
        $this->assertSame(['B', 'SECRET-CARD-2', 'A'], array_slice($titles, -3));
    }

    #[Test]
    public function assigning_tells_the_assignee_and_only_people_who_can_see_the_board(): void
    {
        // normal (2) can't see team board 2: they're not assigned.
        [$status, $json] = $this->call('PATCH', '/cards/200', 3, ['assigneeIds' => [4, 2]]);
        $this->assertSame(200, $status);
        $this->assertSame([4], $json['card']['assigneeIds']);

        $alerts = $this->database()->table('notifications')->where('type', 'corkboardAssigned')->pluck('user_id')->all();
        $this->assertSame([4], array_map('intval', $alerts));

        // Assigning a second person keeps the first one's alert.
        $this->call('PATCH', '/cards/200', 3, ['assigneeIds' => [4, 5]]);
        $this->assertSame(2, $this->database()->table('notifications')->where('type', 'corkboardAssigned')->where('is_deleted', false)->count());

        // Both alerts list fine (the subject has an API resource).
        $response = $this->send($this->request('GET', '/api/notifications', ['authenticatedAs' => 4]));
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_mention_in_a_comment_is_an_alert(): void
    {
        $xmlUser = '@"marc"#4';
        $this->call('POST', '/cards/200/comments', 3, ['content' => "Can you take this, $xmlUser?"]);
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
            [$status] = $this->call('GET', '/boards/1', $as);
            $this->assertSame(200, $status);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        $this->call('GET', '/boards/1', 3);
        $this->call('GET', '/boards/1', 4);
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
    public function the_calendar_feed_lists_the_owners_due_cards(): void
    {
        $this->call('PATCH', '/cards/200', 3, ['assigneeIds' => [4], 'dueAt' => '2030-04-17T15:00:00Z']);
        [, $json] = $this->call('GET', '/feed-token', 4);
        $token = substr($json['url'], -40);
        [$status, , $raw] = $this->call('GET', '/feed/'.$token, null);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('SUMMARY:SECRET-CARD-2', $raw);
        $this->assertStringContainsString('DTSTART:20300417T150000Z', $raw);

        // A new link retires the old one.
        $this->call('POST', '/feed-token', 4);
        [$status] = $this->call('GET', '/feed/'.$token, null);
        $this->assertSame(404, $status);
    }

    #[Test]
    public function private_boards_drop_group_rows_and_refuse_new_ones(): void
    {
        [$status] = $this->call('POST', '/boards/3/members', 3, ['groupId' => 10, 'role' => 'editor']);
        $this->assertSame(422, $status);
        $this->call('PATCH', '/boards/2', 3, ['visibility' => 'private']);
        $this->assertSame(0, $this->database()->table('corkboard_members')->where('board_id', 2)->whereNotNull('group_id')->count());
    }

    #[Test]
    public function a_column_with_cards_is_not_deleted(): void
    {
        [$status] = $this->call('DELETE', '/columns/21', 3);
        $this->assertSame(422, $status);
        [$status, $json] = $this->call('POST', '/boards/2/columns', 3, ['title' => 'Waiting', 'afterId' => 21]);
        $this->assertSame(200, $status);
        $this->assertSame(['To do', 'Waiting', 'Done'], array_column($json['columns'], 'title'));
    }
}
