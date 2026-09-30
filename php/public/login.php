<?php

declare(strict_types=1);

require __DIR__ . '/_init.php';

if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$error = null;

if (!$requiresLogin) {
    // No password configured: no gate at all.
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals((string) $config['password'], (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        header('Location: index.php');
        exit;
    }
    $error = 'Wrong password.';
} elseif (!empty($_SESSION['authed'])) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Assembly sandbox — Log in';
require __DIR__ . '/_header.php';
?>
<h1>Log in</h1>
<?php if ($error !== null): ?>
    <p class="error"><?= h($error) ?></p>
<?php endif; ?>
<form method="post" class="login-form">
    <label>Password
        <input type="password" name="password" required autofocus autocomplete="current-password">
    </label>
    <button type="submit">Log in</button>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
