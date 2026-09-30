<?php

declare(strict_types=1);

// Copy this file to config.php and adjust. config.php is gitignored.
// The app falls back to these defaults when config.php is missing.
return [
    // Empty string = no login gate at all (local play). Set a password to
    // require the session-based login page.
    'password' => '',
    'data_dir' => __DIR__ . '/data',
    'timezone' => 'Europe/Oslo',
];
