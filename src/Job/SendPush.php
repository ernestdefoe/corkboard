<?php

namespace ErnestDefoe\Corkboard\Job;

use ErnestDefoe\Corkboard\Push;
use Flarum\Queue\AbstractJob;

/** One realtime event for a board's team, sent from the queue so no request waits on the realtime server. */
class SendPush extends AbstractJob
{
    /** @param array<string, mixed> $event */
    public function __construct(
        public readonly int $boardId,
        public readonly array $event,
    ) {
    }

    public function handle(Push $push): void
    {
        $push->deliver($this->boardId, $this->event);
    }
}
