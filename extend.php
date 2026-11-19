<?php

/*
 * Corkboard: project boards inside the forum. Columns and cards with checklists, due dates,
 * labels, assignees and comments; public, members, team or private boards with per-person roles;
 * My cards and Due soon widgets for fof/widgets-core (and Bespoke through it) and Page Builder.
 *
 * Reads go through one visibility rule (Scope\BoardVisibility; cards follow their board) and
 * one role rule (Access). Boards, columns, labels, cards, comments, checklist items and team rows
 * are JSON:API resources; a board's read is one slim answer at a fixed query count (its `view`),
 * and a drag is one card update that answers with the board's new version.
 *
 * 🚨 String literals only below, never a class constant read at load time (Settings::KEY): it
 * autoloads during boot and fatals every request while a deploy is swapping composer's autoload
 * map. Constants inside closures are fine.
 */

use ErnestDefoe\Corkboard\Api;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Console;
use ErnestDefoe\Corkboard\Notification;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

$board = '{id:\d+}';

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The board, its settings and the lists are one chunk in here; undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/corkboard', 'corkboard.index')
        ->route('/corkboard/mine', 'corkboard.mine')
        ->route('/corkboard/{id:\d+[^/]*}', 'corkboard.board')
        ->route('/corkboard/{id:\d+[^/]*}/settings', 'corkboard.settings'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-corkboard', __DIR__.'/views'),

    // Boards, columns, labels, cards, comments, checklist items and team rows are JSON:API
    // resources (/api/corkboard-boards, …): every list and read goes through the one visibility
    // rule, every write through the services (src/Api/*Resource.php).
    new Extend\ApiResource(Api\BoardResource::class),
    new Extend\ApiResource(Api\ColumnResource::class),
    new Extend\ApiResource(Api\LabelResource::class),
    new Extend\ApiResource(Api\CardResource::class),
    new Extend\ApiResource(Api\CommentResource::class),
    new Extend\ApiResource(Api\ChecklistItemResource::class),
    new Extend\ApiResource(Api\MemberResource::class),

    // What isn't a create, read, update or delete: one handler each (src/Api/Handler). The card
    // lists (my cards, due soon, a column's next cards) are filters on corkboard-cards, template
    // boards a tab of corkboard-boards, the built-in templates a forum attribute.
    (new Extend\Routes('api'))
        ->post("/corkboard/boards/$board/clone", 'corkboard.boards.clone', Api\Handler\CloneBoard::class)
        ->get("/corkboard/boards/$board/version", 'corkboard.boards.version', Api\Handler\BoardVersion::class)
        ->post("/corkboard/boards/$board/pin", 'corkboard.boards.pin', Api\Handler\PinBoard::class)
        ->post("/corkboard/boards/$board/moving", 'corkboard.boards.moving', Api\Handler\Moving::class)
        ->get('/corkboard/people', 'corkboard.people', Api\Handler\SearchPeople::class)
        ->get('/corkboard/activity', 'corkboard.activity', Api\Handler\Activity::class)

        ->post('/corkboard/cards/{id:\d+}/clone', 'corkboard.cards.clone', Api\Handler\CloneCard::class)
        ->post('/corkboard/cards/{id:\d+}/discussion', 'corkboard.cards.discussion', Api\Handler\StartDiscussion::class)

        ->get('/corkboard/progress/{id:\d+}', 'corkboard.widgets.progress', Api\Handler\BoardProgress::class)

        ->get('/corkboard/feed-token', 'corkboard.feed.token', Api\Handler\FeedToken::class)
        ->post('/corkboard/feed-token', 'corkboard.feed.renewToken', Api\Handler\RenewFeedToken::class)
        ->get('/corkboard/feed/{token:[A-Za-z0-9]{40}}', 'corkboard.feed.ics', Api\Handler\CalendarFeed::class),

    // A Refusal (a plain-language "no" on one field) answers 422 with its sentence translated.
    (new Extend\Middleware('api'))
        ->add(\ErnestDefoe\Corkboard\Http\TranslateRefusals::class),
    (new Extend\ErrorHandling())
        ->type(\ErnestDefoe\Corkboard\Refusal::class, 'validation_error'),

    // Creating cards, comments, boards and team rows, cloning, and starting a discussion from a card:
    // refused once a member made 30 of those in the last minute (other changes don't count).
    (new Extend\ThrottleApi())
        ->set('corkboard', \ErnestDefoe\Corkboard\Throttle::class),

    (new Extend\ModelVisibility(Board::class))
        ->scope(\ErnestDefoe\Corkboard\Scope\BoardVisibility::class),
    (new Extend\ModelVisibility(Card::class))
        ->scope(\ErnestDefoe\Corkboard\Scope\CardVisibility::class),

    // Alerts are core's; their email goes through Corkboard's outbox (Mail\Outbox), one email per
    // person every few minutes. The digest's own switch (its unsubscribe link) stops all of it.
    (new Extend\Notification())
        ->type(Notification\AssignedBlueprint::class, ['alert'])
        ->type(Notification\DueSoonBlueprint::class, ['alert'])
        ->type(Notification\OverdueBlueprint::class, ['alert'])
        ->type(Notification\MentionedBlueprint::class, ['alert'])
        ->type(Notification\CommentedBlueprint::class, ['alert'])
        ->type(Notification\DoneBlueprint::class, ['alert'])
        ->type(Notification\TeamAddedBlueprint::class, ['alert'])
        ->type(Notification\CompletedBlueprint::class, ['alert'])
        ->type(Notification\DigestBlueprint::class, ['email']),

    // 🚨 Core registers a type's email switch only for a mailable blueprint, and these aren't (the
    // outbox sends their email): without these the switches don't exist and nothing is ever emailed.
    (new Extend\User())
        ->registerPreference('notify_corkboardAssigned_email', 'boolval', true)
        ->registerPreference('notify_corkboardDueSoon_email', 'boolval', true)
        ->registerPreference('notify_corkboardOverdue_email', 'boolval', false)
        ->registerPreference('notify_corkboardMentioned_email', 'boolval', true)
        ->registerPreference('notify_corkboardCommented_email', 'boolval', false)
        ->registerPreference('notify_corkboardDone_email', 'boolval', false)
        ->registerPreference('notify_corkboardTeamAdded_email', 'boolval', true)
        ->registerPreference('notify_corkboardCompleted_email', 'boolval', false),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\Settings())
        ->default('ernestdefoe-corkboard.visibilities', '["public","members","team","private"]')
        ->default('ernestdefoe-corkboard.default_visibility', 'team')
        ->default('ernestdefoe-corkboard.due_soon_hours', 24)
        ->default('ernestdefoe-corkboard.min_posts', 0)
        ->default('ernestdefoe-corkboard.nav', true)
        ->serializeToForum('corkboardNav', 'ernestdefoe-corkboard.nav', 'boolval'),

    (new Extend\Console())
        ->command(Console\DueCommand::class)
        ->schedule(Console\DueCommand::class, function (ScheduledEvent $event) {
            $event->hourly()->withoutOverlapping();
        })
        ->command(Console\MailCommand::class)
        ->schedule(Console\MailCommand::class, function (ScheduledEvent $event) {
            $event->everyFiveMinutes()->withoutOverlapping();
        })
        ->command(Console\PurgeCommand::class)
        ->schedule(Console\PurgeCommand::class, function (ScheduledEvent $event) {
            $event->daily()->withoutOverlapping();
        }),

    (new Extend\ServiceProvider())
        ->register(\ErnestDefoe\Corkboard\CorkboardServiceProvider::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [new \ErnestDefoe\Corkboard\Integration('flarum-tags')])
        ->whenExtensionEnabled('flarum-realtime', fn () => [new \ErnestDefoe\Corkboard\Integration('flarum-realtime')])
        ->whenExtensionEnabled('flarum-nicknames', fn () => [new \ErnestDefoe\Corkboard\Integration('flarum-nicknames')])
        ->whenExtensionEnabled('ernestdefoe-projects', fn () => [new \ErnestDefoe\Corkboard\Integration('ernestdefoe-projects')])
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\Corkboard\Gdpr\CorkboardData::class),
        ])
        // My cards, Due soon and Board progress as Page Builder blocks.
        ->whenExtensionEnabled('ernestdefoe-page-builder', fn () => [
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(\ErnestDefoe\Corkboard\Widget\MyCardsBlock::class),
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(\ErnestDefoe\Corkboard\Widget\DueSoonBlock::class),
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(\ErnestDefoe\Corkboard\Widget\ProgressBlock::class),
        ]),
];
