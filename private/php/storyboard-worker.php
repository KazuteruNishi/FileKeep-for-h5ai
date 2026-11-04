<?php
// CLI only: the web gateway never exposes private application sources.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
set_time_limit(0);
require __DIR__ . '/core/class-users.php';
require __DIR__ . '/core/class-storyboards.php';
try {
    if (in_array('--enqueue-all', $argv, true)) { exit(Storyboards::enqueueAll() ? 0 : 1); }
    if (($argv[1] ?? '') === '--job') { Storyboards::workJob($argv[2] ?? '', $argv[3] ?? ''); exit; }
    Storyboards::initializeWorker();
    if (in_array('--watch', $argv, true)) { Storyboards::workParallel(); exit; }
    do {
        Storyboards::checkWorkerVersion();
        Storyboards::scanRequested();
        Storyboards::work();
        if (!in_array('--watch', $argv, true)) { break; }
        sleep(1);
    } while (true);
}
catch (Storyboardreload $error) { error_log('h5ai storyboards: ' . $error->getMessage()); exit(75); }
catch (Throwable $error) { error_log('h5ai storyboards: ' . $error->getMessage()); exit(1); }
