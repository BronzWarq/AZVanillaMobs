<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\spawner;

use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\entity\Location;
use BeeAZ\AZVanillaMobs\entity\BaseMob;
use BeeAZ\AZVanillaMobs\entity\Monster;
use pocketmine\player\Player;

class SpawnerTask extends Task
{

    private int $tickCounter = 0;
    private \BeeAZ\AZVanillaMobs\Main $plugin;
    /** @var array<string, int> */
    private array $worldMobCounts = [];
    /** @var array<class-string, int> */
    private array $entityTypeCounts = [];
    /** @var array<string, int> */
    private array $rareMobCounts = [];
    private int $totalMobs = 0;
    private int $spawnAttempts = 0;
    private int $successfulSpawns = 0;
    private int $failedSpawns = 0;
    private int $naturalDespawns = 0;
    private int $lastCycleAttempts = 0;
    private int $lastCycleSpawns = 0;
    private float $lastCycleMilliseconds = 0.0;
    /** @var array<string, int> */
    private array $rejections = [];
    private string $lastRejectionReason = 'no-valid-position';

    public function __construct(\BeeAZ\AZVanillaMobs\Main $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onRun(): void
    {
        $this->tickCounter++;
        $config = $this->plugin->getConfig()->getAll();
        $worldsConfig = $config['worlds'] ?? ['world' => 'overworld'];
        if (!is_array($worldsConfig)) {
            $worldsConfig = [];
        }

        $dueWorlds = [];
        foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
            $worldName = $world->getFolderName();
            $entry = $worldsConfig[$worldName] ?? null;
            if (!is_string($entry) && !is_array($entry)) {
                continue;
            }
            $dimension = SpawnProfile::dimension($entry);
            if ($dimension === null) {
                continue;
            }
            $profile = SpawnProfile::resolve($config, $worldName, $entry);
            if (($profile->get('enabled') ?? true) === false || $this->tickCounter % $profile->get('interval-seconds') !== 0) {
                continue;
            }
            $dueWorlds[] = [$world, $dimension, $profile];
        }
        if ($dueWorlds === []) {
            return;
        }

        $startedAt = microtime(true);
        $this->lastCycleAttempts = 0;
        $this->lastCycleSpawns = 0;
        $this->refreshPopulationSnapshot($config, $worldsConfig);
        $globalCap = is_numeric($config['global-mob-cap'] ?? null)
            ? max(1, (int)$config['global-mob-cap'])
            : 200;

        foreach ($dueWorlds as [$world, $dimension, $profile]) {
            $worldName = $world->getFolderName();
            $players = $world->getPlayers();
            if ($players === []) {
                continue;
            }
            $worldCap = (int)$profile->get('per-world-mob-cap');
            $worldAttempts = 0;
            $worldAttemptCap = (int)$profile->get('max-attempts-per-cycle');
            foreach ($players as $player) {
                for ($attempt = 0; $attempt < (int)$profile->get('attempts-per-player'); ++$attempt) {
                    if ($this->totalMobs >= $globalCap || ($this->worldMobCounts[$worldName] ?? 0) >= $worldCap || $worldAttempts >= $worldAttemptCap) {
                        break 2;
                    }
                    ++$worldAttempts;
                    ++$this->lastCycleAttempts;
                    ++$this->spawnAttempts;
                    if ($this->attemptSpawn($player, $dimension, $profile)) {
                        ++$this->lastCycleSpawns;
                        ++$this->successfulSpawns;
                    } else {
                        ++$this->failedSpawns;
                        $reason = $this->lastRejectionReason;
                        $this->rejections[$reason] = ($this->rejections[$reason] ?? 0) + 1;
                    }
                }
            }
        }
        $this->lastCycleMilliseconds = (microtime(true) - $startedAt) * 1000.0;
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $worldsConfig */
    private function refreshPopulationSnapshot(array $config, array $worldsConfig): void
    {
        $this->worldMobCounts = [];
        $this->entityTypeCounts = [];
        $this->rareMobCounts = [];
        $this->totalMobs = 0;

        foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
            $worldName = $world->getFolderName();
            $worldEntry = $worldsConfig[$worldName] ?? null;
            $profile = null;
            if ((is_string($worldEntry) || is_array($worldEntry)) && SpawnProfile::dimension($worldEntry) !== null) {
                $resolvedProfile = SpawnProfile::resolve($config, $worldName, $worldEntry);
                if (($resolvedProfile->get('enabled') ?? true) !== false) {
                    $profile = $resolvedProfile;
                }
            }
            $players = $world->getPlayers();
            $despawnDistance = $profile !== null ? (int)$profile->get('natural-hostile-despawn-distance') : 0;
            $despawnDistanceSquared = $despawnDistance * $despawnDistance;

            foreach ($world->getEntities() as $entity) {
                if (!($entity instanceof BaseMob) || $entity->isFlaggedForDespawn()) {
                    continue;
                }
                if ($entity instanceof Monster && $entity->isNaturallySpawned() && !$entity->isPersistenceRequired() && !$entity->isRareEncounter() && $players !== [] && $despawnDistance > 0) {
                    $nearestDistanceSquared = INF;
                    foreach ($players as $player) {
                        $nearestDistanceSquared = min($nearestDistanceSquared, $entity->getPosition()->distanceSquared($player->getPosition()));
                    }
                    if ($nearestDistanceSquared >= $despawnDistanceSquared) {
                        $entity->flagForDespawn();
                        ++$this->naturalDespawns;
                        continue;
                    }
                }
                ++$this->totalMobs;
                ++$this->worldMobCounts[$worldName];
                ++$this->entityTypeCounts[$entity::class];
                if ($entity->isRareEncounter()) {
                    ++$this->rareMobCounts[$worldName];
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function getStatusSnapshot(): array
    {
        return [
            'total' => $this->totalMobs,
            'worlds' => $this->worldMobCounts,
            'types' => $this->entityTypeCounts,
            'rare' => $this->rareMobCounts,
            'attempts' => $this->spawnAttempts,
            'spawned' => $this->successfulSpawns,
            'failed' => $this->failedSpawns,
            'despawned' => $this->naturalDespawns,
            'last-cycle-attempts' => $this->lastCycleAttempts,
            'last-cycle-spawns' => $this->lastCycleSpawns,
            'last-cycle-ms' => round($this->lastCycleMilliseconds, 2),
            'rejections' => $this->rejections,
        ];
    }

    private function reject(string $reason): bool
    {
        $this->lastRejectionReason = $reason;
        return false;
    }

    private function attemptSpawn(Player $player, string $dimensionType, SpawnProfile $profile): bool
    {
        $world = $player->getWorld();
        $origin = $player->getPosition();
        $minDistance = (int)$profile->get('min-player-distance');
        $maxDistance = (int)$profile->get('max-player-distance');
        $radius = mt_rand($minDistance * 100, max($minDistance * 100, $maxDistance * 100)) / 100;
        $angle = mt_rand(0, 35999) / 100;
        $x = (int)floor($origin->x + cos(deg2rad($angle)) * $radius);
        $z = (int)floor($origin->z + sin(deg2rad($angle)) * $radius);
        $chunkX = $x >> 4;
        $chunkZ = $z >> 4;
        if (!$world->isChunkGenerated($chunkX, $chunkZ) || !$world->isChunkLoaded($chunkX, $chunkZ)) {
            return $this->reject('target-chunk-not-loaded');
        }

        $isOverworld = $dimensionType === 'overworld';
        $isWater = false;
        $isOceanBiome = false;
        $biomeName = '';
        $list = [];
        $category = 'hostile';
        $spawnY = null;
        $netherLavaSurfaceY = null;

        if ($dimensionType === 'nether') {
            $list = $this->plugin->spawnerLists['nether'] ?? [];
            $surfaceY = $world->getHighestBlockAt($x, $z);
            if ($surfaceY !== null && $world->getBlockAt($x, $surfaceY, $z) instanceof \pocketmine\block\Lava) {
                $netherLavaSurfaceY = $surfaceY;
            }
            $startY = mt_rand(35, 95);
            for ($tryY = $startY; $tryY >= 28; --$tryY) {
                if ($this->isSafeNetherPosition($world, $x, $tryY, $z)) {
                    $spawnY = $tryY;
                    break;
                }
            }
            if ($spawnY === null) {
                for ($tryY = $startY + 1; $tryY <= 105; ++$tryY) {
                    if ($this->isSafeNetherPosition($world, $x, $tryY, $z)) {
                        $spawnY = $tryY;
                        break;
                    }
                }
            }
            if ($spawnY === null) {
                if ($netherLavaSurfaceY !== null) {
                    $spawnY = $netherLavaSurfaceY + 1;
                    $list = [\BeeAZ\AZVanillaMobs\entity\nether\Strider::class];
                } else {
                    return $this->reject('no-safe-nether-floor');
                }
            }
        } elseif ($dimensionType === 'the_end') {
            $list = $this->plugin->spawnerLists['the_end'] ?? [];
            $topY = $world->getHighestBlockAt($x, $z);
            if ($topY === null || $topY < 10) {
                return $this->reject('no-end-surface');
            }
            $spawnY = $topY + 1;
            if (!$world->getBlockAt($x, $spawnY, $z)->isTransparent() || !$world->getBlockAt($x, $spawnY + 1, $z)->isTransparent()) {
                return $this->reject('blocked-end-surface');
            }
        } else {
            $topY = $world->getHighestBlockAt($x, $z);
            if ($topY === null) {
                return $this->reject('no-overworld-surface');
            }
            $surface = $world->getBlockAt($x, $topY, $z);
            $isWater = $surface instanceof \pocketmine\block\Water;
            $time = $world->getTimeOfDay();
            $isNight = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
            $isRaining = \BeeAZ\AZVanillaMobs\listener\EventListener::isWorldRaining($world);
            $light = $world->getBlockLightAt($x, $isWater ? $topY + 1 : $topY + 1, $z);

            if ($isWater) {
                $list = array_merge(
                    $this->plugin->spawnerLists['overworld_hostile'] ?? [],
                    $this->plugin->spawnerLists['overworld_passive'] ?? []
                );
                $category = 'aquatic';
                $spawnY = $topY;
            } elseif (($isNight || $isRaining) && $light <= 7) {
                $list = $this->plugin->spawnerLists['overworld_hostile'] ?? [];
                $category = 'hostile';
                $spawnY = $topY + 1;
            } else {
                $list = $this->plugin->spawnerLists['overworld_passive'] ?? [];
                $category = 'passive';
                $spawnY = $topY + 1;
            }
            $biomeName = SpawnProfile::normalizeBiome($world->getBiome($x, $topY, $z)->getName());
            $isOceanBiome = str_contains($biomeName, 'ocean');
        }

        if (!$profile->categoryEnabled($category) || $spawnY === null || $list === []) {
            return $this->reject('category-disabled-or-empty');
        }

        $freshwaterMobs = [
            \BeeAZ\AZVanillaMobs\entity\overworld\Axolotl::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Tadpole::class,
        ];
        $oceanMobs = [
            \BeeAZ\AZVanillaMobs\entity\overworld\Dolphin::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Guardian::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\ElderGuardian::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Cod::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Salmon::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Pufferfish::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\TropicalFish::class,
        ];
        $anyWaterMobs = [
            \BeeAZ\AZVanillaMobs\entity\overworld\Squid::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\GlowSquid::class,
            \BeeAZ\AZVanillaMobs\entity\overworld\Turtle::class,
        ];

        $weightedCandidates = [];
        $entityLimits = $this->plugin->getConfig()->get('entity-type-limits', []);
        if (!is_array($entityLimits)) {
            $entityLimits = [];
        }
        $typeLimitRejected = false;
        $time = $world->getTimeOfDay();
        $isNight = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
        $sleepRules = $this->plugin->getConfig()->get('phantom-sleep', []);
        $requiredNights = is_array($sleepRules) && is_numeric($sleepRules['nights-without-sleep'] ?? null)
            ? max(1, min(14, (int)$sleepRules['nights-without-sleep']))
            : 3;

        foreach ($list as $class) {
            $mobName = $this->shortMobName($class);
            if ($class === \BeeAZ\AZVanillaMobs\entity\nether\Strider::class && $netherLavaSurfaceY === null) {
                continue;
            }
            if (isset($entityLimits[$mobName]) && is_numeric($entityLimits[$mobName]) && ($this->entityTypeCounts[$class] ?? 0) >= max(0, (int)$entityLimits[$mobName])) {
                $typeLimitRejected = true;
                continue;
            }
            if ($profile->weight($mobName) <= 0 || !$profile->allowsBiome($mobName, $biomeName)) {
                continue;
            }

            if ($isOverworld) {
                if ($isWater) {
                    if (in_array($class, $freshwaterMobs, true) && $isOceanBiome) {
                        continue;
                    }
                    if (in_array($class, $oceanMobs, true) && !$isOceanBiome) {
                        continue;
                    }
                    if (!in_array($class, $freshwaterMobs, true) && !in_array($class, $oceanMobs, true) && !in_array($class, $anyWaterMobs, true) && $mobName !== 'drowned') {
                        continue;
                    }
                } elseif (in_array($class, $freshwaterMobs, true) || in_array($class, $oceanMobs, true) || in_array($class, $anyWaterMobs, true) || $mobName === 'drowned') {
                    continue;
                }

                if (!$isWater && $mobName === 'phantom' && (!$isNight || $spawnY < 30 || !$this->plugin->getSleepTracker()->isEligible($player, $world, $requiredNights))) {
                    continue;
                }
                if (!$isWater && $mobName === 'cavespider' && $world->getBlockLightAt($x, $spawnY, $z) > 7) {
                    continue;
                }
                if (!$isWater && $mobName === 'bat' && (!$isNight || $world->getBlockLightAt($x, $spawnY, $z) > 3)) {
                    continue;
                }
                if (!$isWater && $mobName === 'slime' && $spawnY >= 40 && !in_array($biomeName, ['swamp', 'mangrove_swamp'], true)) {
                    continue;
                }
                if (!$isWater && in_array($mobName, ['pig', 'cow', 'sheep', 'chicken'], true) && (str_contains($biomeName, 'desert') || str_contains($biomeName, 'snow') || str_contains($biomeName, 'frozen'))) {
                    continue;
                }
            }

            $weightedCandidates[$class] = $profile->weight($mobName);
        }

        if ($weightedCandidates === []) {
            return $this->reject($typeLimitRejected ? 'entity-type-cap' : 'no-mobs-match-world-rules');
        }
        $class = $this->chooseWeighted($weightedCandidates);
        if ($class === null) {
            return $this->reject('all-mob-weights-zero');
        }
        if ($class === \BeeAZ\AZVanillaMobs\entity\nether\Strider::class) {
            if ($netherLavaSurfaceY === null) {
                return $this->reject('strider-needs-lava-surface');
            }
            $spawnY = $netherLavaSurfaceY + 1;
        }

        $height = $class === \BeeAZ\AZVanillaMobs\entity\the_end\Enderman::class ? 3 : 2;
        if ($class === \BeeAZ\AZVanillaMobs\entity\nether\Ghast::class) {
            for ($dx = -1; $dx <= 2; ++$dx) {
                for ($dy = 0; $dy <= 3; ++$dy) {
                    for ($dz = -1; $dz <= 2; ++$dz) {
                        $block = $world->getBlockAt($x + $dx, $spawnY + $dy, $z + $dz);
                        if (!$block->isTransparent() || $block instanceof \pocketmine\block\Lava) {
                            return $this->reject('ghast-space-blocked');
                        }
                    }
                }
            }
        } else {
            for ($dy = 0; $dy < $height; ++$dy) {
                $block = $world->getBlockAt($x, $spawnY + $dy, $z);
                if ($isWater && $dy === 0 && $block instanceof \pocketmine\block\Water) {
                    continue;
                }
                if (!$block->isTransparent() || $block instanceof \pocketmine\block\Lava) {
                    return $this->reject('spawn-space-blocked');
                }
            }
        }

        $minDistanceSquared = $minDistance * $minDistance;
        foreach ($world->getPlayers() as $nearbyPlayer) {
            if ($nearbyPlayer->getPosition()->distanceSquared(new \pocketmine\math\Vector3($x + 0.5, $spawnY, $z + 0.5)) < $minDistanceSquared) {
                return $this->reject('too-close-to-player');
            }
        }

        if ($class === \BeeAZ\AZVanillaMobs\entity\overworld\Phantom::class) {
            $spawnY += mt_rand(20, 25);
        }
        $spawnPosition = new Position($x + 0.5, $spawnY, $z + 0.5, $world);
        try {
            $entity = new $class(Location::fromObject($spawnPosition, $world, mt_rand(0, 360), 0));
        } catch (\Throwable $error) {
            $this->plugin->getLogger()->debug('Natural spawn construction failed for ' . $class . ': ' . $error->getMessage());
            return $this->reject('entity-construction-failed');
        }

        if (!$entity instanceof BaseMob) {
            $entity->close();
            return $this->reject('invalid-mob-class');
        }
        $entity->setNaturallySpawned(true);
        $this->applyRareEncounter($entity, $profile, $world->getFolderName());
        $entity->spawnToAll();

        ++$this->totalMobs;
        ++$this->worldMobCounts[$world->getFolderName()];
        ++$this->entityTypeCounts[$class];
        if ($entity->isRareEncounter()) {
            ++$this->rareMobCounts[$world->getFolderName()];
        }
        return true;
    }

    private function isSafeNetherPosition(World $world, int $x, int $y, int $z): bool
    {
        $feet = $world->getBlockAt($x, $y, $z);
        $head = $world->getBlockAt($x, $y + 1, $z);
        $floor = $world->getBlockAt($x, $y - 1, $z);
        return $feet->isTransparent() && !($feet instanceof \pocketmine\block\Lava) &&
            $head->isTransparent() && !($head instanceof \pocketmine\block\Lava) &&
            $floor->isSolid() && !($floor instanceof \pocketmine\block\Lava);
    }

    private function shortMobName(string $class): string
    {
        $parts = explode('\\', $class);
        return strtolower((string)end($parts));
    }

    /** @param array<class-string, float> $weights */
    private function chooseWeighted(array $weights): ?string
    {
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return null;
        }
        $roll = mt_rand() / mt_getrandmax() * $total;
        foreach ($weights as $class => $weight) {
            $roll -= $weight;
            if ($roll <= 0.0) {
                return $class;
            }
        }
        return (string)array_key_last($weights);
    }

    private function applyRareEncounter(BaseMob $entity, SpawnProfile $profile, string $worldName): void
    {
        $settings = $profile->get('rare-encounters');
        if (!is_array($settings) || ($settings['enabled'] ?? false) !== true || !($entity instanceof Monster)) {
            return;
        }
        $limit = is_numeric($settings['max-per-world'] ?? null) ? max(0, min(100, (int)$settings['max-per-world'])) : 2;
        if (($this->rareMobCounts[$worldName] ?? 0) >= $limit) {
            return;
        }
        $chance = is_numeric($settings['chance'] ?? null) ? max(0.0, min(1.0, (float)$settings['chance'])) : 0.01;
        if ($chance <= 0.0 || mt_rand(1, 100000) > (int)round($chance * 100000)) {
            return;
        }
        $healthMultiplier = is_numeric($settings['health-multiplier'] ?? null) ? max(1.0, min(5.0, (float)$settings['health-multiplier'])) : 1.5;
        $damageMultiplier = is_numeric($settings['damage-multiplier'] ?? null) ? max(1.0, min(3.0, (float)$settings['damage-multiplier'])) : 1.15;
        $entity->setRareEncounter(true, $damageMultiplier);
        $entity->setPersistenceRequired(true);
        $bonusDrop = is_array($settings['bonus-drop'] ?? null) ? $settings['bonus-drop'] : [];
        $entity->setRareBonusDrop([
            'item' => is_string($bonusDrop['item'] ?? null) ? $bonusDrop['item'] : 'diamond',
            'amount' => is_numeric($bonusDrop['amount'] ?? null) ? (int)$bonusDrop['amount'] : 1,
            'chance' => is_numeric($bonusDrop['chance'] ?? null) ? (float)$bonusDrop['chance'] : 0.1,
        ]);
        $entity->setMaxHealth(max(1, (int)ceil($entity->getMaxHealth() * $healthMultiplier)));
        $entity->setHealth($entity->getMaxHealth());
        if (($settings['visible-name'] ?? true) === true) {
            $entity->setNameTag('§6Rare ' . $entity->getName());
            $entity->setNameTagVisible(true);
            $entity->setNameTagAlwaysVisible(true);
        }
    }
}
