# AZVanillaMobs 1.0.1

## Русский

- Для новой установки добавлен стандартный мир `world` в карту естественного спавна. Существующие конфиги не перезаписываются; имя мира можно изменить вручную.
- Глобальный и мировой лимиты считают только живых мобов AZVanillaMobs, а не игроков и предметы. При нескольких игроках лимиты проверяются после каждого успешного спавна.
- Исправлено распознавание песка, снега и снежного слоя при выборе биома.
- Исправлено недостижимое условие появления утопленников в воде. Водные мобы учитывают воду как в точке появления, так и под ней.
- Добавлены локальная сборка PHAR, SHA-256 и сборка в GitHub Actions.

Проверено: PHP-синтаксис 90 файлов; запуск готового PHAR на PocketMine-MP 5.44.3 / PHP 8.4.16; генерация конфига с `world`; убийство зомби создало одну награду RPGMoney Bedrock 1.1.1 с EconomyAPI 5.7.3. Проверка использовала имитацию игрока, естественный спавн и остальные виды мобов отдельно не проверялись.

## English

- Add the default `world` to the natural spawning map for new installations. Existing configs remain untouched; configure your world name manually if needed.
- Global and per-world caps count only live AZVanillaMobs, not players or items. Caps are checked after each successful spawn when multiple players are present.
- Fix sand, snow and snow-layer biome detection.
- Make drowned spawning in water reachable. Aquatic spawning recognizes water at or below the spawn location.
- Add a local PHAR builder, SHA-256 file and GitHub Actions build.

Validated: PHP syntax of 90 files; built PHAR startup on PocketMine-MP 5.44.3 / PHP 8.4.16; fresh config including `world`; a zombie kill issued one RPGMoney Bedrock 1.1.1 reward with EconomyAPI 5.7.3. The check used a simulated player; natural spawning and other mob types were not separately tested.
