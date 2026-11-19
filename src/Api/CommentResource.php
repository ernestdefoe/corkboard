<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Comment;
use ErnestDefoe\Corkboard\Comments;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tobyz\JsonApiServer\Context as BaseContext;
use WeakMap;

/**
 * `corkboard-comments`: quick notes on a card, which follow their card.
 *
 * GET    /api/corkboard-comments?filter[card]=&filter[before]=   newest first, 20 a page
 * GET    /api/corkboard-comments/{id}
 * POST   /api/corkboard-comments        cardId, content
 * PATCH  /api/corkboard-comments/{id}   content (your own)
 * DELETE /api/corkboard-comments/{id}   (your own, or a manager)
 *
 * Writes answer (view) with the card's first page of comments, as the card modal draws them.
 *
 * @extends Resource<Comment>
 */
class CommentResource extends Resource
{
    /** @var WeakMap<Comment, array<string, mixed>> each comment's shape (Presenter::comment), built once */
    private WeakMap $shapes;

    public function __construct(
        private readonly Comments $comments,
        private readonly Presenter $presenter,
        private readonly Access $access,
    ) {
        $this->shapes = new WeakMap();
    }

    public function type(): string
    {
        return 'corkboard-comments';
    }

    public function model(): string
    {
        return Comment::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereIn('corkboard_comments.card_id', Card::whereVisibleTo($context->getActor())->select('corkboard_cards.id'));
    }

    public function endpoints(): array
    {
        return [
            self::list(20, function (Builder $query, array $filters) {
                if (isset($filters['card']) && is_numeric($filters['card'])) {
                    $query->where('corkboard_comments.card_id', (int) $filters['card']);
                }
                // Older than one already shown: new comments never shift the next page.
                if (isset($filters['before']) && is_numeric($filters['before'])) {
                    $query->where('corkboard_comments.id', '<', (int) $filters['before']);
                }
                $query->orderByDesc('corkboard_comments.id');
            }),
            Endpoint\Show::make(),
            self::createOn('cardId', 'comment'),
            Endpoint\Update::make()->authenticated()->can('update'),
            Endpoint\Delete::make()->authenticated()->can('delete'),
        ];
    }

    public function fields(): array
    {
        $shape = fn (string $key) => fn (Comment $c, Context $context) => ($this->shapes[$c] ??= $this->presenter->comment($c, $c->card->board, $context->getActor(), $context->request))[$key];

        return [
            Schema\Integer::make('cardId')->property('card_id')->writableOnCreate()->requiredOnCreate(),
            Schema\Integer::make('userId')->property('user_id')->nullable(),
            Schema\Arr::make('user')->nullable()->get(fn (Comment $c) => $c->user ? $this->presenter->user($c->user) : null),
            Schema\Str::make('html')->get($shape('html')),
            Schema\Str::make('source')->nullable()->get($shape('source')),
            Schema\Str::make('createdAt')->nullable()->get($shape('createdAt')),
            Schema\Str::make('editedAt')->nullable()->get($shape('editedAt')),
            Schema\Boolean::make('canEdit')->get($shape('canEdit')),
            Schema\Boolean::make('canDelete')->get($shape('canDelete')),
            self::writeOnly(Schema\Str::make('content')),

            self::view(function (Comment $comment, Context $context) {
                $actor = $context->getActor();
                $card = Find::card($actor, $comment->card_id);
                $page = $this->presenter->comments($card, $actor, $context->request, null);
                $out = ['comments' => $page['items'], 'more' => $page['more'], 'users' => $this->presenter->users($page['userIds'])];

                return $context->creating(self::class) ? $out + ['commentCount' => $card->comment_count, 'version' => $card->board->version] : $out;
            }),
        ];
    }

    /**
     * A page's authors and cards (with their boards) in one query each, and the viewer's role on
     * every board on it in one more, before any comment is serialized.
     */
    public function results(object $query, BaseContext $context): iterable
    {
        $comments = parent::results($query, $context);
        if ($comments instanceof Collection) {
            $comments->loadMissing(['user', 'card']);
            $boards = [];
            foreach ($comments as $comment) {
                /** @var Comment $comment */
                $boards[$comment->card->board_id] = $comment->card->board;
            }
            $this->access->prime($boards, $context->getActor());
        }

        return $comments;
    }

    public function create(object $model, BaseContext $context): object
    {
        $in = self::input($context);
        $card = Find::card($context->getActor(), (int) $in['cardId']);

        return $this->comments->create($card, $context->getActor(), is_string($in['content'] ?? null) ? $in['content'] : '');
    }

    public function update(object $model, BaseContext $context): object
    {
        $content = self::input($context)['content'] ?? '';

        return $this->comments->update($model, Find::card($context->getActor(), $model->card_id), $context->getActor(), is_string($content) ? $content : '');
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->comments->delete($model, Find::card($context->getActor(), $model->card_id), $context->getActor());
    }
}
