<?php

namespace ErnestDefoe\Corkboard\Widget;

class ProgressBlock extends Block
{
    protected function widget(): string
    {
        return 'progress';
    }

    public function icon(): string
    {
        return 'fas fa-bars-progress';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'board', 'type' => 'text', 'label' => $this->t('board'), 'help' => $this->t('progress_board_help'), 'default' => ''],
            ['key' => 'next', 'type' => 'toggle', 'label' => $this->t('show_next'), 'default' => true],
        ];
    }
}
