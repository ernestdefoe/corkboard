<?php

namespace ErnestDefoe\Corkboard\Api;

use Closure;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use Tobyz\JsonApiServer\Pagination\Pagination;
use Tobyz\JsonApiServer\Schema\Field\Field;

/**
 * What Corkboard's JSON:API resources share.
 *
 * Writes go through the services (Boards, Cards, Structure, …), which validate, check the role,
 * keep positions and log; so a field never writes itself onto the model here. Each writable field
 * is declared (and validated) on the resource, and the service gets the attributes as they were
 * sent, the same arrays it has always taken.
 *
 * A write answers with `view`: what the board or card modal redraws from (a card as the board
 * draws it, the whole board, a page of comments), only on that resource's own writes.
 *
 * @template M of Model
 * @extends AbstractDatabaseResource<M>
 */
abstract class Resource extends AbstractDatabaseResource
{
    /** @param Context $context */
    public function setValue(object $model, Field $field, mixed $value, BaseContext $context): void
    {
    }

    /** @param Context $context */
    public function saveValue(object $model, Field $field, mixed $value, BaseContext $context): void
    {
    }

    /** @return array<string, mixed> the attributes as the browser sent them */
    protected static function input(BaseContext $context): array
    {
        $attributes = $context->body()['data']['attributes'] ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * A field the browser writes and never reads back.
     *
     * @template F of Schema\Attribute
     * @param F $field
     * @param 'create'|'update'|null $only
     * @return F
     */
    protected static function writeOnly(Schema\Attribute $field, ?string $only = null): Schema\Attribute
    {
        $field->hidden();

        return match ($only) {
            'create' => $field->writableOnCreate(),
            'update' => $field->writableOnUpdate(),
            default => $field->writable(),
        };
    }

    /** What a write (and, for boards and cards, a read of one) answers with. */
    protected static function view(Closure $get, bool $onShow = false): Schema\Arr
    {
        return Schema\Arr::make('view')
            ->visible(fn (object $model, Context $context) => $context->creating(static::class) || $context->updating(static::class) || ($onShow && $context->showing(static::class)))
            ->get($get);
    }

    /**
     * A paginated list whose filters are this resource's own (core's Index only takes a model
     * searcher's): $filter gets the query, the `filter[…]` values and the context. A page holds
     * $perPage unless `page[limit]` asks for fewer, or for more up to $max.
     *
     * @param Closure(Builder<M>, array<string, mixed>, Context): void $filter
     */
    protected static function list(int $perPage, Closure $filter, ?int $max = null): Endpoint\Index
    {
        return Endpoint\Index::make()
            ->paginate($perPage, $max ?? $perPage)
            ->query(function (Builder $query, ?Pagination $pagination, Context $context, array $filters) use ($filter): Context {
                $filter($query, $filters, $context);
                if ($pagination instanceof OffsetPagination) {
                    $pagination->apply($query);
                }

                return $context->withQuery($query);
            });
    }
}
