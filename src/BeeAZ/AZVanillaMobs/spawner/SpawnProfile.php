<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\spawner;

/** Backwards-compatible, validated view of a world's spawn profile. */
final class SpawnProfile
{
    private const DEFAULTS = [
        'interval-seconds' => 5,
        'attempts-per-player' => 1,
        'max-attempts-per-cycle' => 40,
        'min-player-distance' => 24,
        'max-player-distance' => 48,
        'per-world-mob-cap' => null,
        'natural-hostile-despawn-distance' => 128,
        'categories' => ['hostile', 'passive', 'aquatic'],
        'disabled-mobs' => [],
        'mob-weights' => [
            // These mobs need a structure, raid, or special vanilla trigger.
            'warden' => 0,
            'cavespider' => 0,
            'silverfish' => 0,
            'elderguardian' => 0,
            'guardian' => 0,
            'enderdragon' => 0,
            'evoker' => 0,
            'vindicator' => 0,
            'pillager' => 0,
            'ravager' => 0,
            'vex' => 0,
            'villager' => 0,
            'irongolem' => 0,
            'snowgolem' => 0,
            'wanderingtrader' => 0,
            'allay' => 0,
            'sniffer' => 0,
            'piglinbrute' => 0,
            'zoglin' => 0,
            'endermite' => 0,
            'shulker' => 0,
        ],
        'mob-biomes' => [
            'husk' => ['desert', 'badlands*'],
            'stray' => ['snowy_*', 'frozen_*', 'ice_spikes', 'grove', 'jagged_peaks'],
            'camel' => ['desert'],
            'horse' => ['plains', 'savanna*'],
            'donkey' => ['plains', 'savanna*'],
            'ocelot' => ['jungle', 'bamboo_jungle', 'sparse_jungle'],
            'panda' => ['jungle', 'bamboo_jungle'],
            'fox' => ['taiga', '*taiga', 'grove', 'snowy_plains'],
            'goat' => ['stony_peaks', 'snowy_slopes', 'frozen_peaks', 'jagged_peaks'],
            'frog' => ['swamp', 'mangrove_swamp'],
            'slime' => ['swamp', 'mangrove_swamp'],
            'phantom' => ['*'],
        ],
        'rare-encounters' => [
            'enabled' => false,
            'chance' => 0.01,
            'max-per-world' => 2,
            'health-multiplier' => 1.5,
            'damage-multiplier' => 1.15,
            'visible-name' => true,
            'bonus-drop' => [
                'item' => 'diamond',
                'amount' => 1,
                'chance' => 0.1,
            ],
        ],
    ];

    private function __construct(
        public readonly string $name,
        private readonly array $values
    ) {
    }

