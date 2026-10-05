<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Filament\Contracts;

use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Liberu\SocialNetwork\Communities\Models\Community;

/**
 * Lets a composition add its own fields to the Communities page without the
 * module depending on it. Implementations are collected from the container by TAG.
 */
interface CommunitiesPageExtension
{
    public const string TAG = 'social-network-communities-filament.extensions';

    /** @return list<string> relations to eager load on the table query */
    public function eagerLoad(): array;

    /** @return list<Column> inserted after the name column */
    public function columns(): array;

    /** @return list<BaseFilter> */
    public function filters(): array;

    /** @return list<Field|Component> appended to the create and edit forms */
    public function formComponents(): array;

    /** @return list<Entry|Component> appended to the community details */
    public function detailEntries(): array;

    /** @return array<string, mixed> extra form state when editing $community */
    public function fillForm(Community $community): array;

    /** @param array<string, mixed> $data the submitted form state */
    public function saved(Community $community, array $data): void;
}
