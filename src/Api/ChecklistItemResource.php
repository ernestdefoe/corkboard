<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Checklist;
use ErnestDefoe\Corkboard\ChecklistItem;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * `corkboard-checklist-items`, which follow their card.
 *
 * GET    /api/corkboard-checklist-items/{id}
 * POST   /api/corkboard-checklist-items        cardId, text, afterId?
 * PATCH  /api/corkboard-checklist-items/{id}   text, isDone, afterId
 * DELETE /api/corkboard-checklist-items/{id}
 *
 * Writes answer (view) with the card's whole checklist, its counts and the board's version.
 *
 * @extends Resource<ChecklistItem>
 */
class ChecklistItemResource extends Resource
{
    public function __construct(private readonly Checklist $checklist)
    {
    }

    public function type(): string
    {
        return 'corkboard-checklist-items';
    }

    public function model(): string
    {
        return ChecklistItem::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereIn('corkboard_checklist.card_id', Card::whereVisibleTo($context->getActor())->select('corkboard_cards.id'));
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make(),
            self::createOn('cardId', 'update'),
            Endpoint\Update::make()->authenticated()->can('update'),
            Endpoint\Delete::make()->authenticated()->can('delete'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('cardId')->property('card_id')->writableOnCreate()->requiredOnCreate(),
            Schema\Str::make('text')->writable(),
            Schema\Boolean::make('isDone')->property('is_done')->writableOnUpdate(),
            Schema\Integer::make('userId')->property('user_id')->nullable(),
            self::writeOnly(Schema\Integer::make('afterId')->nullable()),

            self::view(function (ChecklistItem $item, Context $context) {
                $card = Find::card($context->getActor(), $item->card_id);
                $items = ChecklistItem::query()->where('card_id', $card->id)->orderBy('position')->orderBy('id')->get();

                return [
                    'checklist' => $items->map(fn (ChecklistItem $i) => ['id' => $i->id, 'text' => $i->text, 'isDone' => $i->is_done, 'userId' => $i->user_id])->values()->all(),
                    'checklistDone' => $card->checklist_done,
                    'checklistTotal' => $card->checklist_total,
                    'version' => $card->board->version,
                ];
            }),
        ];
    }

    public function create(object $model, BaseContext $context): object
    {
        $in = self::input($context);
        $card = Find::card($context->getActor(), (int) $in['cardId']);
        unset($in['cardId']);

        return $this->checklist->add($card, $context->getActor(), $in);
    }

    public function update(object $model, BaseContext $context): object
    {
        return $this->checklist->update($model, Find::card($context->getActor(), $model->card_id), $context->getActor(), self::input($context));
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->checklist->delete($model, Find::card($context->getActor(), $model->card_id), $context->getActor());
    }
}
