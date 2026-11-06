# 🐾 AZVanillaMobs

Форк версии **1.0.2** для PocketMine-MP 5.44.x. Проверен на 5.44.3 с PHP 8.4.16. В новой версии исправлены бой, торговля и поведение мобов; полный список изменений приведён в [заметках к релизу](docs/releases/v1.0.2.md). Свежая установка включает стандартный мир `world` в карту естественного спавна. Существующие `config.yml` не перезаписываются: если ваш основной мир называется иначе, впишите его имя в `worlds` вручную.

Плагин использует стандартный `EntityDeathEvent`; для денежных наград с ним совместим [RPGMoney Bedrock 1.1.1](https://github.com/BronzWarq/RPGMoney/releases/tag/bedrock-v1.1.1). RPGMoney и EconomyAPI не обязательны для работы самих мобов. Проверено убийство зомби; остальные виды мобов и естественный спавн требуют отдельной проверки на вашем сервере. Одновременная установка другого плагина, регистрирующего те же виды мобов, может конфликтовать.

Скачайте PHAR из [релиза форка](https://github.com/BronzWarq/AZVanillaMobs/releases). Для самостоятельной сборки используйте PHP 8.4 с Phar:

```sh
php -d phar.readonly=0 tools/build.php
```

Готовый файл и его SHA-256 появятся в `dist/`. Исходный автор — BeeAZ; лицензия MIT сохранена.


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
- Nighttime Phantom spawning above the ground

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
| `/summon <mob> [amount]` | Spawn a mob |
| `/azkill <@e|mob>` | Remove plugin entities |

---

# ⚙️ Configuration

```yaml
worlds:
  "world": "overworld"
  "nether": "nether"
  "the_end": "the_end"

global-mob-cap: 200
per-world-mob-cap: 70
```

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
