<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Corkboard's actions that aren't a resource's create, read, update or delete (cloning, pinning,
 * the version poll, widgets, the calendar feed…), one route each under /api/corkboard. They answer
 * plain JSON (or 204). Errors are Flarum's: a refusal is a 422 with a sentence, a board you can't
 * see is a 404.
 */
abstract class Handler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->respond($request, RequestUtil::getActor($request));

        return $result instanceof ResponseInterface ? $result : ($result === null ? new EmptyResponse(204) : new JsonResponse($result));
    }

    /** @return array<string, mixed>|ResponseInterface|null */
    abstract protected function respond(ServerRequestInterface $request, User $actor): array|ResponseInterface|null;

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
}
