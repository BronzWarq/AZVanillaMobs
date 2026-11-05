<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\spawner;

use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\entity\Location;
use BeeAZ\AZVanillaMobs\entity\BaseMob;

class SpawnerTask extends Task
{

    private int $tickCounter = 0;
    private \BeeAZ\AZVanillaMobs\Main $plugin;

    public function __construct(\BeeAZ\AZVanillaMobs\Main $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onRun(): void
    {
        $this->tickCounter++;

        if ($this->tickCounter % 5 !== 0) return;

        $config = $this->plugin->getConfig();
        $worldsConfig = $config->get("worlds", ["world" => "overworld"]);
        $globalCap = (int) $config->get("global-mob-cap", 200);
        $perWorldCap = (int) $config->get("per-world-mob-cap", 70);

        $totalMobs = 0;
        foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
            $totalMobs += $this->countWorldMobs($world);
        }

        if ($totalMobs >= $globalCap) return;

        foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
            $folderName = $world->getFolderName();
            if (!isset($worldsConfig[$folderName])) continue;

            $dimensionType = $worldsConfig[$folderName];

            $players = $world->getPlayers();
            if (empty($players)) continue;

            $worldMobs = $this->countWorldMobs($world);
            if ($worldMobs >= $perWorldCap) continue;

            foreach ($players as $player) {
                if ($totalMobs >= $globalCap || $worldMobs >= $perWorldCap) break;
                if ($this->attemptSpawn($player->getPosition(), $dimensionType)) {
                    ++$totalMobs;
                    ++$worldMobs;
                }
            }
        }
    }

    private function countWorldMobs(World $world): int {
        $count = 0;
        foreach ($world->getEntities() as $entity) {
            if ($entity instanceof BaseMob && !$entity->isFlaggedForDespawn()) ++$count;
        }
        return $count;
    }

    private function getEntityTypeCount(string $entityClass): int {
        $count = 0;
        foreach (Server::getInstance()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof $entityClass) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function getOverworldBiomeType(int $x, int $z, int $y, \pocketmine\world\World $world): string
    {
        $sandCount = 0;
        $snowCount = 0;
        $grassCount = 0;

        for ($dx = -2; $dx <= 2; $dx++) {
            for ($dz = -2; $dz <= 2; $dz++) {
                $className = get_class($world->getBlockAt($x + $dx, $y, $z + $dz));

                if ($className === 'pocketmine\\block\\Sand' || $className === 'pocketmine\\block\\RedSand') {
                    $sandCount++;
                    if ($sandCount > 12) return 'desert';
                } else if ($className === 'pocketmine\\block\\Snow' || $className === 'pocketmine\\block\\SnowLayer' || $className === 'pocketmine\\block\\Ice') {
                    $snowCount++;
                    if ($snowCount > 12) return 'snow';
                } else if (strpos($className, 'Grass') !== false) {
                    $grassCount++;
                    if ($grassCount > 10) return 'grass';
                }
            }
        }

        return ($sandCount > 8) ? 'desert' : (($snowCount > 8) ? 'snow' : (($grassCount > 5) ? 'grass' : 'generic'));
    }

    private function attemptSpawn(Position $pos, string $dimensionType): bool
    {
        $world = $pos->getWorld();
        $x = $pos->getFloorX() + mt_rand(-24, 24);
        $z = $pos->getFloorZ() + mt_rand(-24, 24);

        $chunkX = $x >> 4;
        $chunkZ = $z >> 4;
        if (!$world->isChunkGenerated($chunkX, $chunkZ)) {
            return false;
        }

        $list = [];
        $category = '';
        $spawnY = null;

        if ($dimensionType === "nether") {
            $list = $this->plugin->spawnerLists['nether'] ?? [];
            $category = 'nether';

            $spawnY = null;
            $startY = mt_rand(35, 95);
            for ($tryY = $startY; $tryY >= 28; $tryY--) {
                $blockAtY = $world->getBlockAt($x, $tryY, $z);
                $blockAbove = $world->getBlockAt($x, $tryY + 1, $z);
                $blockBelow = $world->getBlockAt($x, $tryY - 1, $z);
                
                if ($blockAtY->isTransparent() && !($blockAtY instanceof \pocketmine\block\Lava) &&
                    $blockAbove->isTransparent() && !($blockAbove instanceof \pocketmine\block\Lava) &&
                    $blockBelow->isSolid() && !($blockBelow instanceof \pocketmine\block\Lava)) {
                    $spawnY = $tryY;
                    break;
                }
            }
            if ($spawnY === null) {
                for ($tryY = $startY + 1; $tryY <= 105; $tryY++) {
                    $blockAtY = $world->getBlockAt($x, $tryY, $z);
                    $blockAbove = $world->getBlockAt($x, $tryY + 1, $z);
                    $blockBelow = $world->getBlockAt($x, $tryY - 1, $z);
                    
                    if ($blockAtY->isTransparent() && !($blockAtY instanceof \pocketmine\block\Lava) &&
                        $blockAbove->isTransparent() && !($blockAbove instanceof \pocketmine\block\Lava) &&
                        $blockBelow->isSolid() && !($blockBelow instanceof \pocketmine\block\Lava)) {
                        $spawnY = $tryY;
                        break;
                    }
                }
            }
            
            if ($spawnY === null) return false;
        } elseif ($dimensionType === "the_end") {
            $list = $this->plugin->spawnerLists['the_end'] ?? [];
            $category = 'the_end';

            $y = $world->getHighestBlockAt($x, $z);
            if ($y === null || $y < 10) return false;

            $block = $world->getBlockAt($x, $y, $z);
            $checkBlock = $world->getBlockAt($x, $y + 1, $z);
            $checkBlock2 = $world->getBlockAt($x, $y + 2, $z);

            if (!$checkBlock->isFullCube() && !$checkBlock2->isFullCube()) {
                $spawnY = $y + 2;
            } else {
                return false;
            }
        } else {
            $y = $world->getHighestBlockAt($x, $z);
            if ($y === null || $y < 0) return false;

            $block = $world->getBlockAt($x, $y, $z);
            $isWater = $block instanceof \pocketmine\block\Water;

            $light = $world->getBlockLightAt($x, $y + 1, $z);
            $time = $world->getTimeOfDay();
            $isNight = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;

            $isRaining = \BeeAZ\AZVanillaMobs\listener\EventListener::isWorldRaining($world);

            if ($isWater) {
                $list = $this->plugin->spawnerLists['overworld_hostile'] ?? [];
                $category = 'overworld_hostile';
            } else {
                if (($isNight || $isRaining) && $light <= 7) {
                    $list = $this->plugin->spawnerLists['overworld_hostile'] ?? [];
                    $category = 'overworld_hostile';
                } else {
                    $list = $this->plugin->spawnerLists['overworld_passive'] ?? [];
                    $category = 'overworld_passive';
                }
            }

            $spawnY = $isWater ? $y : $y + 1;
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

        if (!empty($list) && $spawnY !== null) {

            $filteredList = [];
            foreach ($list as $c) {
                if ($dimensionType === 'nether' || $dimensionType === 'the_end') {
                    $filteredList[] = $c;
                    continue;
                }

                $blockBelow = $world->getBlockAt($x, (int)$spawnY - 1, $z);
                $spawnBlock = $world->getBlockAt($x, (int)$spawnY, $z);
                $isWater = $spawnBlock instanceof \pocketmine\block\Water || $blockBelow instanceof \pocketmine\block\Water;
                $isLava = $spawnBlock instanceof \pocketmine\block\Lava || $blockBelow instanceof \pocketmine\block\Lava;

                if ($isLava) {
                    continue;
                }

                $isFreshwater = false;
                $isOcean = false;
                $biomeType = $this->getOverworldBiomeType($x, $z, (int)$spawnY - 1, $world);

                if ($isWater) {
                    $hasSeagrass = false;
                    for ($dx = -1; $dx <= 1; $dx++) {
                        for ($dz = -1; $dz <= 1; $dz++) {
                            $className = get_class($world->getBlockAt($x + $dx, (int)$spawnY - 1, $z + $dz));
                            if (strpos($className, 'Seagrass') !== false || strpos($className, 'KelpPlant') !== false) {
                                $hasSeagrass = true;
                                break 2;
                            }
                        }
                    }

                    if ($hasSeagrass) {
                        $isOcean = true;
                    } else {
                        $isFreshwater = true;
                    }
                }

                $parts = explode('\\', $c);
                $mobName = strtolower(array_pop($parts));

                if (in_array($c, $freshwaterMobs)) {
                    if ($isFreshwater) {
                        $filteredList[] = $c;
                    }
                } else if (in_array($c, $oceanMobs)) {
                    if ($isOcean) {
                        $filteredList[] = $c;
                    }
                } else if (in_array($c, $anyWaterMobs)) {
                    if ($isWater) {
                        $filteredList[] = $c;
                    }
                } else {
                    if ($mobName === 'drowned') {
                        if ($isWater) $filteredList[] = $c;
                    } else if (!$isWater && !$isLava) {
                        if ($mobName === 'husk') {
                            if ($biomeType === 'desert') $filteredList[] = $c;
                        } else if ($mobName === 'stray') {
                            if ($biomeType === 'snow') $filteredList[] = $c;
                        } else if ($mobName === 'phantom') {
                            $time = $world->getTimeOfDay();
                            $isNight = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
                            if ($isNight && $spawnY >= 30) $filteredList[] = $c;
                        } else if ($mobName === 'cavespider') {
                            $light = $world->getBlockLightAt($x, (int)$spawnY, $z);
                            if ($light <= 7) $filteredList[] = $c;
                        } else if ($mobName === 'bat') {
                            $time = $world->getTimeOfDay();
                            $isNight = $time >= World::TIME_NIGHT && $time < World::TIME_SUNRISE;
                            $light = $world->getBlockLightAt($x, (int)$spawnY, $z);
                            if ($isNight && $light <= 3) $filteredList[] = $c;
                        } else if (in_array($mobName, ['horse', 'donkey', 'mule', 'llama', 'traderllama', 'wolf', 'fox'])) {
                            if ($biomeType === 'grass') $filteredList[] = $c;
                        } else if (in_array($mobName, ['pig', 'cow', 'sheep', 'chicken'])) {
                            if ($biomeType !== 'desert' && $biomeType !== 'snow') $filteredList[] = $c;
                        } else if ($mobName === 'ocelot') {
                            if ($biomeType === 'grass') $filteredList[] = $c;
                        } else if ($mobName === 'cat') {
                            if ($biomeType === 'grass') $filteredList[] = $c;
                        } else if ($mobName === 'panda') {
                            if ($biomeType === 'grass') $filteredList[] = $c;
                        } else if (in_array($mobName, ['spider', 'zombie', 'skeleton', 'creeper', 'witch', 'silverfish', 'enderman', 'vindicator', 'evoker', 'pillager', 'ravager', 'vex', 'zombievillager'])) {
                            $filteredList[] = $c;
                        } else if ($mobName === 'slime') {
                            if ($spawnY < 40) $filteredList[] = $c;
                        } else if (in_array($mobName, ['goat', 'sniffer', 'camel'])) {
                            if ($biomeType === 'grass' || $biomeType === 'generic') $filteredList[] = $c;
                        } else if (in_array($mobName, ['frog'])) {
                            if ($biomeType === 'grass') $filteredList[] = $c;
                        } else if (in_array($mobName, ['villager', 'irongolem', 'snowgolem', 'wanderingtrader', 'allay', 'bee'])) {
                            if ($category === 'overworld_passive') $filteredList[] = $c;
                        } else {
                            if ($category === 'overworld_passive') $filteredList[] = $c;
                        }
                    }
                }
            }

            if (empty($filteredList)) return false;
            $class = $filteredList[array_rand($filteredList)];

            if ($class === \BeeAZ\AZVanillaMobs\entity\nether\Ghast::class) {
                $valid = true;
                for ($dx = -1; $dx <= 2; $dx++) {
                    for ($dy = 0; $dy <= 3; $dy++) {
                        for ($dz = -1; $dz <= 2; $dz++) {
                            $checkBlock = $world->getBlockAt($x + $dx, $spawnY + $dy, $z + $dz);
                            if (!$checkBlock->isTransparent() || $checkBlock instanceof \pocketmine\block\Lava) {
                                $valid = false;
                                break 2;
                            }
                        }
                    }
                }
                if (!$valid) {
                    $filteredWithoutGhast = array_filter($filteredList, fn($c) => $c !== \BeeAZ\AZVanillaMobs\entity\nether\Ghast::class);
                    if (!empty($filteredWithoutGhast)) {
                        $class = $filteredWithoutGhast[array_rand($filteredWithoutGhast)];
                    } else {
                        return false;
                    }
                }
            }

            $height = 2;
            if (strpos($class, 'Enderman') !== false) {
                $height = 3;
            }
            if ($height > 2) {
                $valid = true;
                for ($h = 2; $h < $height; $h++) {
                    $checkBlock = $world->getBlockAt($x, $spawnY + $h, $z);
                    if (!$checkBlock->isTransparent() || $checkBlock instanceof \pocketmine\block\Lava) {
                        $valid = false;
                        break;
                    }
                }
                if (!$valid) {
                    $filteredShort = array_filter($filteredList, fn($c) => strpos($c, 'Enderman') === false && $c !== \BeeAZ\AZVanillaMobs\entity\nether\Ghast::class);
                    if (!empty($filteredShort)) {
                        $class = $filteredShort[array_rand($filteredShort)];
                    } else {
                        return false;
                    }
                }
            }

            $parts = explode('\\', $class);
            $entityName = strtolower(array_pop($parts));
            
            $config = $this->plugin->getConfig();
            $entityLimits = $config->get("entity-type-limits", []);
            
            if (isset($entityLimits[$entityName])) {
                $limit = (int) $entityLimits[$entityName];
                $currentCount = $this->getEntityTypeCount($class);
                if ($currentCount >= $limit) {
                    return false;
                }
            }

            if ($class === \BeeAZ\AZVanillaMobs\entity\overworld\Phantom::class) {
                $spawnY = $spawnY + mt_rand(20, 25);
            }

            $spawnPos = new Position($x + 0.5, $spawnY, $z + 0.5, $world);

            $entity = new $class(Location::fromObject($spawnPos, $world, mt_rand(0, 360), 0));
            $entity->spawnToAll();
            return true;
        }
        return false;
    }
}
