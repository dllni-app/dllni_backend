<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningV3CatalogItem;

/** Public labels and allowed scope identities; never exposes tariff amounts. */
final class CleaningV3DeepScopeCatalog
{
    private const LABELS = [
        'whole' => 'كامل',
        'floor' => 'الأرضيات', 'floors' => 'الأرضيات',
        'wall' => 'الجدران', 'walls' => 'الجدران',
        'ceiling' => 'الأسقف', 'ceilings' => 'الأسقف',
        'window' => 'النوافذ', 'windows' => 'النوافذ',
        'door' => 'الأبواب', 'doors' => 'الأبواب',
    ];

    public function choices(CleaningV3CatalogItem $item): array
    {
        $configured = $item->input_rules['scopeLabels'] ?? [];
        if (! is_array($configured)) {
            return [];
        }
        $choices = [];
        foreach ($item->tariffRules->where('is_enabled', true)->where('cleaning_mode', 'deep')->pluck('scope_key')->unique()->sort() as $key) {
            $label = $key === 'whole' ? self::LABELS['whole'] : ($configured[$key] ?? self::LABELS[$key] ?? null);
            if (! is_string($label) || mb_trim($label) === '' || mb_strlen($label) > 150) {
                continue;
            }
            $choices[] = ['key' => (string) $key, 'label' => mb_trim($label)];
        }

        return $choices;
    }

    public function validLabels(mixed $labels): bool
    {
        if (! is_array($labels) || count($labels) > 30) {
            return false;
        }
        foreach ($labels as $key => $label) {
            if (! is_string($key) || $key === 'whole' || preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $key) !== 1
                || ! is_string($label) || mb_trim($label) === '' || mb_strlen($label) > 150) {
                return false;
            }
        }

        return true;
    }
}
