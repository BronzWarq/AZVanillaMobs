# AZVanillaMobs on a survival server

AZVanillaMobs supplies custom vanilla-style mobs and natural spawning for PocketMine-MP. Its economy is not tied to any one plugin. RPGMoney Bedrock can optionally reward player kills through its regular mob reward rules.

## World profiles

Use a profile per world to tune spawn density without copying every setting. Old configuration entries such as `"world": "overworld"` still work and use the default profile.

```yaml
worlds:
  "world": {dimension: "overworld", profile: "survival"}
  "resource": {dimension: "overworld", profile: "sparse"}
  "nether": {dimension: "nether", profile: "survival"}
  "the_end": {dimension: "the_end", profile: "survival"}

spawn-profiles:
  survival:
    interval-seconds: 5
    attempts-per-player: 1
    max-attempts-per-cycle: 40
    min-player-distance: 24
    max-player-distance: 48
    per-world-mob-cap: 70
    natural-hostile-despawn-distance: 128
    categories: [hostile, passive, aquatic]
    mob-weights:
      zombie: 10
      skeleton: 10
      creeper: 6
      phantom: 2
    mob-biomes:
      husk: [desert, "badlands*"]
      camel: [desert]
    mob-conditions:
      skeleton:
        time: [dusk, night]
        max-light: 7
        min-y: 50
        ground-blocks: [stone, deepslate]
    rare-encounters:
      enabled: false
      chance: 0.01
      max-per-world: 2
      health-multiplier: 1.5
      damage-multiplier: 1.15
      visible-name: true
      bonus-drop:
        item: diamond
        amount: 1
        chance: 0.1
  sparse:
    interval-seconds: 8
    attempts-per-player: 1
    max-attempts-per-cycle: 20
    per-world-mob-cap: 35
    categories: [hostile, passive, aquatic]
```

The global cap still limits all configured worlds together. Each world profile has its own cap. Per-mob limits in `entity-type-limits` remain available. A weight of `0` disables that mob in the profile; omitted weights use the profile defaults. Biome patterns use normalized biome names and support `*` and `?` wildcards. `categories` can contain `hostile`, `passive`, and `aquatic`.

Spawn attempts are limited per cycle and use already generated, loaded chunks, so this task does not load distant chunks while searching for spawn positions. Natural hostile mobs farther than `natural-hostile-despawn-distance` from every player can despawn. Named, rare, interacted, leashed, and mounted mobs are kept. The per-cycle duration and rejection reasons are visible in `/azm status`.

### Spawn conditions

`mob-conditions` adds optional filters per mob without changing its category, weight, or biome rules. Available fields are `time` (`day`, `dawn`, `dusk`, `night`), `min-light`/`max-light` (0–15), `min-y`/`max-y`, `weather` (`clear`, `rain`, `thunder`), and `ground-blocks` (block names or `*`/`?` patterns). The ground check uses the block directly below the candidate spawn position. Leave a field out to allow any value.

Use `/azm status [world]` to inspect current population and persisted telemetry for the last 24 hours: attempts, successful spawns, rejected attempts, average/max cycle time, and top rejection reasons. Telemetry is stored by world and minute in `survival.sqlite3`; old metric rows are pruned after 30 days.

## Phantoms

Configure the required number of full in-game days without sleep:

```yaml
phantom-sleep:
  nights-without-sleep: 3
```

Sleep history is stored by player UUID and world in the plugin data folder. A new player's counter starts at their first join. The phantom only selects eligible survival players; creative and spectator players are ignored. If world time is moved backwards, that player's counter in that world restarts safely.

## Rare encounters

Rare encounters are disabled by default. Enable them per profile under `rare-encounters`. The chance is evaluated per successful natural monster spawn; `max-per-world` caps existing rare mobs in that world. Health, damage multiplier, visible name, and bonus drop settings are saved on each rare entity, so a restart or configuration reload does not erase its encounter metadata. The damage multiplier also scales Creeper blast radius and Warden sonic damage. The configured bonus item is awarded only when a player kills the rare mob, including a player-owned projectile kill.

Start with a low chance and a small bonus. Use recognized PocketMine item names, for example `diamond`, `emerald`, or `gold_ingot`. An unknown item is skipped and reported in the server log.

Profiles may define weighted `archetypes` under `rare-encounters`. Each archetype can set `display-name`, `weight`, `health-multiplier`, `damage-multiplier`, and its own `bonus-drop`; `bonus-drop: false` disables a drop for that archetype. The selected archetype ID and its combat/drop values are saved with the entity, so tuning the profile does not rewrite an already spawned rare mob.

## UUID bestiary and hunt badges

Player kill history is stored under the player's UUID, not the current name. It records species, biome, dimension, total kills, and rare kills in the same `survival.sqlite3` database. By default only naturally spawned AZVanillaMobs count; change `bestiary.only-natural-kills` if the server wants spawn-egg or command-spawn kills included.

Use `/azbestiary` (alias `/azhunt`) to view recorded species and habitats, `/azbestiary goals` to view milestones, and `/azbestiary claim <goal>` to claim an earned badge once. Goals are configured by ID under `bestiary.goals` and may require a species, any species (`mob: "*"`), rare kills (`rare: true`), or a biome. Badges are persistent achievements displayed by the plugin; they do not grant items or economy funds.

## Commands and diagnostics

- `/azsummon <mob> [amount]` (alias `/azmob`) creates plugin mobs under a dedicated command name, avoiding collisions with other plugins that provide `/summon`.
- `/azm status` shows current mob counts, spawn attempts, successes, rejections, recent cycle time, and the most common rejection reasons.
- `/azm status [world]` also shows persisted spawn telemetry from the last 24 hours, optionally for one world.
- `/azm reload` reloads configuration and reports invalid values in the server log.
- `/azbestiary` (`/azhunt`) shows a player's UUID-based bestiary and hunt milestones.
- `/azkill <@e|mob>` removes plugin entities.

Administration commands require the `azvanillamobs.command.admin` permission. The bestiary command uses `azvanillamobs.command.bestiary` (enabled for all players by default). Spawn, dimension, profile, cap, weight, category, spawn condition, phantom, rare chance, and hunt-goal settings are checked at enable and reload.

## Optional RPGMoney Bedrock rewards

RPGMoney Bedrock and EconomyAPI are not dependencies of AZVanillaMobs. To reward kills, configure the desired mob IDs in RPGMoney's normal mob reward rules, such as `minecraft:zombie`, `minecraft:skeleton`, or `minecraft:creeper`. Mob network IDs are the same IDs emitted by these entities, so no custom bridge is needed. Configure only the mobs and amounts that fit the server economy, and retain RPGMoney's own anti-farm and world controls. Review its current configuration guide for the exact rule fields for your installed version.

AZVanillaMobs does not issue money itself and does not bypass RPGMoney's kill eligibility rules. Test each configured mob reward on the target server before enabling it for players.
