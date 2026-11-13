# 🐾 AZVanillaMobs

Fork version **1.0.3** for PocketMine-MP 5.44.x, tested on 5.44.3 with PHP 8.4.16. This release adds configurable survival spawning, persistent phantom sleep history, rare encounters, and performance improvements; see the [release notes](https://github.com/BronzWarq/AZVanillaMobs/blob/master/docs/releases/v1.0.3.md) for details. A fresh installation includes default world profiles. Existing `config.yml` files are not overwritten: if your world names differ, add them to `worlds` manually.

The plugin uses the standard `EntityDeathEvent` and is compatible with [RPGMoney Bedrock 1.1.1](https://github.com/BronzWarq/RPGMoney/releases/tag/bedrock-v1.1.1) for money rewards. Neither RPGMoney nor EconomyAPI is required for the mobs themselves. Installing another plugin that registers the same mob types may cause conflicts. The release was smoke-tested on PocketMine-MP 5.44.3; live natural spawn rates, multi-day sleep behavior, trading with real clients, and multiplayer performance need server-specific observation.

Download the PHAR from the [fork's releases](https://github.com/BronzWarq/AZVanillaMobs/releases). To build it yourself, use PHP 8.4 with Phar:

```sh
php -d phar.readonly=0 tools/build.php
```

The PHAR and its SHA-256 checksum will appear in `dist/`. The original author is BeeAZ; the MIT license is retained.


<p align="center">
  <img src="https://github.com/BeeAZ-pm-pl/AZVanillaMobs/blob/master/icon.png" width="140" height="140" alt="AZVanillaMobs Icon">
</p>

<p align="center">
  <b>Highly optimized vanilla mobs plugin for PocketMine-MP 5</b>
</p>

<p align="center">
  Bring true vanilla mob AI, combat, spawning, and gameplay mechanics to your server.
</p>

---

# ✨ Features

## 🧠 Advanced Vanilla AI
- Optimized AI system for better server performance
- Smart movement and pathfinding
- Swimming, flying, jumping, and cliff avoidance
- Improved fish swimming AI
- Better hostile mob targeting behavior

## ⚔️ Vanilla Combat System
- Built-in mob damage values with optional `combat-damage` overrides in `config.yml`
- Cave Spider poison attacks
- Wither Skeleton applies Wither effect
- Husk applies Hunger effect
- Snow Golems attack hostile mobs using snowballs
- Attack animations and realistic combat mechanics

## 🌍 Natural Vanilla Spawning
- Vanilla-style mob spawning system
- Passive mobs spawn during daytime
- Hostile mobs spawn at night
- Aquatic mobs spawn in water
- Nether and End exclusive spawning
- Nighttime Phantom spawning above the ground after the configured number of days without sleep
- Per-world spawn profiles with mob weights, biome restrictions, category controls, and spawn caps
- Bounded spawn attempts that use only already-loaded chunks
- Optional rare hostile encounters with persistent health/damage settings and bonus drops
- Distant natural hostile mobs despawn without removing named, interacted, leashed, mounted, or rare mobs

## 🌊 Aquatic Features
- Improved fish AI and movement
- Added fish bucket variants:
  - Cod Bucket
  - Salmon Bucket
  - Tropical Fish Bucket
  - Pufferfish Bucket
- Fish, axolotls, and tadpoles can be captured with a water bucket

## ☠️ Mob Mechanics
- Zombie → Drowned conversion
- Undead burn in sunlight
- Random armor equipment
- Vanilla loot tables
- XP drops
- Cooked drops from fire deaths

## 🐴 Interactive Mobs
- Rideable mobs support
- Horse armor support
- Wolf taming system
- Cat taming system
- Villager professions and leveling
- Piglin bartering
- Tamed, interacted, leashed, and mounted mobs are saved as persistent entities

---

# 📦 Included Entities

## 🌳 Overworld
Zombie, Skeleton, Creeper, Spider, Cave Spider, Phantom, Villager, Iron Golem, Snow Golem, Fish, Axolotl, Bee, Camel, Frog, Sniffer, Allay, and many more.

## 🔥 Nether
Piglin, Piglin Brute, Blaze, Ghast, Hoglin, Zoglin, Magma Cube, Wither Skeleton, Strider, and more.

## 🟣 The End
Enderman, Shulker, Endermite, Ender Dragon.

> Includes 65+ vanilla entities and projectiles.

---

# ⚡ Performance Optimized

AZVanillaMobs is built specifically for PocketMine-MP performance:

- Distributed AI updates
- Lightweight entity processing
- Optimized pathfinding
- Reduced CPU usage
- Multiplayer friendly

---

# 🐛 Recent Improvements

- Fixed fish getting stuck against walls
- Fixed delayed Creeper explosions
- Fixed Creeper explosion visual glitches in water
- Improved mob AI and movement behavior
- Improved vanilla gameplay accuracy

---

# 🔧 Commands

| Command | Description |
|---|---|
| `/azsummon <mob> [amount]` (`/azmob`) | Spawn 1–20 AZVanillaMobs using a dedicated command name |
| `/azkill <@e|mob>` | Remove plugin entities |
| `/azm status` | Inspect mob counts, spawn cycle timing, and common spawn rejection reasons |
| `/azm reload` | Reload configuration and report invalid settings to the server log |

---

# ⚙️ Configuration

```yaml
worlds:
  "world": {dimension: "overworld", profile: "survival"}
  "nether": {dimension: "nether", profile: "survival"}
  "the_end": {dimension: "the_end", profile: "survival"}

global-mob-cap: 200
per-world-mob-cap: 70

phantom-sleep:
  nights-without-sleep: 3

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
      phantom: 2
    mob-biomes:
      husk: [desert, "badlands*"]
    rare-encounters:
      enabled: false
      chance: 0.01
      max-per-world: 2
      health-multiplier: 1.5
      damage-multiplier: 1.15
      bonus-drop: {item: diamond, amount: 1, chance: 0.1}
```

Legacy world values such as `"world": "overworld"` remain supported. See [the survival-server guide](docs/SURVIVAL_GUIDE.md) for profile tuning and optional RPGMoney setup.

---

# 🚀 Installation

1. Download the PHAR from the fork release
2. Put the `.phar` file into the `plugins/` folder
3. Start the server
4. Configure `plugin_data/AZVanillaMobs/config.yml`
5. Restart the server

---

# 📌 Compatibility

- PocketMine-MP 5.44.x tested (API 5.x)
- API 5.0.0+
- PHP 8+

---

# ❤️ Credits

Developed by **BeeAZ**

Made for servers that want a true vanilla mob experience on PocketMine-MP.
