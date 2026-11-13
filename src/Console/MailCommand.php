<?php

namespace ErnestDefoe\Corkboard\Console;

use ErnestDefoe\Corkboard\Mail\Outbox;
use Illuminate\Console\Command;

/** Every few minutes: one email per person for what's waiting in the outbox. */
class MailCommand extends Command
{
    protected $signature = 'corkboard:mail {--now : Send everything waiting, even updates from the last two minutes}';

    protected $description = 'Send the Corkboard emails that are waiting.';

    public function handle(Outbox $outbox): int
    {
        $this->info('Sent '.$outbox->flush((bool) $this->option('now')).' emails.');

        return 0;
    }
}
