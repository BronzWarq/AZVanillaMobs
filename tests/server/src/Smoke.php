<?php
declare(strict_types=1);

namespace BeeAZ\AZVanillaMobsSmoke;

use BeeAZ\AZVanillaMobs\Main as AZVanillaMobsMain;
use BeeAZ\AZVanillaMobs\spawner\SpawnProfile;
use BeeAZ\AZVanillaMobs\entity\nether\MagmaCube;
use BeeAZ\AZVanillaMobs\entity\overworld\{Cod, Creeper, Drowned, Phantom, Slime, SnowGolem, Villager, Zombie};
use BeeAZ\AZVanillaMobs\item\FishBucket;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Snowball;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\item\ItemUseResult;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\event\EventPriority;
use pocketmine\inventory\PlayerInventory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\World;

final class SmokePlayer extends Player {
    private PlayerInventory $testInventory;
    public function __construct(private World $testWorld) {
        $this->testInventory = new PlayerInventory($this);
    }
    public function getName(): string { return 'AZVanillaMobsSmoke'; }
    public function getInventory(): PlayerInventory { return $this->testInventory; }
    public function getWorld(): World { return $this->testWorld; }
    public function isCreative(bool $literal = false): bool { return false; }
    public function __destruct() {}
}

final class Smoke extends PluginBase {
    private bool $cancelNextBucket = false;

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvent(PlayerBucketEmptyEvent::class, function(PlayerBucketEmptyEvent $event): void {
            if ($this->cancelNextBucket) $event->cancel();
        }, EventPriority::NORMAL, $this);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $this->check()), 40);
    }

    private function check(): void {
        $checks = [];
        try {
            $server = $this->getServer();
            $plugin = $server->getPluginManager()->getPlugin('AZVanillaMobs');
            if ($plugin === null || !$plugin->isEnabled()) throw new \RuntimeException('AZVanillaMobs did not enable');
            if (!$plugin instanceof AZVanillaMobsMain) throw new \RuntimeException('AZVanillaMobs loaded with an unexpected main class');
            $commandMap = $server->getCommandMap();
            foreach (['azsummon', 'azm', 'azkill'] as $command) {
                if ($commandMap->getCommand($command) === null) throw new \RuntimeException("Missing command: $command");
            }
            if ($commandMap->getCommand('summon') instanceof \BeeAZ\AZVanillaMobs\command\SummonCommand) {
                throw new \RuntimeException('AZVanillaMobs registered its summon command under the shared /summon name');
            }
            $checks[] = 'plugin command names avoid the shared summon name';
            $plugin->getSleepTracker();
            $profileConfig = $plugin->getConfig()->getAll();
            $survivalProfile = SpawnProfile::resolve($profileConfig, 'world', $profileConfig['worlds']['world']);
            if ($survivalProfile->get('interval-seconds') !== 5 || $survivalProfile->weight('warden') !== 0.0 ||
                !$survivalProfile->allowsBiome('camel', 'minecraft:desert') || $survivalProfile->allowsBiome('camel', 'plains')) {
                throw new \RuntimeException('Natural spawn profile defaults or biome rules are incorrect');
            }
            $malformedProfileConfig = $profileConfig;
            $malformedProfileConfig['spawn-profiles']['smoke-invalid'] = ['disabled-mobs' => ['zombie', 1, []]];
            $malformedProfile = SpawnProfile::resolve($malformedProfileConfig, 'world', ['dimension' => 'overworld', 'profile' => 'smoke-invalid']);
            if ($malformedProfile->weight('zombie') !== 0.0 || $malformedProfile->weight('creeper') <= 0.0) {
                throw new \RuntimeException('Malformed disabled-mobs entries were not safely ignored');
            }
            $checks[] = 'spawn profiles, biome rules, and sleep service registration';
            $world = $server->getWorldManager()->getDefaultWorld();
            if ($world === null) throw new \RuntimeException('No default world');
            $spawn = $world->getSpawnLocation();
            $x = ($spawn->getFloorX() >> 4) * 16 + 8;
            $z = ($spawn->getFloorZ() >> 4) * 16 + 8;
            for ($cx = ($x >> 4) - 2; $cx <= ($x >> 4) + 2; $cx++) {
                for ($cz = ($z >> 4) - 2; $cz <= ($z >> 4) + 2; $cz++) $world->loadChunk($cx, $cz);
            }
            $loc = fn(float $dx = 0, float $dz = 0) => new Location($x + $dx, 90, $z + $dz, $world, 0, 0);
            $plugin->getConfig()->set('combat-damage', ['zombie' => 9, 'phantom' => 8, 'slime' => 6, 'magmacube' => 7]);
            foreach ([Zombie::class => 9, Phantom::class => 8, Slime::class => 6, MagmaCube::class => 7] as $class => $expected) {
                $mob = new $class($loc());
                if ($mob->getAttackDamage() !== (float) $expected) throw new \RuntimeException("Damage override failed for $class");
                $mob->close();
            }
            $checks[] = 'configurable melee damage';

            $rareMob = new Zombie($loc());
            $rareMob->setNaturallySpawned(true);
            $rareMob->setPersistenceRequired(true);
            $rareMob->setRareEncounter(true, 2.0);
            $rareMob->setRareBonusDrop(['item' => 'emerald', 'amount' => 2, 'chance' => 0.5]);
            $rareMob->setMaxHealth(40);
            $rareMob->setHealth(35);
            $restoredRareMob = new Zombie($loc(), $rareMob->saveNBT());
            if (!$restoredRareMob->isNaturallySpawned() || !$restoredRareMob->isPersistenceRequired() || !$restoredRareMob->isRareEncounter() ||
                $restoredRareMob->getMaxHealth() !== 40 || $restoredRareMob->getHealth() !== 35.0 || $restoredRareMob->getAttackDamage() !== 18.0 ||
                $restoredRareMob->getRareBonusDrop() !== ['item' => 'emerald', 'amount' => 2, 'chance' => 0.5]) {
                throw new \RuntimeException('Natural, persistent, or rare mob NBT state did not round-trip');
            }
            $restoredRareMob->close();
            $rareMob->close();
            $checks[] = 'rare encounter modifiers and entity NBT persistence';

            $creeper = new Creeper($loc());
            $creeper->onUpdate($server->getTick() + 1);
            $creeper->close();
            $checks[] = 'creeper construction and update';

            $villager = new Villager($loc());
            for ($profession = 1; $profession <= 13; $profession++) {
                $villager->setProfession($profession);
                if ($villager->getTradeRecipes() === []) throw new \RuntimeException("No villager recipes for profession $profession");
            }
            $villager->close();
            $checks[] = 'villager profession and recipes';

            foreach (['cod_bucket', 'salmon_bucket', 'pufferfish_bucket', 'tropical_fish_bucket', 'axolotl_bucket', 'tadpole_bucket'] as $id) {
                if (!(StringToItemParser::getInstance()->parse($id) instanceof FishBucket)) throw new \RuntimeException("Missing fish bucket: $id");
            }
            $fish = new Cod($loc());
            $fish->onUpdate($server->getTick() + 1);
            $player = new SmokePlayer($world);
            $player->getInventory()->setItemInHand(VanillaItems::WATER_BUCKET());
            if (!$fish->onInteract($player, new Vector3(0, 0, 0)) || !$fish->isClosed()) {
                throw new \RuntimeException('Fish capture did not close fish');
            }
            $codBucket = null;
            foreach ($player->getInventory()->getContents() as $item) {
                if ($item instanceof FishBucket) $codBucket = $item;
            }
            if ($codBucket === null) throw new \RuntimeException('Fish capture did not deliver bucket');
            $world->setBlock($world->getBlockAt($x, 90, $z + 6)->getPosition(), VanillaBlocks::AIR());
            $replace = $world->getBlockAt($x, 90, $z + 6);
            $returned = [];
            $this->cancelNextBucket = true;
            $cancelResult = $codBucket->onInteractBlock($player, $replace, $replace, 1, new Vector3(0, 0, 0), $returned);
            $this->cancelNextBucket = false;
            if ($cancelResult !== ItemUseResult::FAIL || $returned !== [] ||
                !($world->getBlockAt($x, 90, $z + 6) instanceof \pocketmine\block\Air)) {
                throw new \RuntimeException('Cancelled fish bucket changed the world');
            }
            $result = $codBucket->onInteractBlock($player, $replace, $replace, 1, new Vector3(0, 0, 0), $returned);
            if ($result !== ItemUseResult::SUCCESS || count($returned) !== 1 ||
                $returned[0]->getTypeId() !== VanillaItems::BUCKET()->getTypeId() ||
                !($world->getBlockAt($x, 90, $z + 6) instanceof \pocketmine\block\Water)) {
                throw new \RuntimeException('Fish bucket release failed');
            }
            $checks[] = 'fish bucket variants, capture, and release';

            $golem = new SnowGolem($loc());
            $far = new Zombie($loc(20));
            $ai = new \ReflectionMethod($golem, 'calculateAI');
            $before = count(array_filter($world->getEntities(), fn($entity) => $entity instanceof Snowball));
            $ai->invoke($golem);
            $after = count(array_filter($world->getEntities(), fn($entity) => $entity instanceof Snowball));
            if ($after !== $before) throw new \RuntimeException('Snow golem attacked beyond 12 blocks');
            $far->close();
            $near = new Zombie($loc(3));
            $ai->invoke($golem);
            $after = count(array_filter($world->getEntities(), fn($entity) => $entity instanceof Snowball));
            if ($after <= $before) throw new \RuntimeException('Snow golem did not fire at near hostile mob');
            $near->close();
            $golem->close();
            $checks[] = 'snow golem targeting and projectile';

            $water = $loc(0, 3);
            $world->setBlock($water, VanillaBlocks::WATER());
            $world->setBlock($water->add(0, 1, 0), VanillaBlocks::WATER());
            $zombie = new Zombie($water);
            $zombie->setNaturallySpawned(true);
            $zombie->setPersistenceRequired(true);
            $zombie->setRareEncounter(true, 1.5);
            $zombie->setRareBonusDrop(['item' => 'emerald', 'amount' => 2, 'chance' => 0.5]);
            $zombie->setMaxHealth(30);
            $zombie->setHealth(25);
            (new \ReflectionProperty($zombie, 'conversionTicks'))->setValue($zombie, 599);
            $zombie->onUpdate($server->getTick() + 1);
            $drowned = array_values(array_filter($world->getEntities(), fn($entity) => $entity instanceof Drowned && !$entity->isClosed()));
            if (count($drowned) !== 1 || $drowned[0]->getMaxHealth() !== 30 || $drowned[0]->getHealth() !== 25.0 ||
                !$drowned[0]->isNaturallySpawned() || !$drowned[0]->isPersistenceRequired() || !$drowned[0]->isRareEncounter() ||
                $drowned[0]->getRareDamageMultiplier() !== 1.5 || $drowned[0]->getRareBonusDrop() !== ['item' => 'emerald', 'amount' => 2, 'chance' => 0.5]) {
                throw new \RuntimeException('Zombie conversion lost health or rare encounter state');
            }
            $drowned[0]->close();
            $world->setBlock($water, VanillaBlocks::AIR());
            $world->setBlock($water->add(0, 1, 0), VanillaBlocks::AIR());
            $checks[] = 'zombie to drowned conversion';

            file_put_contents($this->getDataFolder() . 'smoke-result.json', json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR));
            $this->getLogger()->info('PASS ' . implode(', ', $checks));
        } catch (\Throwable $error) {
            file_put_contents($this->getDataFolder() . 'smoke-result.json', json_encode(['ok' => false, 'checks' => $checks, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR));
            $this->getLogger()->logException($error);
        }
        $this->getServer()->shutdown();
    }
}
