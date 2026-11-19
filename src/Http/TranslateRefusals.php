<?php

namespace ErnestDefoe\Corkboard\Http;

use ErnestDefoe\Corkboard\Refusal;
use Flarum\Foundation\ErrorHandling\HandledError;
use Flarum\Foundation\ErrorHandling\JsonApiFormatter;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns a Refusal into the 422 core gives a validation error: its sentence, translated in the
 * viewer's language (core's SetLocale has run), on its field. Here rather than in an
 * Extend\ErrorHandling handler because core makes those with `new`, so they can't be given the
 * translator; extend.php maps the class to validation_error too, for anywhere this doesn't run.
 */
class TranslateRefusals implements MiddlewareInterface
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Refusal $refusal) {
            $error = (new HandledError($refusal, 'validation_error', 422))->withDetails([[
                'detail' => $this->translator->trans($refusal->getMessage()),
                'source' => ['pointer' => '/data/attributes/'.$refusal->field],
            ]]);

            return (new JsonApiFormatter())->format($error, $request);
        }
    }
}
