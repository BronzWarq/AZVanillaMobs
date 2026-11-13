# PocketMine-MP smoke test

Use a disposable PocketMine-MP 5.44.x server. Build the plugin with
`php -d phar.readonly=0 tools/build.php`, then build this test plugin with
`php -d phar.readonly=0 tests/server/build.php`. Copy both PHARs from `dist/`
into the server's `plugins/` directory and start the server. The test shuts
the server down and writes its result into the smoke plugin's data folder.

The test covers runtime startup, plugin command names, spawn profile rules,
rare entity NBT persistence and modifiers, configurable damage, villager
recipes, fish buckets, snow golem targeting, and zombie conversion. It does not
test real client trading, live natural spawn rates, sleep tracking over several
in-game days, or multiplayer performance.
