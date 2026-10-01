<?php

declare(strict_types=1);

use Assembly\Ai\AiClient;
use Assembly\Ai\AnthropicAiClient;
use Assembly\Ai\MockAiClient;
use Assembly\Ai\PatchValidator;
use Assembly\Data\Store;
use Assembly\Domain\Proposal;
use Assembly\Domain\ProposalVersion;
use Assembly\Domain\Session;
use Assembly\Domain\User;
use Assembly\Text\WordDiff;

session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

$configFile = dirname(__DIR__) . '/config.php';
$config = is_file($configFile)
    ? require $configFile
    : require dirname(__DIR__) . '/config.example.php';
$config += [
    'password' => '',
    'data_dir' => dirname(__DIR__) . '/data',
    'timezone' => 'Europe/Oslo',
    'ai' => ['provider' => 'mock', 'api_key' => '', 'model' => 'claude-haiku-4-5'],
];

date_default_timezone_set((string) $config['timezone']);

// Seeding is per-subdirectory: any aggregate subdir missing from data/ is
// copied from data-example/ independently (including one nested level, for
// votes/{ballotId}/). Existing documents are never touched — so a deployed
// data/ that predates a new aggregate type gains the new seeds on the next
// request. data/ itself is gitignored.
$exampleDir = dirname(__DIR__) . '/data-example';
foreach (['users', 'topics', 'proposals', 'sessions', 'ballots', 'votes'] as $subdir) {
    $target = $config['data_dir'] . '/' . $subdir;
    $source = $exampleDir . '/' . $subdir;
    if (is_dir($target) || !is_dir($source)) {
        continue;
    }
    mkdir($target, 0775, true);
    foreach (glob($source . '/*.json') ?: [] as $file) {
        copy($file, $target . '/' . basename($file));
    }
    foreach (glob($source . '/*', GLOB_ONLYDIR) ?: [] as $nestedSource) {
        $nestedTarget = $target . '/' . basename($nestedSource);
        if (!is_dir($nestedTarget)) {
            mkdir($nestedTarget, 0775, true);
        }
        foreach (glob($nestedSource . '/*.json') ?: [] as $file) {
            copy($file, $nestedTarget . '/' . basename($file));
        }
    }
}

$store = new Store((string) $config['data_dir']);

// Auth gate: only active when a password is configured. Empty password =
// no gate at all (local play). Just a session flag; identity proper is the
// impersonation below.
$requiresLogin = (string) $config['password'] !== '';
$script = basename((string) $_SERVER['SCRIPT_NAME']);
if ($requiresLogin && empty($_SESSION['authed']) && $script !== 'login.php') {
    header('Location: login.php');
    exit;
}

// Impersonation: the session acts as one of the users from users/. No
// passwords — this is a rehearsal sandbox, switch any time.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'impersonate') {
    $userId = (string) ($_POST['user'] ?? '');
    if ($store->load('users', $userId) !== null) {
        $_SESSION['act_as_user_id'] = $userId;
    }
    redirect_back('index.php');
}

/** All users, keyed by id, in creation order. */
function all_users(Store $store): array
{
    $users = array_map(User::fromArray(...), $store->list('users'));
    usort($users, static fn (User $a, User $b): int => strcmp($a->createdAtUtc, $b->createdAtUtc));

    return $users;
}

/** The impersonated user; falls back to the first user when stale/unset. */
function current_user(Store $store): User
{
    $sessionId = (string) ($_SESSION['act_as_user_id'] ?? '');
    if ($sessionId !== '') {
        $data = $store->load('users', $sessionId);
        if ($data !== null) {
            return User::fromArray($data);
        }
    }
    $users = all_users($store);
    if ($users === []) {
        throw new \RuntimeException('No users seeded — check data-example/users/.');
    }

    return $users[0];
}

function is_admin(Store $store): bool
{
    return current_user($store)->role === User::ADMIN;
}

/** Chairs and admins may create topics and sessions. */
function is_chair(Store $store): bool
{
    return in_array(current_user($store)->role, [User::ADMIN, User::CHAIR], true);
}

/** The session owner (or an admin) steers the session. */
function owns_session(Store $store, Session $session): bool
{
    $user = current_user($store);

    return $user->role === User::ADMIN || $session->ownerUserId === $user->id;
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

/** Reject an action the current role may not perform. */
function forbid(): never
{
    http_response_code(403);
    exit('Forbidden — your role may not perform this action.');
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

/** AI client from config: mock by default, Anthropic when a key is set. */
function ai_client(array $config): AiClient
{
    $ai = (array) ($config['ai'] ?? []);
    if (($ai['provider'] ?? 'mock') === 'anthropic') {
        return new AnthropicAiClient((string) ($ai['api_key'] ?? ''), (string) ($ai['model'] ?? 'claude-haiku-4-5'));
    }

    return new MockAiClient();
}

/**
 * Ask the configured AI for a patch and validate the ops in one place.
 *
 * @param list<array{clauseId: ?string, operation: string, text: ?string}>|null $previousDraftOps
 * @return array{ops: list<array{clauseId: ?string, operation: string, text: ?string}>, summary: string}
 */
function ai_suggest_patch(
    array $config,
    Proposal $proposal,
    ProposalVersion $latestVersion,
    string $ask,
    ?array $previousDraftOps = null,
): array {
    $result = ai_client($config)->suggestPatch($proposal, $latestVersion, $ask, $previousDraftOps);
    $result['ops'] = PatchValidator::validate($result['ops'], $latestVersion);

    return $result;
}

/** One-shot flash message across the PRG redirect. */
function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function flash_take(): ?string
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $message === null ? null : (string) $message;
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
