<?php

namespace ErnestDefoe\Corkboard;

use DomainException;

/**
 * A plain-language "no" for the form: a 422 on one field whose sentence is a locale key under
 * lib.error. It carries the key, not the text; Http\TranslateRefusals translates it in the
 * viewer's language as the API answers (and Extend\ErrorHandling makes it a 422 anywhere else).
 */
class Refusal extends DomainException
{
    public function __construct(public readonly string $key, public readonly string $field = 'corkboard')
    {
        parent::__construct('ernestdefoe-corkboard.lib.error.'.$key);
    }

    public static function because(string $key, string $field = 'corkboard'): self
    {
        return new self($key, $field);
    }
}
