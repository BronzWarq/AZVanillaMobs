<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$output = $root . '/dist/AZVanillaMobs.phar';
if (!class_exists(Phar::class) || ini_get('phar.readonly') !== '0') {
    fwrite(STDERR, "Build with: php -d phar.readonly=0 tools/build.php\n");
    exit(1);
}
if (!is_dir(dirname($output))) mkdir(dirname($output), 0755, true);
if (file_exists($output)) unlink($output);
$phar = new Phar($output);
$phar->startBuffering();
foreach (['plugin.yml', 'LICENSE'] as $name) $phar->addFile($root . '/' . $name, $name);
foreach (['src', 'resources'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) continue;
        $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $phar->addFile($file->getPathname(), $name);
    }
}
$phar->setStub('<?php __HALT_COMPILER();');
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();
file_put_contents($output . '.sha256', hash_file('sha256', $output) . '  AZVanillaMobs.phar' . "\n");
echo $output, "\n";
