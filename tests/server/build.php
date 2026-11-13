<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$output = $root . '/dist/AZVanillaMobsSmoke.phar';
if (ini_get('phar.readonly') !== '0') {
    fwrite(STDERR, "Run with php -d phar.readonly=0 tests/server/build.php\n");
    exit(1);
}
if (!is_dir(dirname($output))) mkdir(dirname($output), 0755, true);
if (file_exists($output)) unlink($output);
$phar = new Phar($output);
$phar->startBuffering();
$phar->addFile(__DIR__ . '/plugin.yml', 'plugin.yml');
$phar->addFile(__DIR__ . '/src/Smoke.php', 'src/BeeAZ/AZVanillaMobsSmoke/Smoke.php');
$phar->setStub('<?php __HALT_COMPILER();');
$phar->stopBuffering();
echo $output, "\n";
