<?php

namespace ErnestDefoe\Corkboard\Widget;

class DueSoonBlock extends Block
{
    protected function widget(): string
    {
        return 'due';
    }

    public function icon(): string
    {
        return 'fas fa-clock';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'days', 'type' => 'select', 'label' => $this->t('days'), 'default' => '3',
                'options' => [['value' => '1', 'label' => $this->t('days_1')], ['value' => '3', 'label' => $this->t('days_3')], ['value' => '7', 'label' => $this->t('days_7')]]],
            ['key' => 'board', 'type' => 'text', 'label' => $this->t('board'), 'help' => $this->t('board_help'), 'default' => ''],
            ['key' => 'count', 'type' => 'number', 'label' => $this->t('count'), 'default' => 5],
        ];
    }
}
