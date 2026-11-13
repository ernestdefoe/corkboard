<?php

/*
 * Corkboard: project boards inside the forum. Columns and cards with checklists, due dates,
 * labels, assignees and comments; public, members, team or private boards with per-person roles;
 * My cards and Due soon widgets for fof/widgets-core (and Bespoke through it) and Page Builder.
 *
 * Reads go through one visibility rule (Scope\BoardVisibility; cards follow their board) and
 * one role rule (Access). The API is plain JSON under /api/corkboard (src/Api).
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
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

$board = '{id:\d+}';

$extenders = [
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

    (new Extend\Routes('api'))
        ->get('/corkboard/boards', 'corkboard.boards.index', Api\BoardsController::class)
        ->post('/corkboard/boards', 'corkboard.boards.create', Api\BoardsController::class)
        ->get("/corkboard/boards/$board", 'corkboard.boards.show', Api\BoardsController::class)
        ->patch("/corkboard/boards/$board", 'corkboard.boards.update', Api\BoardsController::class)
        ->delete("/corkboard/boards/$board", 'corkboard.boards.delete', Api\BoardsController::class)
        ->post("/corkboard/boards/$board/clone", 'corkboard.boards.clone', Api\BoardsController::class)
        ->get("/corkboard/boards/$board/version", 'corkboard.boards.version', Api\BoardsController::class)
        ->get("/corkboard/boards/$board/cards", 'corkboard.boards.cards', Api\BoardsController::class)
        ->post("/corkboard/boards/$board/pin", 'corkboard.boards.pin', Api\BoardsController::class)
        ->post("/corkboard/boards/$board/moving", 'corkboard.boards.moving', Api\BoardsController::class)
        ->get('/corkboard/templates', 'corkboard.boards.templates', Api\BoardsController::class)

        ->get("/corkboard/boards/$board/activity", 'corkboard.team.activity', Api\TeamController::class)
        ->post("/corkboard/boards/$board/members", 'corkboard.team.add', Api\TeamController::class)
        ->patch('/corkboard/members/{id:\d+}', 'corkboard.team.role', Api\TeamController::class)
        ->delete('/corkboard/members/{id:\d+}', 'corkboard.team.remove', Api\TeamController::class)
        ->get('/corkboard/people', 'corkboard.team.people', Api\TeamController::class)

        ->post("/corkboard/boards/$board/columns", 'corkboard.structure.addColumn', Api\StructureController::class)
        ->patch('/corkboard/columns/{id:\d+}', 'corkboard.structure.editColumn', Api\StructureController::class)
        ->delete('/corkboard/columns/{id:\d+}', 'corkboard.structure.deleteColumn', Api\StructureController::class)
        ->post("/corkboard/boards/$board/labels", 'corkboard.structure.addLabel', Api\StructureController::class)
        ->patch('/corkboard/labels/{id:\d+}', 'corkboard.structure.editLabel', Api\StructureController::class)
        ->delete('/corkboard/labels/{id:\d+}', 'corkboard.structure.deleteLabel', Api\StructureController::class)

        ->post("/corkboard/boards/$board/cards", 'corkboard.cards.create', Api\CardsController::class)
        ->get('/corkboard/cards/{id:\d+}', 'corkboard.cards.show', Api\CardsController::class)
        ->patch('/corkboard/cards/{id:\d+}', 'corkboard.cards.update', Api\CardsController::class)
        ->delete('/corkboard/cards/{id:\d+}', 'corkboard.cards.delete', Api\CardsController::class)
        ->post('/corkboard/cards/{id:\d+}/clone', 'corkboard.cards.clone', Api\CardsController::class)
        ->post('/corkboard/cards/{id:\d+}/discussion', 'corkboard.cards.discussion', Api\CardsController::class)
        ->get('/corkboard/cards/{id:\d+}/comments', 'corkboard.cards.comments', Api\CardsController::class)
        ->post('/corkboard/cards/{id:\d+}/comments', 'corkboard.cards.comment', Api\CardsController::class)
        ->patch('/corkboard/comments/{id:\d+}', 'corkboard.cards.editComment', Api\CardsController::class)
        ->delete('/corkboard/comments/{id:\d+}', 'corkboard.cards.deleteComment', Api\CardsController::class)
        ->get('/corkboard/cards/{id:\d+}/activity', 'corkboard.cards.activity', Api\CardsController::class)
        ->post('/corkboard/cards/{id:\d+}/checklist', 'corkboard.cards.addItem', Api\CardsController::class)
        ->patch('/corkboard/checklist/{id:\d+}', 'corkboard.cards.editItem', Api\CardsController::class)
        ->delete('/corkboard/checklist/{id:\d+}', 'corkboard.cards.deleteItem', Api\CardsController::class)

        ->get('/corkboard/my-cards', 'corkboard.widgets.mine', Api\WidgetsController::class)
        ->get('/corkboard/due-soon', 'corkboard.widgets.dueSoon', Api\WidgetsController::class)
        ->get('/corkboard/progress/{id:\d+}', 'corkboard.widgets.progress', Api\WidgetsController::class)

        ->get('/corkboard/feed-token', 'corkboard.feed.token', Api\FeedController::class)
        ->post('/corkboard/feed-token', 'corkboard.feed.renewToken', Api\FeedController::class)
        ->get('/corkboard/feed/{token:[A-Za-z0-9]{40}}', 'corkboard.feed.ics', Api\FeedController::class),

    (new Extend\ModelVisibility(Board::class))
        ->scope(\ErnestDefoe\Corkboard\Scope\BoardVisibility::class),
    (new Extend\ModelVisibility(Card::class))
        ->scope(\ErnestDefoe\Corkboard\Scope\CardVisibility::class),

    // Only so notifications can carry their subject (no endpoints).
    new Extend\ApiResource(Api\CardResource::class),
    new Extend\ApiResource(Api\BoardResource::class),

    (new Extend\Notification())
        ->type(Notification\AssignedBlueprint::class, ['alert', 'email'])
        ->type(Notification\DueSoonBlueprint::class, ['alert', 'email'])
        ->type(Notification\OverdueBlueprint::class, ['alert'])
        ->type(Notification\MentionedBlueprint::class, ['alert', 'email'])
        ->type(Notification\CommentedBlueprint::class, ['alert'])
        ->type(Notification\DoneBlueprint::class, ['alert'])
        ->type(Notification\TeamAddedBlueprint::class, ['alert', 'email'])
        ->type(Notification\CompletedBlueprint::class, ['alert'])
        // The batched email (Mail\Outbox); its unsubscribe link stops all Corkboard email.
        ->type(Notification\DigestBlueprint::class, ['email']),

    // The "On <board> · <column>" chip, only once some card links a discussion (no query before).
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            Schema\Arr::make('corkboardCards')
                ->visible(fn () => (bool) resolve(\Flarum\Settings\SettingsRepositoryInterface::class)->get('ernestdefoe-corkboard.linked'))
                ->get(fn ($discussion, Context $context) => resolve(Api\DiscussionCards::class)($discussion, $context)),
        ]),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('corkboardCanCreate')
                ->get(fn ($forum, Context $context) => resolve(\ErnestDefoe\Corkboard\Access::class)->createRefusal($context->getActor(), resolve(\ErnestDefoe\Corkboard\Settings::class)) === null),
            Schema\Boolean::make('corkboardCanPublic')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('corkboard.createPublic')),
            Schema\Boolean::make('corkboardCanModerate')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('corkboard.moderate')),
            Schema\Boolean::make('corkboardCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('corkboard.view') || ! $context->getActor()->isGuest()),
            Schema\Arr::make('corkboardVisibilities')
                ->get(fn () => resolve(\ErnestDefoe\Corkboard\Settings::class)->visibilities()),
            Schema\Str::make('corkboardDefaultVisibility')
                ->get(fn () => resolve(\ErnestDefoe\Corkboard\Settings::class)->defaultVisibility()),
        ]),

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

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\Corkboard\Gdpr\CorkboardData::class),
        ]),
];

if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    foreach (['MyCards', 'DueSoon', 'Progress'] as $block) {
        $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock('ErnestDefoe\\Corkboard\\Widget\\'.$block.'Block');
    }
}

return $extenders;
