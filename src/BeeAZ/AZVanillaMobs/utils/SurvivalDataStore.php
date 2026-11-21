<?php

declare(strict_types=1);

namespace BeeAZ\AZVanillaMobs\utils;

use BeeAZ\AZVanillaMobs\Main;

/** Persistent bestiary, hunt claims and bounded spawn telemetry. */
final class SurvivalDataStore
{
    private mixed $database = null;
    private bool $reportedFailure = false;
    /** @var array<string, array{attempts:int, spawned:int, rejected:int, despawned:int, cycles:int, cycle_ms_sum:float, cycle_ms_max:float, rejections:array<string,int>}> */
    private array $pendingMetrics = [];
    private int $lastMetricsFlush;

    public function __construct(private readonly Main $plugin)
    {
        $this->lastMetricsFlush = time();
        if (!class_exists(\SQLite3::class)) {
            $this->reportFailure('PHP SQLite3 is unavailable; persistent bestiary and spawn telemetry are disabled.');
            return;
        }

        try {
            $path = $plugin->getDataFolder() . 'survival.sqlite3';
            $this->database = new \SQLite3($path, \SQLITE3_OPEN_READWRITE | \SQLITE3_OPEN_CREATE);
            $this->database->busyTimeout(2000);
            $schema = [
                'PRAGMA journal_mode=WAL',
                'PRAGMA synchronous=NORMAL',
                'CREATE TABLE IF NOT EXISTS bestiary_kills (
                player_uuid TEXT NOT NULL,
                player_name TEXT NOT NULL,
                mob_id TEXT NOT NULL,
                biome TEXT NOT NULL,
                dimension TEXT NOT NULL,
                kills INTEGER NOT NULL DEFAULT 0,
                rare_kills INTEGER NOT NULL DEFAULT 0,
                last_killed INTEGER NOT NULL,
                PRIMARY KEY(player_uuid, mob_id, biome, dimension)
            )',
                'CREATE TABLE IF NOT EXISTS hunt_claims (
                player_uuid TEXT NOT NULL,
                goal_id TEXT NOT NULL,
                claimed_at INTEGER NOT NULL,
                PRIMARY KEY(player_uuid, goal_id)
            )',
                'CREATE TABLE IF NOT EXISTS spawn_metrics (
                world TEXT NOT NULL,
                minute_bucket INTEGER NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                spawned INTEGER NOT NULL DEFAULT 0,
                rejected INTEGER NOT NULL DEFAULT 0,
                despawned INTEGER NOT NULL DEFAULT 0,
                cycles INTEGER NOT NULL DEFAULT 0,
                cycle_ms_sum REAL NOT NULL DEFAULT 0,
                cycle_ms_max REAL NOT NULL DEFAULT 0,
                rejections TEXT NOT NULL,
                PRIMARY KEY(world, minute_bucket)
            )',
                'CREATE INDEX IF NOT EXISTS bestiary_player_idx ON bestiary_kills(player_uuid)',
                'DELETE FROM spawn_metrics WHERE minute_bucket < ' . (time() - 30 * 86400),
            ];
            foreach ($schema as $sql) {
                if (!$this->database->exec($sql)) {
                    throw new \RuntimeException($this->database->lastErrorMsg());
                }
            }
        } catch (\Throwable $error) {
            $this->database = null;
            $this->reportFailure('Could not open survival data database: ' . $error->getMessage());
        }
    }

    public function isAvailable(): bool
    {
        return $this->database instanceof \SQLite3;
    }

    public function recordMobKill(string $uuid, string $playerName, string $mobId, string $biome, string $dimension, bool $rare): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $statement = $this->database->prepare('INSERT INTO bestiary_kills(player_uuid, player_name, mob_id, biome, dimension, kills, rare_kills, last_killed)
            VALUES(:uuid, :name, :mob, :biome, :dimension, 1, :rare, :now)
            ON CONFLICT(player_uuid, mob_id, biome, dimension) DO UPDATE SET
                player_name = excluded.player_name,
                kills = kills + 1,
                rare_kills = rare_kills + excluded.rare_kills,
                last_killed = excluded.last_killed');
        if ($statement === false) {
            $this->reportFailure('Could not prepare bestiary record.');
            return;
        }
        $statement->bindValue(':uuid', strtolower($uuid), SQLITE3_TEXT);
        $statement->bindValue(':name', $playerName, SQLITE3_TEXT);
        $statement->bindValue(':mob', self::normalizeMobId($mobId), SQLITE3_TEXT);
        $statement->bindValue(':biome', self::normalizeBiome($biome), SQLITE3_TEXT);
        $statement->bindValue(':dimension', $dimension, SQLITE3_TEXT);
        $statement->bindValue(':rare', $rare ? 1 : 0, SQLITE3_INTEGER);
        $statement->bindValue(':now', time(), SQLITE3_INTEGER);
        $result = $statement->execute();
        if ($result instanceof \SQLite3Result) {
            $result->finalize();
        }
        $statement->close();
        if ($result === false) {
            $this->reportFailure('Could not save a bestiary kill.');
        }
    }

    /** @return list<array{mob:string, kills:int, rare:int}> */
    public function getKillSummary(string $uuid): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $statement = $this->database->prepare('SELECT mob_id, SUM(kills) AS kills, SUM(rare_kills) AS rare
            FROM bestiary_kills WHERE player_uuid = :uuid GROUP BY mob_id ORDER BY kills DESC, mob_id ASC LIMIT 12');
        if ($statement === false) {
            return [];
        }
        $statement->bindValue(':uuid', strtolower($uuid), SQLITE3_TEXT);
        $result = $statement->execute();
        $rows = [];
        if ($result !== false) {
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $rows[] = ['mob' => (string)$row['mob_id'], 'kills' => (int)$row['kills'], 'rare' => (int)$row['rare']];
            }
            $result->finalize();
        }
        $statement->close();
        return $rows;
    }

    /** @return array{kills:int, rare:int, species:int} */
    public function getKillTotals(string $uuid): array
    {
        $totals = ['kills' => 0, 'rare' => 0, 'species' => 0];
        if (!$this->isAvailable()) {
            return $totals;
        }
        $statement = $this->database->prepare('SELECT COALESCE(SUM(kills), 0) AS kills, COALESCE(SUM(rare_kills), 0) AS rare, COUNT(DISTINCT mob_id) AS species FROM bestiary_kills WHERE player_uuid = :uuid');
        if ($statement === false) {
            return $totals;
        }
        $statement->bindValue(':uuid', strtolower($uuid), SQLITE3_TEXT);
        $result = $statement->execute();
        $row = $result instanceof \SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : false;
        if (is_array($row)) {
            $totals = ['kills' => (int)$row['kills'], 'rare' => (int)$row['rare'], 'species' => (int)$row['species']];
        }
        if ($result instanceof \SQLite3Result) {
            $result->finalize();
        }
        $statement->close();
        return $totals;
    }

    /** @return list<array{biome:string, kills:int}> */
    public function getBiomeSummary(string $uuid): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $statement = $this->database->prepare('SELECT biome, SUM(kills) AS kills FROM bestiary_kills
            WHERE player_uuid = :uuid GROUP BY biome ORDER BY kills DESC LIMIT 5');
        if ($statement === false) {
            return [];
        }
        $statement->bindValue(':uuid', strtolower($uuid), SQLITE3_TEXT);
        $result = $statement->execute();
        $rows = [];
        if ($result !== false) {
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $rows[] = ['biome' => (string)$row['biome'], 'kills' => (int)$row['kills']];
            }
            $result->finalize();
        }
        $statement->close();
        return $rows;
    }

    public function getKillCount(string $uuid, string $mobId, bool $rareOnly = false, string $biome = '*'): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $column = $rareOnly ? 'rare_kills' : 'kills';
        $sql = 'SELECT COALESCE(SUM(' . $column . '), 0) AS total FROM bestiary_kills WHERE player_uuid = :uuid';
        if ($mobId !== '*') {
            $sql .= ' AND mob_id = :mob';
        }
        if ($biome !== '*') {
            $sql .= ' AND biome = :biome';
        }
        $statement = $this->database->prepare($sql);
        if ($statement === false) {
            return 0;
        }
        $statement->bindValue(':uuid', strtolower($uuid), SQLITE3_TEXT);
        if ($mobId !== '*') {
            $statement->bindValue(':mob', self::normalizeMobId($mobId), SQLITE3_TEXT);
        }
        if ($biome !== '*') {
            $statement->bindValue(':biome', self::normalizeBiome($biome), SQLITE3_TEXT);
        }
        $result = $statement->execute();
        $row = $result !== false ? $result->fetchArray(SQLITE3_ASSOC) : false;
        $count = is_array($row) ? (int)$row['total'] : 0;
        if ($result instanceof \SQLite3Result) {
            $result->finalize();
        }
        $statement->close();
        return $count;
    }

    public function isGoalClaimed(string $uuid, string $goalId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $statement = $this->database->prepare('SELECT 1 FROM hunt_claims WHERE player_uuid = :uuid AND goal_id = :goal');
        if ($statement === false) {
            return false;
        }
        $statement->bindValue(':uuid', strtolower($uuid), SQLITE3_TEXT);
        $statement->bindValue(':goal', $goalId, SQLITE3_TEXT);
        $result = $statement->execute();
        $found = $result !== false && $result->fetchArray(SQLITE3_NUM) !== false;
        if ($result instanceof \SQLite3Result) {
            $result->finalize();
        }
        $statement->close();
        return $found;
    }

    public function claimGoal(string $uuid, string $goalId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $alreadyClaimed = $this->isGoalClaimed($uuid, $goalId);
        $statement = $this->database->prepare('INSERT OR IGNORE INTO hunt_claims(player_uuid, goal_id, claimed_at) VALUES(:uuid, :goal, :now)');
        if ($statement === false) {
            return false;
        }
        $statement->bindValue(':uuid', strtolower($uuid), SQLITE3_TEXT);
        $statement->bindValue(':goal', $goalId, SQLITE3_TEXT);
        $statement->bindValue(':now', time(), SQLITE3_INTEGER);
        $result = $statement->execute();
        if ($result instanceof \SQLite3Result) {
            $result->finalize();
        }
        $statement->close();
        return !$alreadyClaimed && $result !== false && $this->isGoalClaimed($uuid, $goalId);
    }

    /** @param array<string, int> $rejections */
    public function recordSpawnCycle(string $world, int $attempts, int $spawned, int $despawned, array $rejections, float $cycleMilliseconds): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $metrics = $this->pendingMetrics[$world] ?? [
            'attempts' => 0, 'spawned' => 0, 'rejected' => 0, 'despawned' => 0, 'cycles' => 0,
            'cycle_ms_sum' => 0.0, 'cycle_ms_max' => 0.0, 'rejections' => [],
        ];
        $rejected = array_sum($rejections);
        $metrics['attempts'] += max(0, $attempts);
        $metrics['spawned'] += max(0, $spawned);
        $metrics['rejected'] += $rejected;
        $metrics['despawned'] += max(0, $despawned);
        ++$metrics['cycles'];
        $metrics['cycle_ms_sum'] += max(0.0, $cycleMilliseconds);
        $metrics['cycle_ms_max'] = max($metrics['cycle_ms_max'], $cycleMilliseconds);
        foreach ($rejections as $reason => $count) {
            $metrics['rejections'][$reason] = ($metrics['rejections'][$reason] ?? 0) + max(0, $count);
        }
        $this->pendingMetrics[$world] = $metrics;
        if (time() - $this->lastMetricsFlush >= 60) {
            $this->flushSpawnMetrics();
        }
    }

    public function flushSpawnMetrics(): void
    {
        if (!$this->isAvailable() || $this->pendingMetrics === []) {
            $this->lastMetricsFlush = time();
            return;
        }
        $bucket = intdiv(time(), 60) * 60;
        foreach ($this->pendingMetrics as $world => $metrics) {
            $existingRejections = [];
            $query = $this->database->prepare('SELECT rejections FROM spawn_metrics WHERE world = :world AND minute_bucket = :bucket');
            if ($query !== false) {
                $query->bindValue(':world', $world, SQLITE3_TEXT);
                $query->bindValue(':bucket', $bucket, SQLITE3_INTEGER);
                $result = $query->execute();
                $row = $result !== false ? $result->fetchArray(SQLITE3_ASSOC) : false;
                if (is_array($row)) {
                    $decoded = json_decode((string)$row['rejections'], true);
                    $existingRejections = is_array($decoded) ? $decoded : [];
                }
                if ($result instanceof \SQLite3Result) {
                    $result->finalize();
                }
                $query->close();
            }
            foreach ($metrics['rejections'] as $reason => $count) {
                $existingRejections[$reason] = (int)($existingRejections[$reason] ?? 0) + $count;
            }
            $statement = $this->database->prepare('INSERT INTO spawn_metrics(world, minute_bucket, attempts, spawned, rejected, despawned, cycles, cycle_ms_sum, cycle_ms_max, rejections)
                VALUES(:world, :bucket, :attempts, :spawned, :rejected, :despawned, :cycles, :ms_sum, :ms_max, :rejections)
                ON CONFLICT(world, minute_bucket) DO UPDATE SET
                    attempts = attempts + excluded.attempts,
                    spawned = spawned + excluded.spawned,
                    rejected = rejected + excluded.rejected,
                    despawned = despawned + excluded.despawned,
                    cycles = cycles + excluded.cycles,
                    cycle_ms_sum = cycle_ms_sum + excluded.cycle_ms_sum,
                    cycle_ms_max = MAX(cycle_ms_max, excluded.cycle_ms_max),
                    rejections = excluded.rejections');
            if ($statement === false) {
                $this->reportFailure('Could not prepare spawn telemetry update.');
                continue;
            }
            $statement->bindValue(':world', $world, SQLITE3_TEXT);
            $statement->bindValue(':bucket', $bucket, SQLITE3_INTEGER);
            $statement->bindValue(':attempts', $metrics['attempts'], SQLITE3_INTEGER);
            $statement->bindValue(':spawned', $metrics['spawned'], SQLITE3_INTEGER);
            $statement->bindValue(':rejected', $metrics['rejected'], SQLITE3_INTEGER);
            $statement->bindValue(':despawned', $metrics['despawned'], SQLITE3_INTEGER);
            $statement->bindValue(':cycles', $metrics['cycles'], SQLITE3_INTEGER);
            $statement->bindValue(':ms_sum', $metrics['cycle_ms_sum'], SQLITE3_FLOAT);
            $statement->bindValue(':ms_max', $metrics['cycle_ms_max'], SQLITE3_FLOAT);
            $statement->bindValue(':rejections', json_encode($existingRejections, JSON_UNESCAPED_SLASHES) ?: '{}', SQLITE3_TEXT);
            $result = $statement->execute();
            if ($result === false) {
                $this->reportFailure('Could not save spawn telemetry.');
            } else {
                $result->finalize();
                unset($this->pendingMetrics[$world]);
            }
            $statement->close();
        }
        $this->lastMetricsFlush = time();
        if (time() % 3600 < 60) {
            $this->database->exec('DELETE FROM spawn_metrics WHERE minute_bucket < ' . (time() - 30 * 86400));
        }
    }

    /** @return array{attempts:int, spawned:int, rejected:int, despawned:int, cycles:int, average_ms:float, max_ms:float, rejections:array<string,int>} */
    public function getSpawnTelemetrySummary(?string $world = null, int $windowSeconds = 86400): array
    {
        $summary = ['attempts' => 0, 'spawned' => 0, 'rejected' => 0, 'despawned' => 0, 'cycles' => 0, 'average_ms' => 0.0, 'max_ms' => 0.0, 'rejections' => []];
        if (!$this->isAvailable()) {
            return $summary;
        }
        $this->flushSpawnMetrics();
        $sql = 'SELECT attempts, spawned, rejected, despawned, cycles, cycle_ms_sum, cycle_ms_max, rejections FROM spawn_metrics WHERE minute_bucket >= :since';
        if ($world !== null) {
            $sql .= ' AND world = :world';
        }
        $statement = $this->database->prepare($sql);
        if ($statement === false) {
            return $summary;
        }
        $statement->bindValue(':since', time() - max(60, $windowSeconds), SQLITE3_INTEGER);
        if ($world !== null) {
            $statement->bindValue(':world', $world, SQLITE3_TEXT);
        }
        $result = $statement->execute();
        $cycleMilliseconds = 0.0;
        if ($result !== false) {
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                foreach (['attempts', 'spawned', 'rejected', 'despawned', 'cycles'] as $field) {
                    $summary[$field] += (int)$row[$field];
                }
                $cycleMilliseconds += (float)$row['cycle_ms_sum'];
                $summary['max_ms'] = max($summary['max_ms'], (float)$row['cycle_ms_max']);
                $rejections = json_decode((string)$row['rejections'], true);
                if (is_array($rejections)) {
                    foreach ($rejections as $reason => $count) {
                        if (is_string($reason) && is_numeric($count)) {
                            $summary['rejections'][$reason] = ($summary['rejections'][$reason] ?? 0) + (int)$count;
                        }
                    }
                }
            }
            $result->finalize();
        }
        $statement->close();
        $summary['average_ms'] = $summary['cycles'] > 0 ? round($cycleMilliseconds / $summary['cycles'], 2) : 0.0;
        arsort($summary['rejections']);
        return $summary;
    }

    public function close(): void
    {
        $this->flushSpawnMetrics();
        if ($this->isAvailable()) {
            $this->database->close();
            $this->database = null;
        }
    }

    public static function normalizeMobId(string $mobId): string
    {
        $mobId = strtolower(trim($mobId));
        return preg_replace('/^minecraft:/', '', $mobId) ?? $mobId;
    }

    private static function normalizeBiome(string $biome): string
    {
        $normalized = strtolower(trim($biome));
        $normalized = preg_replace('/^minecraft[:_]/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9*?]+/', '_', $normalized) ?? '';
        return trim($normalized, '_');
    }

    private function reportFailure(string $message): void
    {
        if (!$this->reportedFailure) {
            $this->plugin->getLogger()->warning($message);
            $this->reportedFailure = true;
        }
    }
}
