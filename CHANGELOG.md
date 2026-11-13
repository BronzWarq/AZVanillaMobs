# AZVanillaMobs 1.0.3

## Русский

- Добавлены профили естественного спавна по мирам: категории мобов, биомные ограничения, веса, лимиты, интервалы и ограниченное число попыток только в загруженных чанках.
- Добавлены сохранение истории сна по UUID и миру для фантомов, а также редкие усиленные мобы с сохраняемыми характеристиками и бонусной добычей.
- Добавлены команды `/azm status` и `/azm reload`; команда создания мобов получила имя `/azsummon` с алиасом `/azmob`, чтобы избежать конфликтов с `/summon` других плагинов.
- Для `/azsummon` добавлен предел 20 мобов за один вызов; разбор некорректных значений профиля стал устойчивее.
- Снижены лишние сканирования сущностей; исправлены спавн страйдеров, масштабирование редкого урона, дробные веса и синтаксическая ошибка обработчика поводка.
- Превращение зомби в утопленника сохраняет состояние естественного появления и параметры редкой встречи.

Проверено: PHP 8.4 lint и smoke-запуск PHAR на PocketMine-MP 5.44.3. Живые частоты естественного спавна, сон в течение нескольких игровых суток, торговля с реальными клиентами и многопользовательская производительность требуют наблюдения на игровом сервере.

## English

- Add per-world natural spawn profiles with mob categories, biome rules, weights, caps, intervals, and bounded attempts using loaded chunks only.
- Add sleep history persisted by player UUID and world for phantom eligibility, plus rare enhanced mobs with persistent modifiers and bonus drops.
- Add `/azm status` and `/azm reload`; rename the spawn command to `/azsummon` with `/azmob` alias to avoid collisions with other plugins' `/summon` command.
- Limit `/azsummon` to 20 mobs per invocation and handle malformed profile values safely.
- Reduce unnecessary entity scans; fix Strider spawning, rare damage scaling, fractional spawn weights, and a LeashListener syntax error.
- Preserve natural-spawn and rare encounter state when a Zombie converts to a Drowned.

Validated with PHP 8.4 syntax checks and a PHAR smoke run on PocketMine-MP 5.44.3. Live natural spawn rates, sleep behavior over multiple in-game days, real-client trading, and multiplayer performance require observation on a game server.

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
- Global and per-world caps count only live AZVanillaMobs, not players and items. Caps are checked after each successful spawn when multiple players are present.
- Fix sand, snow and snow-layer biome detection.
- Make drowned spawning in water reachable. Aquatic spawning recognizes water at or below the spawn location.
- Add a local PHAR builder, SHA-256 file and GitHub Actions build.

Validated: PHP syntax of 90 files; built PHAR startup on PocketMine-MP 5.44.3 / PHP 8.4.16; fresh config including `world`; a zombie kill issued one RPGMoney Bedrock 1.1.1 reward with EconomyAPI 5.7.3. The check used a simulated player; natural spawning and other mob types were not separately tested.