    /** @param string|array<string, mixed> $worldEntry */
    public static function resolve(array $config, string $worldName, string|array $worldEntry): self
    {
        $worldProfiles = $config['world-profiles'] ?? [];
        $profileNameValue = is_array($worldEntry)
            ? ($worldEntry['profile'] ?? $worldEntry['spawn-profile'] ?? (is_array($worldProfiles) ? ($worldProfiles[$worldName] ?? 'default') : 'default'))
            : (is_array($worldProfiles) ? ($worldProfiles[$worldName] ?? 'default') : 'default');
        $profileName = is_string($profileNameValue) && trim($profileNameValue) !== '' ? trim($profileNameValue) : 'default';

        $configuredProfiles = $config['spawn-profiles'] ?? [];
        $profileOverrides = is_array($configuredProfiles) && is_array($configuredProfiles[$profileName] ?? null)
            ? $configuredProfiles[$profileName]
            : [];
        if ($profileName !== 'default' && $profileOverrides === []) {
            $profileName = 'default';
        }

        $values = self::mergeValues(self::DEFAULTS, $profileOverrides);

        // Keep the old top-level cap effective for existing installations.
        if (!array_key_exists('per-world-mob-cap', $profileOverrides)) {
            $values['per-world-mob-cap'] = $config['per-world-mob-cap'] ?? 70;
        }

        $values['interval-seconds'] = self::boundedInt($values['interval-seconds'] ?? 5, 5, 1, 3600);
        $values['attempts-per-player'] = self::boundedInt($values['attempts-per-player'] ?? 1, 1, 1, 20);
        $values['max-attempts-per-cycle'] = self::boundedInt($values['max-attempts-per-cycle'] ?? 40, 40, 1, 1000);
        $values['min-player-distance'] = self::boundedInt($values['min-player-distance'] ?? 24, 24, 0, 256);
        $values['max-player-distance'] = self::boundedInt($values['max-player-distance'] ?? 48, 48, 1, 512);
        if ($values['max-player-distance'] < $values['min-player-distance']) {
            $values['max-player-distance'] = $values['min-player-distance'];
        }
        $values['natural-hostile-despawn-distance'] = self::boundedInt($values['natural-hostile-despawn-distance'] ?? 128, 128, 0, 512);
        $values['per-world-mob-cap'] = self::boundedInt($values['per-world-mob-cap'] ?? 70, 70, 1, 10000);
        $values['categories'] = is_array($values['categories'] ?? null) ? $values['categories'] : ['hostile', 'passive', 'aquatic'];
        $disabledMobs = is_array($values['disabled-mobs'] ?? null) ? $values['disabled-mobs'] : [];
        $values['disabled-mobs'] = array_values(array_map(
            'strtolower',
            array_filter($disabledMobs, static fn(mixed $mob): bool => is_string($mob))
        ));
        $values['mob-weights'] = is_array($values['mob-weights'] ?? null) ? $values['mob-weights'] : [];
        $values['mob-biomes'] = is_array($values['mob-biomes'] ?? null) ? $values['mob-biomes'] : [];
        $values['rare-encounters'] = is_array($values['rare-encounters'] ?? null)
            ? self::mergeValues(self::DEFAULTS['rare-encounters'], $values['rare-encounters'])
            : self::DEFAULTS['rare-encounters'];

        return new self($profileName, $values);
    }

    public static function dimension(string|array $worldEntry): ?string
    {
        $dimension = is_array($worldEntry) ? ($worldEntry['dimension'] ?? null) : $worldEntry;
        if (!is_string($dimension)) {
            return null;
        }
        $dimension = strtolower($dimension);
        return in_array($dimension, ['overworld', 'nether', 'the_end'], true) ? $dimension : null;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function categoryEnabled(string $category): bool
    {
        return in_array($category, $this->values['categories'], true);
    }

    public function weight(string $mobName): float
    {
        $key = strtolower($mobName);
        if (in_array($key, $this->values['disabled-mobs'], true)) {
            return 0.0;
        }
        $value = $this->values['mob-weights'][$key] ?? 1;
        if (!is_numeric($value)) {
            return 0.0;
        }
        $weight = (float)$value;
        return is_finite($weight) ? max(0.0, min(1000.0, $weight)) : 0.0;
    }

    public function allowsBiome(string $mobName, string $biomeName): bool
    {
        $patterns = $this->values['mob-biomes'][strtolower($mobName)] ?? null;
        if ($patterns === null) {
            return true;
        }
        if (!is_array($patterns) || $patterns === []) {
            return false;
        }
        $biome = self::normalizeBiome($biomeName);
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && fnmatch(self::normalizeBiome($pattern), $biome)) {
                return true;
            }
        }
        return false;
    }

    public static function normalizeBiome(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/^minecraft[:_]/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9*?]+/', '_', $normalized) ?? '';
        return trim($normalized, '_');
    }

    private static function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }

    private static function mergeValues(array $base, array $overrides): array
    {
        if ($overrides === []) {
            return $base;
        }
        if (array_is_list($base) || array_is_list($overrides)) {
            return $overrides;
        }
        foreach ($overrides as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = self::mergeValues($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
