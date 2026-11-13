<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * The one email a person gets for a few minutes of Corkboard updates (Mail\Outbox). Each alert
 * type's own email switch decides what goes in; this type's switch (the email's unsubscribe
 * link) stops all Corkboard email.
 */
class DigestBlueprint implements BlueprintInterface, MailableInterface
{
    /**
     * @param list<array{text: string, url: string}> $lines
     */
    public function __construct(public array $lines, public ?string $boardTitle)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return null;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getType(): string
    {
        return 'corkboardEmail';
    }

    public static function getSubjectModel(): string
    {
        return \ErnestDefoe\Corkboard\Board::class;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-corkboard::emails.plain', 'html' => 'ernestdefoe-corkboard::emails.html'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        $n = count($this->lines);
        if ($n === 1) {
            return strip_tags($this->lines[0]['text']);
        }

        return $this->boardTitle !== null
            ? $translator->trans('ernestdefoe-corkboard.email.subject_board', ['{count}' => $n, '{board}' => $this->boardTitle])
            : $translator->trans('ernestdefoe-corkboard.email.subject_many', ['{count}' => $n]);
    }
}
