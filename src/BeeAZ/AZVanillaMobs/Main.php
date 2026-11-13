<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs;

use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntityDataHelper;

class Main extends PluginBase {
    private ?\BeeAZ\AZVanillaMobs\utils\SleepTracker $sleepTracker = null;
    private ?\BeeAZ\AZVanillaMobs\spawner\SpawnerTask $spawnerTask = null;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->validateConfiguration();
        $this->sleepTracker = new \BeeAZ\AZVanillaMobs\utils\SleepTracker($this);
        $this->getServer()->getPluginManager()->registerEvents($this->sleepTracker, $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\loot\LootManager($this), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\EventListener($this), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\TradeListener($this), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\LeashListener($this), $this);
        $this->getServer()->getPluginManager()->registerEvents(new \BeeAZ\AZVanillaMobs\listener\RidingListener($this), $this);

        $map = $this->getServer()->getCommandMap();
        $map->register("AZVanillaMobs", new \BeeAZ\AZVanillaMobs\command\SummonCommand($this));
        $map->register("AZVanillaMobs", new \BeeAZ\AZVanillaMobs\command\KillCommand($this));
        $map->register("AZVanillaMobs", new \BeeAZ\AZVanillaMobs\command\AZVanillaMobsCommand($this));

        $this->registerEntities();
        $this->spawnerTask = new \BeeAZ\AZVanillaMobs\spawner\SpawnerTask($this);
        $this->getScheduler()->scheduleRepeatingTask($this->spawnerTask, 20);
    }

    public function getSleepTracker(): \BeeAZ\AZVanillaMobs\utils\SleepTracker {
        if ($this->sleepTracker === null) {
            throw new \LogicException('Sleep tracking is not initialized.');
        }
        return $this->sleepTracker;
    }

    /** @return array<string, mixed> */
    public function getSpawnerStatus(): array {
        return $this->spawnerTask?->getStatusSnapshot() ?? [];
    }

    public function validateConfiguration(): void {
        $config = $this->getConfig()->getAll();
        $worlds = $config['worlds'] ?? [];
        $profiles = $config['spawn-profiles'] ?? [];
        if (isset($config['world-profiles']) && !is_array($config['world-profiles'])) {
            $this->getLogger()->warning('Config setting "world-profiles" must be a mapping of world names to profile names.');
        }
        if (!is_array($worlds)) {
            $this->getLogger()->warning('Config setting "worlds" must be a mapping; natural spawning is disabled for all worlds.');
            return;
        }
        foreach ($worlds as $worldName => $entry) {
            if (!is_string($entry) && !is_array($entry)) {
                $this->getLogger()->warning('Invalid world entry for "' . (string)$worldName . '"; expected a dimension or dimension/profile mapping.');
                continue;
            }
            if (\BeeAZ\AZVanillaMobs\spawner\SpawnProfile::dimension($entry) === null) {
                $this->getLogger()->warning('Invalid dimension for world "' . (string)$worldName . '"; use overworld, nether, or the_end.');
            }
            $worldProfiles = $config['world-profiles'] ?? [];
            $profileReference = is_array($entry)
                ? ($entry['profile'] ?? $entry['spawn-profile'] ?? (is_array($worldProfiles) ? ($worldProfiles[$worldName] ?? 'default') : 'default'))
                : (is_array($worldProfiles) ? ($worldProfiles[$worldName] ?? 'default') : 'default');
            if (!is_string($profileReference)) {
                $this->getLogger()->warning('World "' . (string)$worldName . '" has an invalid profile name; default settings will be used.');
                $profileName = 'default';
            } else {
                $profileName = trim($profileReference) !== '' ? trim($profileReference) : 'default';
            }
            if ($profileName !== 'default' && (!is_array($profiles) || !isset($profiles[$profileName]) || !is_array($profiles[$profileName]))) {
                $this->getLogger()->warning('World "' . (string)$worldName . '" refers to missing spawn profile "' . $profileName . '"; default settings will be used.');
            }
        }
        foreach (['global-mob-cap', 'per-world-mob-cap'] as $setting) {
            $value = $config[$setting] ?? null;
            if (!is_numeric($value) || (int)$value < 1) {
                $this->getLogger()->warning('Config setting "' . $setting . '" must be a positive integer.');
            }
        }
        $typeLimits = $config['entity-type-limits'] ?? [];
        if (is_array($typeLimits)) {
            foreach ($typeLimits as $mob => $limit) {
                if (!is_numeric($limit) || !is_finite((float)$limit) || (int)$limit < 0) {
                    $this->getLogger()->warning('Config setting "entity-type-limits.' . (string)$mob . '" must be a non-negative number.');
                }
            }
        } elseif (isset($config['entity-type-limits'])) {
            $this->getLogger()->warning('Config setting "entity-type-limits" must be a mapping.');
        }
        if (is_array($profiles)) {
            foreach ($profiles as $name => $profile) {
                if (!is_array($profile)) {
                    $this->getLogger()->warning('Spawn profile "' . (string)$name . '" must be a mapping.');
                    continue;
                }
                $ranges = [
                    'interval-seconds' => [1, 3600],
                    'attempts-per-player' => [1, 20],
                    'max-attempts-per-cycle' => [1, 1000],
                    'min-player-distance' => [0, 256],
                    'max-player-distance' => [1, 512],
                    'per-world-mob-cap' => [1, 10000],
                    'natural-hostile-despawn-distance' => [0, 512],
                ];
                foreach ($ranges as $setting => [$minimum, $maximum]) {
                    if (isset($profile[$setting]) && (!is_numeric($profile[$setting]) || !is_finite((float)$profile[$setting]) || (float)$profile[$setting] < $minimum || (float)$profile[$setting] > $maximum)) {
                        $this->getLogger()->warning('Spawn profile "' . (string)$name . '" has an out-of-range "' . $setting . '" value; allowed range is ' . $minimum . '..' . $maximum . '.');
                    }
                }
                if (isset($profile['categories'])) {
                    $categories = $profile['categories'];
                    $hasInvalidCategory = !is_array($categories);
                    if (is_array($categories)) {
                        foreach ($categories as $category) {
                            if (!is_string($category) || !in_array($category, ['hostile', 'passive', 'aquatic'], true)) {
                                $hasInvalidCategory = true;
                                break;
                            }
                        }
                    }
                    if ($hasInvalidCategory) {
                        $this->getLogger()->warning('Spawn profile "' . (string)$name . '" contains an unknown category. Allowed: hostile, passive, aquatic.');
                    }
                }
                if (is_array($profile['mob-weights'] ?? null)) {
                    foreach ($profile['mob-weights'] as $mob => $weight) {
                        if (!is_numeric($weight) || !is_finite((float)$weight) || (float)$weight < 0.0 || (float)$weight > 1000.0) {
                            $this->getLogger()->warning('Spawn profile "' . (string)$name . '" has an invalid weight for "' . (string)$mob . '".');
                        }
                    }
                }
                $rare = $profile['rare-encounters'] ?? null;
                if (is_array($rare)) {
                    $rareRanges = ['chance' => [0.0, 1.0], 'max-per-world' => [0, 100], 'health-multiplier' => [1.0, 5.0], 'damage-multiplier' => [1.0, 3.0]];
                    foreach ($rareRanges as $setting => [$minimum, $maximum]) {
                        if (isset($rare[$setting]) && (!is_numeric($rare[$setting]) || !is_finite((float)$rare[$setting]) || (float)$rare[$setting] < $minimum || (float)$rare[$setting] > $maximum)) {
                            $this->getLogger()->warning('Spawn profile "' . (string)$name . '" has an out-of-range rare-encounters.' . $setting . ' value; allowed range is ' . $minimum . '..' . $maximum . '.');
                        }
                    }
                }
            }
        }
        $phantomSleep = $config['phantom-sleep'] ?? [];
        if (is_array($phantomSleep) && isset($phantomSleep['nights-without-sleep']) && (!is_numeric($phantomSleep['nights-without-sleep']) || (int)$phantomSleep['nights-without-sleep'] < 1 || (int)$phantomSleep['nights-without-sleep'] > 14)) {
            $this->getLogger()->warning('Config setting "phantom-sleep.nights-without-sleep" must be an integer from 1 to 14.');
        }
    }

    public array $spawnerLists = [
        'nether' => [],
        'the_end' => [],
        'overworld_hostile' => [],
        'overworld_passive' => []
    ];

    private function registerEntities(): void {
        $creativeInventory = \pocketmine\inventory\CreativeInventory::getInstance();

        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\projectile\GhastFireball::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\projectile\GhastFireball(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);
        }, ['GhastFireball', 'minecraft:fireball']);

        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\projectile\BlazeFireball::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\projectile\BlazeFireball(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);
        }, ['BlazeFireball', 'minecraft:small_fireball']);

        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\projectile\WitchPotion::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\projectile\WitchPotion(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);
        }, ['WitchPotion', 'minecraft:splash_potion']);

        $register = function(string $class, string $name, string $id, string $category) use ($creativeInventory) {
            EntityFactory::getInstance()->register($class, function(World $world, CompoundTag $nbt) use ($class) : \pocketmine\entity\Entity {
                return new $class(EntityDataHelper::parseLocation($nbt, $world), $nbt);
            }, [$name, $id]);
            $this->spawnerLists[$category][] = $class;

            $eggMapping = [
                'minecraft:skeleton' => 'skeleton_spawn_egg',
                'minecraft:zombie' => 'zombie_spawn_egg',
                'minecraft:creeper' => 'creeper_spawn_egg',
                'minecraft:spider' => 'spider_spawn_egg',
                'minecraft:cave_spider' => 'cave_spider_spawn_egg',
                'minecraft:slime' => 'slime_spawn_egg',
                'minecraft:silverfish' => 'silverfish_spawn_egg',
                'minecraft:witch' => 'witch_spawn_egg',
                'minecraft:zombie_villager' => 'zombie_villager_spawn_egg',
                'minecraft:drowned' => 'drowned_spawn_egg',
                'minecraft:husk' => 'husk_spawn_egg',
                'minecraft:stray' => 'stray_spawn_egg',
                'minecraft:phantom' => 'phantom_spawn_egg',
                'minecraft:vindicator' => 'vindicator_spawn_egg',
                'minecraft:evoker' => 'evoker_spawn_egg',
                'minecraft:pillager' => 'pillager_spawn_egg',
                'minecraft:ravager' => 'ravager_spawn_egg',
                'minecraft:vex' => 'vex_spawn_egg',
                'minecraft:guardian' => 'guardian_spawn_egg',
                'minecraft:elder_guardian' => 'elder_guardian_spawn_egg',
                'minecraft:cow' => 'cow_spawn_egg',
                'minecraft:pig' => 'pig_spawn_egg',
                'minecraft:sheep' => 'sheep_spawn_egg',
                'minecraft:chicken' => 'chicken_spawn_egg',
                'minecraft:wolf' => 'wolf_spawn_egg',
                'minecraft:ocelot' => 'ocelot_spawn_egg',
                'minecraft:cat' => 'cat_spawn_egg',
                'minecraft:horse' => 'horse_spawn_egg',
                'minecraft:donkey' => 'donkey_spawn_egg',
                'minecraft:mule' => 'mule_spawn_egg',
                'minecraft:llama' => 'llama_spawn_egg',
                'minecraft:trader_llama' => 'trader_llama_spawn_egg',
                'minecraft:fox' => 'fox_spawn_egg',
                'minecraft:panda' => 'panda_spawn_egg',
                'minecraft:turtle' => 'turtle_spawn_egg',
                'minecraft:dolphin' => 'dolphin_spawn_egg',
                'minecraft:squid' => 'squid_spawn_egg',
                'minecraft:glow_squid' => 'glow_squid_spawn_egg',
                'minecraft:bat' => 'bat_spawn_egg',
                'minecraft:villager_v2' => 'villager_spawn_egg',
                'minecraft:wandering_trader' => 'wandering_trader_spawn_egg',
                'minecraft:axolotl' => 'axolotl_spawn_egg',
                'minecraft:goat' => 'goat_spawn_egg',
                'minecraft:frog' => 'frog_spawn_egg',
                'minecraft:tadpole' => 'tadpole_spawn_egg',
                'minecraft:cod' => 'cod_spawn_egg',
                'minecraft:salmon' => 'salmon_spawn_egg',
                'minecraft:pufferfish' => 'pufferfish_spawn_egg',
                'minecraft:tropicalfish' => 'tropical_fish_spawn_egg',
                'minecraft:camel' => 'camel_spawn_egg',
                'minecraft:sniffer' => 'sniffer_spawn_egg',
                'minecraft:allay' => 'allay_spawn_egg',
                'minecraft:bee' => 'bee_spawn_egg',
                'minecraft:zombie_pigman' => 'zombie_pigman_spawn_egg',
                'minecraft:piglin' => 'piglin_spawn_egg',
                'minecraft:piglin_brute' => 'piglin_brute_spawn_egg',
                'minecraft:hoglin' => 'hoglin_spawn_egg',
                'minecraft:zoglin' => 'zoglin_spawn_egg',
                'minecraft:ghast' => 'ghast_spawn_egg',
                'minecraft:blaze' => 'blaze_spawn_egg',
                'minecraft:magma_cube' => 'magma_cube_spawn_egg',
                'minecraft:wither_skeleton' => 'wither_skeleton_spawn_egg',
                'minecraft:strider' => 'strider_spawn_egg',
                'minecraft:enderman' => 'enderman_spawn_egg',
                'minecraft:endermite' => 'endermite_spawn_egg',
                'minecraft:shulker' => 'shulker_spawn_egg',
                'minecraft:warden' => 'warden_spawn_egg',
            ];

            if (!isset($eggMapping[$id])) {
                return;
            }

            $parseId = $eggMapping[$id];

            try {
                $oldEgg = \pocketmine\item\StringToItemParser::getInstance()->parse($parseId);
                if ($oldEgg !== null) {
                    \pocketmine\inventory\CreativeInventory::getInstance()->remove($oldEgg);
                }
            } catch (\Exception $e) {}

            $typeId = \pocketmine\item\ItemTypeIds::newId();
            $identifier = new \pocketmine\item\ItemIdentifier($typeId);

            $eggItem = new class($identifier, "§r§e" . $name . " Spawn Egg") extends \pocketmine\item\SpawnEgg {
                public string $entityClass;
                protected function createEntity(\pocketmine\world\World $world, \pocketmine\math\Vector3 $pos, float $yaw, float $pitch) : \pocketmine\entity\Entity {
                    $c = $this->entityClass;
                    return new $c(\pocketmine\entity\Location::fromObject($pos, $world, $yaw, $pitch));
                }
            };
            $eggItem->entityClass = $class;

            try {

                \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($eggItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:" . $parseId));
            } catch (\Exception $e) {}

            try {

                \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:" . $parseId, fn() => clone $eggItem);
            } catch (\Exception $e) {}

            try {
                \pocketmine\inventory\CreativeInventory::getInstance()->add($eggItem, \pocketmine\inventory\CreativeCategory::NATURE);
            } catch (\Exception $e) {}

            try {

                if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                    \pocketmine\item\StringToItemParser::getInstance()->override($parseId, fn() => clone $eggItem);
                } else {
                    \pocketmine\item\StringToItemParser::getInstance()->register($parseId, fn() => clone $eggItem);
                }
            } catch (\Exception $e) {}
        };

        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Skeleton::class, 'Skeleton', 'minecraft:skeleton', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Zombie::class, 'Zombie', 'minecraft:zombie', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Creeper::class, 'Creeper', 'minecraft:creeper', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Spider::class, 'Spider', 'minecraft:spider', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\CaveSpider::class, 'CaveSpider', 'minecraft:cave_spider', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Slime::class, 'Slime', 'minecraft:slime', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Silverfish::class, 'Silverfish', 'minecraft:silverfish', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Witch::class, 'Witch', 'minecraft:witch', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\ZombieVillager::class, 'ZombieVillager', 'minecraft:zombie_villager', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Drowned::class, 'Drowned', 'minecraft:drowned', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Husk::class, 'Husk', 'minecraft:husk', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Stray::class, 'Stray', 'minecraft:stray', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Phantom::class, 'Phantom', 'minecraft:phantom', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Vindicator::class, 'Vindicator', 'minecraft:vindicator', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Evoker::class, 'Evoker', 'minecraft:evoker', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Pillager::class, 'Pillager', 'minecraft:pillager', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Ravager::class, 'Ravager', 'minecraft:ravager', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Vex::class, 'Vex', 'minecraft:vex', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Guardian::class, 'Guardian', 'minecraft:guardian', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\ElderGuardian::class, 'ElderGuardian', 'minecraft:elder_guardian', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Warden::class, 'Warden', 'minecraft:warden', 'overworld_hostile');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Cow::class, 'Cow', 'minecraft:cow', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Pig::class, 'Pig', 'minecraft:pig', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Sheep::class, 'Sheep', 'minecraft:sheep', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Chicken::class, 'Chicken', 'minecraft:chicken', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Wolf::class, 'Wolf', 'minecraft:wolf', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Ocelot::class, 'Ocelot', 'minecraft:ocelot', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Cat::class, 'Cat', 'minecraft:cat', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Horse::class, 'Horse', 'minecraft:horse', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Donkey::class, 'Donkey', 'minecraft:donkey', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Mule::class, 'Mule', 'minecraft:mule', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Llama::class, 'Llama', 'minecraft:llama', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\TraderLlama::class, 'TraderLlama', 'minecraft:trader_llama', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Fox::class, 'Fox', 'minecraft:fox', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Panda::class, 'Panda', 'minecraft:panda', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Turtle::class, 'Turtle', 'minecraft:turtle', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Dolphin::class, 'Dolphin', 'minecraft:dolphin', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Squid::class, 'Squid', 'minecraft:squid', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\GlowSquid::class, 'GlowSquid', 'minecraft:glow_squid', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Bat::class, 'Bat', 'minecraft:bat', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Villager::class, 'Villager', 'minecraft:villager_v2', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\WanderingTrader::class, 'WanderingTrader', 'minecraft:wandering_trader', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\IronGolem::class, 'IronGolem', 'minecraft:iron_golem', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\SnowGolem::class, 'SnowGolem', 'minecraft:snow_golem', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Axolotl::class, 'Axolotl', 'minecraft:axolotl', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Goat::class, 'Goat', 'minecraft:goat', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Frog::class, 'Frog', 'minecraft:frog', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Tadpole::class, 'Tadpole', 'minecraft:tadpole', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Cod::class, 'Cod', 'minecraft:cod', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Salmon::class, 'Salmon', 'minecraft:salmon', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Pufferfish::class, 'Pufferfish', 'minecraft:pufferfish', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\TropicalFish::class, 'TropicalFish', 'minecraft:tropicalfish', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Camel::class, 'Camel', 'minecraft:camel', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Sniffer::class, 'Sniffer', 'minecraft:sniffer', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Allay::class, 'Allay', 'minecraft:allay', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\overworld\Bee::class, 'Bee', 'minecraft:bee', 'overworld_passive');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\ZombifiedPiglin::class, 'ZombifiedPiglin', 'minecraft:zombie_pigman', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Piglin::class, 'Piglin', 'minecraft:piglin', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\PiglinBrute::class, 'PiglinBrute', 'minecraft:piglin_brute', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Hoglin::class, 'Hoglin', 'minecraft:hoglin', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Zoglin::class, 'Zoglin', 'minecraft:zoglin', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Ghast::class, 'Ghast', 'minecraft:ghast', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Blaze::class, 'Blaze', 'minecraft:blaze', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\MagmaCube::class, 'MagmaCube', 'minecraft:magma_cube', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\WitherSkeleton::class, 'WitherSkeleton', 'minecraft:wither_skeleton', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\nether\Strider::class, 'Strider', 'minecraft:strider', 'nether');
        $register(\BeeAZ\AZVanillaMobs\entity\the_end\Enderman::class, 'Enderman', 'minecraft:enderman', 'the_end');
        $this->spawnerLists['overworld_hostile'][] = \BeeAZ\AZVanillaMobs\entity\the_end\Enderman::class;
        $register(\BeeAZ\AZVanillaMobs\entity\the_end\Endermite::class, 'Endermite', 'minecraft:endermite', 'the_end');
        $register(\BeeAZ\AZVanillaMobs\entity\the_end\Shulker::class, 'Shulker', 'minecraft:shulker', 'the_end');
        EntityFactory::getInstance()->register(\BeeAZ\AZVanillaMobs\entity\the_end\EnderDragon::class, function(World $world, CompoundTag $nbt) : \pocketmine\entity\Entity {
            return new \BeeAZ\AZVanillaMobs\entity\the_end\EnderDragon(EntityDataHelper::parseLocation($nbt, $world), $nbt);
        }, ['EnderDragon', 'minecraft:ender_dragon']);

        try {
            $saddleId = \pocketmine\item\ItemTypeIds::newId();
            $saddleIdentifier = new \pocketmine\item\ItemIdentifier($saddleId);
            $saddleItem = new \pocketmine\item\Item($saddleIdentifier, "Saddle");

            \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($saddleItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:saddle"));
            \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:saddle", fn() => clone $saddleItem);

            \pocketmine\inventory\CreativeInventory::getInstance()->add($saddleItem, \pocketmine\inventory\CreativeCategory::EQUIPMENT);

            if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                \pocketmine\item\StringToItemParser::getInstance()->override("saddle", fn() => clone $saddleItem);
            } else {
                \pocketmine\item\StringToItemParser::getInstance()->register("saddle", fn() => clone $saddleItem);
            }
        } catch (\Exception $e) {}

        try {
            $leadId = \pocketmine\item\ItemTypeIds::newId();
            $leadIdentifier = new \pocketmine\item\ItemIdentifier($leadId);
            $leadItem = new \BeeAZ\AZVanillaMobs\item\Lead($leadIdentifier, "Lead");

            \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($leadItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:lead"));
            \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:lead", fn() => clone $leadItem);

            \pocketmine\inventory\CreativeInventory::getInstance()->add($leadItem);

            if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                \pocketmine\item\StringToItemParser::getInstance()->override("lead", fn() => clone $leadItem);
            } else {
                \pocketmine\item\StringToItemParser::getInstance()->register("lead", fn() => clone $leadItem);
            }
        } catch (\Exception $e) {}

        try {
            $registerBucket = function(string $class, string $name, string $id, string $parseId) {
                try {
                    $bucketId = \pocketmine\item\ItemTypeIds::newId();
                    $bucketIdentifier = new \pocketmine\item\ItemIdentifier($bucketId);
                    $bucketItem = new \BeeAZ\AZVanillaMobs\item\FishBucket($bucketIdentifier, $name, $class);

                    \pocketmine\world\format\io\GlobalItemDataHandlers::getSerializer()->map($bucketItem, fn() => new \pocketmine\data\bedrock\item\SavedItemData("minecraft:" . $id));
                    \pocketmine\world\format\io\GlobalItemDataHandlers::getDeserializer()->map("minecraft:" . $id, fn() => clone $bucketItem);

                    \pocketmine\inventory\CreativeInventory::getInstance()->add($bucketItem, \pocketmine\inventory\CreativeCategory::NATURE);

                    if (method_exists(\pocketmine\item\StringToItemParser::getInstance(), 'override')) {
                        \pocketmine\item\StringToItemParser::getInstance()->override($parseId, fn() => clone $bucketItem);
                    } else {
                        \pocketmine\item\StringToItemParser::getInstance()->register($parseId, fn() => clone $bucketItem);
                    }
                } catch (\Exception $e) {}
            };

            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Cod::class, "Cod Bucket", "cod_bucket", "cod_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Salmon::class, "Salmon Bucket", "salmon_bucket", "salmon_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Pufferfish::class, "Pufferfish Bucket", "pufferfish_bucket", "pufferfish_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\TropicalFish::class, "Tropical Fish Bucket", "tropical_fish_bucket", "tropical_fish_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Axolotl::class, "Axolotl Bucket", "axolotl_bucket", "axolotl_bucket");
            $registerBucket(\BeeAZ\AZVanillaMobs\entity\overworld\Tadpole::class, "Tadpole Bucket", "tadpole_bucket", "tadpole_bucket");
        } catch (\Exception $e) {}
    }
}
