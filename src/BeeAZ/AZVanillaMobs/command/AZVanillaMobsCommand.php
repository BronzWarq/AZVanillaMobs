<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\command;

use BeeAZ\AZVanillaMobs\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

final class AZVanillaMobsCommand extends Command implements PluginOwned
{
    use PluginOwnedTrait {
        PluginOwnedTrait::__construct as private __traitConstruct;
    }

    public function __construct(private Main $plugin)
    {
        $this->__traitConstruct($plugin);
        parent::__construct('azm', 'AZVanillaMobs administration', '/azm <status|reload>', ['azvanillamobs']);
        $this->setPermission('azvanillamobs.command.admin');
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }
        $subcommand = strtolower((string)($args[0] ?? 'status'));
        if ($subcommand === 'reload') {
            $this->plugin->reloadConfig();
            $this->plugin->validateConfiguration();
            $sender->sendMessage('§aAZVanillaMobs configuration reloaded. Check the server log for configuration warnings.');
            return true;
        }
        if ($subcommand !== 'status') {
            $sender->sendMessage('§eUsage: /azm <status|reload>');
            return false;
        }

        $status = $this->plugin->getSpawnerStatus();
        if ($status === []) {
            $sender->sendMessage('§eSpawner status is not available yet.');
            return true;
        }
        $worldCounts = $status['worlds'] ?? [];
        $worldSummary = [];
        foreach (is_array($worldCounts) ? $worldCounts : [] as $world => $count) {
            $worldSummary[] = (string)$world . ': ' . (int)$count;
        }
        $sender->sendMessage('§6AZVanillaMobs status');
        $sender->sendMessage('§7Mobs: §f' . (int)($status['total'] ?? 0) . ' §7| Attempts: §f' . (int)($status['attempts'] ?? 0) . ' §7| Spawned: §f' . (int)($status['spawned'] ?? 0) . ' §7| Rejected: §f' . (int)($status['failed'] ?? 0));
        $sender->sendMessage('§7Worlds: §f' . ($worldSummary === [] ? 'none scanned yet' : implode(', ', $worldSummary)));
        $sender->sendMessage('§7Rare: §f' . array_sum(is_array($status['rare'] ?? null) ? $status['rare'] : []) . ' §7| Natural despawns: §f' . (int)($status['despawned'] ?? 0) . ' §7| Last cycle: §f' . (float)($status['last-cycle-ms'] ?? 0.0) . ' ms');

        $rejections = is_array($status['rejections'] ?? null) ? $status['rejections'] : [];
        arsort($rejections);
        $rejections = array_slice($rejections, 0, 5, true);
        if ($rejections !== []) {
            $summary = [];
            foreach ($rejections as $reason => $count) {
                $summary[] = $reason . ': ' . (int)$count;
            }
            $sender->sendMessage('§7Top spawn rejection reasons: §f' . implode(', ', $summary));
        }
        return true;
    }
}
