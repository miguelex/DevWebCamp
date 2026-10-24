<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [__FILE__, $root . '/phpstan-bootstrap.php'];

foreach (['src', 'config', 'public', 'views', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);
$failures = 0;

foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=stderr', '-l', $file],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Could not run PHP syntax check for $file\n");
        $failures++;
        continue;
    }

    $output = stream_get_contents($pipes[1]);
    $diagnostics = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if ($diagnostics !== '') {
        fwrite(STDERR, $diagnostics);
    }
    if (proc_close($process) !== 0) {
        fwrite(STDERR, $output);
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, "PHP syntax check failed in $failures file(s).\n");
    exit(1);
}

echo 'PHP syntax OK: ' . count($files) . " files.\n";
