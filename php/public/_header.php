<?php

declare(strict_types=1);

$headerUser = current_user($store);
$headerUsers = all_users($store);
$headerBack = basename((string) $_SERVER['SCRIPT_NAME'])
    . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
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
        <a href="topics.php">Topics</a>
        <?php if ($headerUser->role === \Assembly\Domain\User::ADMIN): ?>
            <a href="users.php">Users</a>
        <?php endif; ?>
        <form method="post" class="impersonate">
            <input type="hidden" name="action" value="impersonate">
            <input type="hidden" name="_back" value="<?= h($headerBack) ?>">
            <select name="user" aria-label="Impersonate user">
                <?php foreach ($headerUsers as $user): ?>
                    <option value="<?= h($user->id) ?>" <?= $user->id === $headerUser->id ? 'selected' : '' ?>>
                        <?= h($user->name) ?> (<?= h(\Assembly\Domain\User::roleLabel($user->role)) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Act as</button>
        </form>
    </nav>
</header>
<main>
