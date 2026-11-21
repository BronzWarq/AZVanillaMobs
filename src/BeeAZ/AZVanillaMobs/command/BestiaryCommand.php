<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\command;

use BeeAZ\AZVanillaMobs\Main;
use BeeAZ\AZVanillaMobs\utils\SurvivalDataStore;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

final class BestiaryCommand extends Command implements PluginOwned
{
    private const DEFAULT_GOALS = [
        'zombie_hunter' => ['title' => 'Zombie Hunter', 'mob' => 'zombie', 'target' => 25],
        'skeleton_hunter' => ['title' => 'Skeleton Hunter', 'mob' => 'skeleton', 'target' => 25],
        'desert_hunter' => ['title' => 'Desert Hunter', 'mob' => 'husk', 'biome' => 'desert', 'target' => 10],
        'rare_hunter' => ['title' => 'Rare Hunter', 'mob' => '*', 'target' => 3, 'rare' => true],
    ];

    use PluginOwnedTrait {
        PluginOwnedTrait::__construct as private __traitConstruct;
    }

    public function __construct(private readonly Main $plugin)
    {
        $this->__traitConstruct($plugin);
        parent::__construct('azbestiary', 'View your AZVanillaMobs bestiary and hunt goals', '/azbestiary [goals|claim <goal>]', ['azhunt']);
        $this->setPermission('azvanillamobs.command.bestiary');
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return false;
        }
        if (!$sender instanceof Player) {
            $sender->sendMessage('§cThis command can only be used in game.');
            return true;
        }

        $store = $this->plugin->getSurvivalDataStore();
        if ($store === null || !$store->isAvailable()) {
            $sender->sendMessage('§cThe bestiary is unavailable because persistent storage could not be opened.');
            return true;
        }
        $bestiary = $this->plugin->getConfig()->get('bestiary', []);
        $goals = is_array($bestiary) && is_array($bestiary['goals'] ?? null) ? $bestiary['goals'] : self::DEFAULT_GOALS;
        if (is_array($bestiary) && ($bestiary['enabled'] ?? true) === false) {
            $sender->sendMessage('§eThe bestiary is disabled in the server configuration.');
            return true;
        }

        $action = strtolower((string)($args[0] ?? ''));
        if ($action === 'claim') {
            $this->claimGoal($sender, $store, $goals, (string)($args[1] ?? ''));
            return true;
        }
        if ($action === 'goals') {
            $this->showGoals($sender, $store, $goals);
            return true;
        }
        if ($action !== '') {
            $sender->sendMessage('§eUsage: /azbestiary [goals|claim <goal>]');
            return false;
        }

        $uuid = $sender->getUniqueId()->toString();
        $summary = $store->getKillSummary($uuid);
        $totals = $store->getKillTotals($uuid);
        $sender->sendMessage('§6Your AZVanillaMobs bestiary');
        $sender->sendMessage('§7Recorded kills: §f' . $totals['kills'] . ' §7| Rare kills: §f' . $totals['rare'] . ' §7| Species: §f' . $totals['species']);
        if ($summary === []) {
            $sender->sendMessage('§7No eligible kills recorded yet.');
        } else {
            foreach ($summary as $row) {
                $sender->sendMessage('§f' . ucfirst($row['mob']) . '§7: ' . $row['kills'] . ($row['rare'] > 0 ? ' §6(' . $row['rare'] . ' rare)' : ''));
            }
        }
        $biomes = $store->getBiomeSummary($uuid);
        if ($biomes !== []) {
            $habitats = array_map(static fn(array $row): string => $row['biome'] . ' (' . $row['kills'] . ')', $biomes);
            $sender->sendMessage('§7Top habitats: §f' . implode(', ', $habitats));
        }
        $sender->sendMessage('§7Use §f/azbestiary goals §7to view hunt milestones.');
        return true;
    }

    /** @param array<string, mixed> $goals */
    private function showGoals(Player $player, SurvivalDataStore $store, array $goals): void
    {
        $player->sendMessage('§6Hunt milestones');
        if ($goals === []) {
            $player->sendMessage('§7No hunt goals are configured.');
            return;
        }
        foreach ($goals as $id => $definition) {
            if (!is_string($id) || !is_array($definition)) {
                continue;
            }
            $target = is_numeric($definition['target'] ?? null) ? max(1, (int)$definition['target']) : 1;
            $mob = is_string($definition['mob'] ?? null) ? SurvivalDataStore::normalizeMobId($definition['mob']) : '*';
            $biome = is_string($definition['biome'] ?? null) ? $definition['biome'] : '*';
            $rareOnly = ($definition['rare'] ?? false) === true;
            $progress = min($target, $store->getKillCount($player->getUniqueId()->toString(), $mob, $rareOnly, $biome));
            $claimed = $store->isGoalClaimed($player->getUniqueId()->toString(), $id);
            $title = is_string($definition['title'] ?? null) ? $definition['title'] : $id;
            $state = $claimed ? '§aearned' : ($progress >= $target ? '§eclaim ready' : '§7in progress');
            $player->sendMessage('§f' . $id . ' §7— ' . $title . ': §f' . $progress . '/' . $target . ' §7(' . $state . '§7)');
        }
        $player->sendMessage('§7Claim a completed badge with §f/azbestiary claim <goal>§7.');
    }

    /** @param array<string, mixed> $goals */
    private function claimGoal(Player $player, SurvivalDataStore $store, array $goals, string $goalId): void
    {
        if ($goalId === '' || !preg_match('/^[a-z0-9_-]{1,48}$/i', $goalId) || !is_array($goals[$goalId] ?? null)) {
            $player->sendMessage('§cUnknown goal. View available goals with /azbestiary goals.');
            return;
        }
        $definition = $goals[$goalId];
        $target = is_numeric($definition['target'] ?? null) ? max(1, (int)$definition['target']) : 1;
        $mob = is_string($definition['mob'] ?? null) ? SurvivalDataStore::normalizeMobId($definition['mob']) : '*';
        $biome = is_string($definition['biome'] ?? null) ? $definition['biome'] : '*';
        $rareOnly = ($definition['rare'] ?? false) === true;
        $uuid = $player->getUniqueId()->toString();
        $progress = $store->getKillCount($uuid, $mob, $rareOnly, $biome);
        if ($store->isGoalClaimed($uuid, $goalId)) {
            $player->sendMessage('§eThis hunt badge has already been claimed.');
            return;
        }
        if ($progress < $target) {
            $player->sendMessage('§eProgress: ' . min($progress, $target) . '/' . $target . '.');
            return;
        }
        if (!$store->claimGoal($uuid, $goalId)) {
            $player->sendMessage('§eThis hunt badge has already been claimed.');
            return;
        }
        $title = is_string($definition['title'] ?? null) ? $definition['title'] : $goalId;
        $player->sendMessage('§aHunt badge earned: §f' . $title);
    }
}
