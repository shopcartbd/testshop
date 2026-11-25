<?php
declare(strict_types=1);
/** Fallback entrypoint for hosts that always execute index.php. */
require __DIR__ . '/includes/bootstrap.php';
AllStore\Core\Router::dispatch();
