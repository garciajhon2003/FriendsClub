<?php

namespace App\Enums;

enum ClubModality: string
{
    case Online = 'online';
    case InPerson = 'in_person';

    public function label(): string
    {
        return match ($this) {
            self::Online => __('Online'),
            self::InPerson => __('In person'),
        };
    }
}
