<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\utils;

use BeeAZ\AZVanillaMobs\Main;
use pocketmine\event\Listener;
use pocketmine\event\EventPriority;
use pocketmine\event\handler\EventHandler;
use pocketmine\event\player\PlayerBedEnterEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\world\World;

final class SleepTracker implements Listener
{
    /** @var array<string, array<string, int>> UUID => world folder => total world ticks */
    private array $lastSleepOrBaseline = [];
    private string $filePath;

    public function __construct(private Main $plugin)
    {
        $this->filePath = $plugin->getDataFolder() . 'sleep-data.json';
        $this->load();
    }

    #[EventHandler(priority: EventPriority::MONITOR)]
    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();
        $world = $player->getWorld();
        if (!isset($this->lastSleepOrBaseline[$uuid][$world->getFolderName()])) {
            $this->record($player, $world);
        }
    }

    #[EventHandler(priority: EventPriority::MONITOR)]
    public function onPlayerBedEnter(PlayerBedEnterEvent $event): void
    {
        if ($event->isCancelled()) {
            return;
        }
        $player = $event->getPlayer();
        $this->record($player, $player->getWorld());
    }

    public function isEligible(Player $player, World $world, int $requiredNights): bool
    {
        $uuid = $player->getUniqueId()->toString();
        $worldName = $world->getFolderName();
        $now = $world->getTime();
        $last = $this->lastSleepOrBaseline[$uuid][$worldName] ?? null;
        if (!is_int($last)) {
            $this->lastSleepOrBaseline[$uuid][$worldName] = $now;
            $this->save();
            return false;
        }
        if ($now < $last) {
            $this->lastSleepOrBaseline[$uuid][$worldName] = $now;
            $this->save();
            return false;
        }
        return ($now - $last) >= max(1, min(14, $requiredNights)) * 24000;
    }

    private function record(Player $player, World $world): void
    {
        $this->lastSleepOrBaseline[$player->getUniqueId()->toString()][$world->getFolderName()] = $world->getTime();
        $this->save();
    }

    private function load(): void
    {
        if (!is_file($this->filePath)) {
            return;
        }
        $decoded = json_decode((string)file_get_contents($this->filePath), true);
        if (!is_array($decoded)) {
            $this->plugin->getLogger()->warning('Sleep history file is invalid; starting with empty player history.');
            return;
        }
        foreach ($decoded as $uuid => $worlds) {
            if (!is_string($uuid) || !is_array($worlds)) {
                continue;
            }
            foreach ($worlds as $world => $time) {
                if (is_string($world) && is_numeric($time) && (int)$time >= 0) {
                    $this->lastSleepOrBaseline[$uuid][$world] = (int)$time;
                }
            }
        }
    }

    private function save(): void
    {
        $json = json_encode($this->lastSleepOrBaseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->plugin->getLogger()->warning('Could not encode player sleep history.');
            return;
        }
        $temporaryPath = $this->filePath . '.tmp';
        if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporaryPath, $this->filePath)) {
            @unlink($temporaryPath);
            $this->plugin->getLogger()->warning('Could not save player sleep history.');
        }
    }
}
