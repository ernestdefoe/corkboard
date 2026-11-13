<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Foundation\ValidationException;

/** A plain-language "no" for the form: a 422 whose message is a locale key under lib.error. */
class Refusal
{
    public static function because(string $key, string $field = 'corkboard'): ValidationException
    {
        return new ValidationException([$field => resolve(\Flarum\Locale\TranslatorInterface::class)->trans('ernestdefoe-corkboard.lib.error.'.$key)]);
    }
}
