<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Enums;

/**
 * Where a community meets: online, or in person in a city.
 */
enum CommunityModality: string
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

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $modality) {
            $options[$modality->value] = $modality->label();
        }

        return $options;
    }
}
