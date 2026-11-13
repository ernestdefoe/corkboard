<?php

namespace ErnestDefoe\Corkboard\Widget;

class MyCardsBlock extends Block
{
    protected function widget(): string
    {
        return 'mine';
    }

    public function icon(): string
    {
        return 'fas fa-id-card';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'count', 'type' => 'number', 'label' => $this->t('count'), 'default' => 5],
            ['key' => 'owned', 'type' => 'toggle', 'label' => $this->t('owned'), 'default' => false],
        ];
    }
}
