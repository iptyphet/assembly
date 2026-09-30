<?php

declare(strict_types=1);

$needsName = trim((string) ($_SESSION['display_name'] ?? '')) === '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'Assembly sandbox') ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php">Assembly sandbox</a>
    <nav>
        <a href="proposals.php">Proposals</a>
        <a href="sessions.php">Sessions</a>
        <span class="whoami"><?= h(display_name()) ?></span>
    </nav>
</header>
<?php if ($needsName): ?>
    <form method="post" class="name-banner">
        <input type="hidden" name="action" value="set-name">
        <input type="hidden" name="_back" value="<?= h(basename((string) $_SERVER['SCRIPT_NAME']) . ($_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">
        <label>Your display name (used as author and speaker default):
            <input type="text" name="name" required autofocus>
        </label>
        <button type="submit">Set name</button>
    </form>
<?php else: ?>
    <form method="post" class="name-banner subtle">
        <input type="hidden" name="action" value="set-name">
        <input type="hidden" name="_back" value="<?= h(basename((string) $_SERVER['SCRIPT_NAME']) . ($_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">
        <label>Name: <input type="text" name="name" value="<?= h(display_name()) ?>" required></label>
        <button type="submit">Change</button>
    </form>
<?php endif; ?>
<main>
