<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Card;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Corkboard's API is plain JSON under /api/corkboard. Each controller serves a few routes and
 * picks the action by route name (`corkboard.<area>.<action>` → the method named <action>).
 * Errors are Flarum's: a refusal is a 422 with a sentence, a board you can't see is a 404.
 */
abstract class Controller implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = (string) $request->getAttribute('routeName');
        $action = substr($name, strrpos($name, '.') + 1);
        if (! method_exists($this, $action)) {
            throw new \RuntimeException('No action '.$action);
        }
        $result = $this->{$action}($request, RequestUtil::getActor($request));

        return $result instanceof ResponseInterface ? $result : ($result === null ? new EmptyResponse(204) : new JsonResponse($result));
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function param(ServerRequestInterface $request, string $name): int
    {
        return (int) Arr::get($request->getQueryParams(), $name, 0);
    }

    protected function id(ServerRequestInterface $request): int
    {
        return (int) Arr::get($request->getAttribute('routeParameters', []), 'id');
    }

    protected function board(ServerRequestInterface $request, User $actor, ?int $id = null): Board
    {
        return Board::whereVisibleTo($actor)->findOrFail($id ?? $this->id($request));
    }

    protected function card(ServerRequestInterface $request, User $actor, ?int $id = null): Card
    {
        $card = Card::whereVisibleTo($actor)->with('board')->findOrFail($id ?? $this->id($request));
        // Its board is soft-deleted: gone for everyone.
        if ($card->board->deleted_at !== null) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        return $card;
    }
}
