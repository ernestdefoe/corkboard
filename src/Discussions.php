<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Formatter\Formatter;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Start a discussion from this card": a real discussion through core's own endpoint, as the
 * member, so the tags extension's required tag and per-tag permissions apply as in the composer.
 * It goes in the board's tag; the card links to it.
 */
class Discussions
{
    public function __construct(
        private readonly JsonApi $api,
        private readonly Access $access,
        private readonly Extensions $extensions,
        private readonly Formatter $formatter,
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
        private readonly Log $log,
    ) {
    }

    public function start(Card $card, User $actor, ServerRequestInterface $request): Discussion
    {
        $board = $card->board;
        $this->access->assert($board, $actor, 'editor');
        // process() skips the endpoint's own check.
        $actor->assertCan('startDiscussion');
        if ($card->discussion_id && Discussion::query()->whereKey($card->discussion_id)->exists()) {
            throw Refusal::because('already_linked');
        }

        $link = $this->url->to('forum')->route('corkboard.board', ['id' => $board->slugged()]).'?card='.$card->id;
        $text = $card->description ? $this->formatter->unparse($card->description, null) : '';
        $content = trim($text."\n\n".$this->translator->trans('ernestdefoe-corkboard.lib.card.discussion_footer', ['{board}' => $board->title, '{url}' => $link]));

        $data = ['attributes' => ['title' => $card->title, 'content' => $content]];
        if ($this->extensions->tags()) {
            if ($board->tag_id) {
                $data['relationships']['tags']['data'] = [['type' => 'tags', 'id' => (string) $board->tag_id]];
            } elseif (! $actor->hasPermission('bypassTagCounts')) {
                throw Refusal::because('needs_tag');
            }
        }

        /** @var Discussion $discussion */
        $discussion = $this->api->forResource(DiscussionResource::class)
            ->forEndpoint('create')
            // process() merges the base request's body into ours: start from an empty one.
            ->withRequest($request->withParsedBody([])->withQueryParams([]))
            ->process(['data' => $data], [], ['actor' => $actor]);

        $card->discussion_id = $discussion->id;
        $card->save();
        self::markLinked();
        $this->log->record($board, $card->id, $actor, 'card_linked', ['d' => $discussion->id]);

        return $discussion;
    }

    /** From now on discussions carry the board chip (until then it costs no query). */
    public static function markLinked(): void
    {
        $settings = resolve(\Flarum\Settings\SettingsRepositoryInterface::class);
        if (! $settings->get('ernestdefoe-corkboard.linked')) {
            $settings->set('ernestdefoe-corkboard.linked', '1');
        }
    }
}
