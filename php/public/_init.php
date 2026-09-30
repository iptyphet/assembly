<?php

declare(strict_types=1);

use Assembly\Data\Store;
use Assembly\Text\WordDiff;

session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

$configFile = dirname(__DIR__) . '/config.php';
$config = is_file($configFile)
    ? require $configFile
    : require dirname(__DIR__) . '/config.example.php';
$config += ['password' => '', 'data_dir' => dirname(__DIR__) . '/data', 'timezone' => 'Europe/Oslo'];

date_default_timezone_set((string) $config['timezone']);

// First run: seed data/ from data-example/ so diffs and queues are visible
// immediately. data/ itself is gitignored.
if (!is_dir($config['data_dir'])) {
    $exampleDir = dirname(__DIR__) . '/data-example';
    if (is_dir($exampleDir)) {
        mkdir($config['data_dir'], 0775, true);
        foreach (glob($exampleDir . '/*/*.json') ?: [] as $file) {
            $subdir = $config['data_dir'] . '/' . basename(dirname($file));
            if (!is_dir($subdir)) {
                mkdir($subdir, 0775, true);
            }
            copy($file, $subdir . '/' . basename($file));
        }
    }
}

$store = new Store((string) $config['data_dir']);

// Auth gate: only active when a password is configured. Empty password =
// no gate at all (local play). No users.json — just a session flag.
$requiresLogin = (string) $config['password'] !== '';
$script = basename((string) $_SERVER['SCRIPT_NAME']);
if ($requiresLogin && empty($_SESSION['authed']) && $script !== 'login.php') {
    header('Location: login.php');
    exit;
}

// Display name: free text stored in the session, used as version author and
// speaker name default. Asked once (header banner), editable any time.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set-name') {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name !== '') {
        $_SESSION['display_name'] = $name;
    }
    redirect_back('index.php');
}

function display_name(): string
{
    $name = trim((string) ($_SESSION['display_name'] ?? ''));

    return $name === '' ? 'Anonymous' : $name;
}

/**
 * PRG redirect with a whitelist-validated target: only local page URLs of
 * the form page.php?a=b are honored, anything else falls back.
 */
function redirect_back(string $default): never
{
    $back = (string) ($_POST['_back'] ?? '');
    if (!preg_match('/^[a-z]+\.php(\?[\w=&%-]*)?$/', $back)) {
        $back = $default;
    }
    header('Location: ' . $back);
    exit;
}

/** Render a UTC ISO-8601 timestamp in the configured timezone. */
function fmt_time(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '';
    }

    return (new \DateTimeImmutable($utc))
        ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i');
}

/**
 * Render a word-level diff between two texts as <del>/<ins> HTML.
 * Segments are escaped with h(); only the tags are markup.
 */
function render_diff(string $oldText, string $newText): string
{
    $html = '';
    foreach (WordDiff::compute($oldText, $newText) as $segment) {
        $text = h($segment['text']);
        $html .= match ($segment['kind']) {
            WordDiff::REMOVED => "<del>$text</del> ",
            WordDiff::ADDED => "<ins>$text</ins> ",
            default => "$text ",
        };
    }

    return $html;
}
