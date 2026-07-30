<?php
/**
 * Runs every *-test.php suite in this directory and summarises the result.
 *
 * Each suite installs its own WordPress stubs as namespace-local functions,
 * so two suites cannot share a process — every one is run in a subprocess
 * with the same PHP binary that started this script.
 *
 * Usage: php tests/run.php [name-fragment]
 * Exit code is non-zero if any suite fails, so CI can gate on it.
 */

$filter = isset($argv[1]) ? $argv[1] : '';

$suites = glob(__DIR__ . '/*-test.php');
sort($suites);

if ($filter !== '') {
    $suites = array_values(array_filter($suites, static function ($path) use ($filter) {
        return stripos(basename($path), $filter) !== false;
    }));
}

if (!$suites) {
    fwrite(STDERR, "No test suites matched.\n");
    exit(1);
}

$php     = PHP_BINARY;
$failed  = [];
$passed  = 0;
$asserts = 0;

foreach ($suites as $suite) {
    $name = basename($suite);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(
        escapeshellarg($php) . ' ' . escapeshellarg($suite),
        $descriptors,
        $pipes,
        dirname(__DIR__)
    );

    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start {$name}\n");
        $failed[] = $name;
        continue;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);

    // Suites end with "N passed, M failed" — reuse those counts for the total.
    if (preg_match('/(\d+) passed, (\d+) failed/', $stdout, $m)) {
        $asserts += (int) $m[1] + (int) $m[2];
    }

    if ($status === 0) {
        $passed++;
        $tail = trim(strrchr(trim($stdout), "\n"));
        echo "PASS  {$name}  ({$tail})\n";
        continue;
    }

    $failed[] = $name;
    echo "FAIL  {$name}\n";
    echo rtrim($stdout) . "\n";
    if (trim($stderr) !== '') {
        echo trim($stderr) . "\n";
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
printf(
    "%d/%d suites passed, %d assertions\n",
    $passed,
    count($suites),
    $asserts
);

if ($failed) {
    echo 'Failed: ' . implode(', ', $failed) . "\n";
    exit(1);
}

exit(0);
