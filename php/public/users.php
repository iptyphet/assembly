<?php

declare(strict_types=1);

use Assembly\Domain\User;

require __DIR__ . '/_init.php';

// The one permission gate for page access: user management is admin only.
if (!is_admin($store)) {
    http_response_code(403);
    exit('Forbidden — user management is admin only.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');
        if ($name !== '' && in_array($role, User::ROLES, true)) {
            $user = User::create($name, $role);
            $store->save('users', $user->toArray());
        }
    } elseif ($action === 'set-role') {
        $data = $store->load('users', (string) ($_POST['user'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');
        if ($data !== null && in_array($role, User::ROLES, true)) {
            $user = User::fromArray($data);
            $user->role = $role;
            $store->save('users', $user->toArray());
        }
    }

    redirect_back('users.php');
}

$users = all_users($store);

$pageTitle = 'Assembly sandbox — Users';
require __DIR__ . '/_header.php';
?>
<h1>Users</h1>
<p class="meta">No passwords, no real auth — the header dropdown impersonates any user. Roles gate actions server-side.</p>

<form method="post" class="card">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="_back" value="users.php">
    <h2>New user</h2>
    <label>Name
        <input type="text" name="name" required>
    </label>
    <label>Role
        <select name="role">
            <?php foreach (User::ROLES as $role): ?>
                <option value="<?= h($role) ?>"><?= h(User::roleLabel($role)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Add user</button>
</form>

<div class="card-list">
<?php foreach ($users as $user): ?>
    <div class="card">
        <h2>
            <?= h($user->name) ?>
            <span class="badge role-<?= h($user->role) ?>"><?= h(User::roleLabel($user->role)) ?></span>
        </h2>
        <p class="meta">id <?= h($user->id) ?> · added <?= h(fmt_time($user->createdAtUtc)) ?> ·
            <a href="json.php?type=users&id=<?= h($user->id) ?>">view JSON</a></p>
        <form method="post" class="inline-form">
            <input type="hidden" name="action" value="set-role">
            <input type="hidden" name="user" value="<?= h($user->id) ?>">
            <input type="hidden" name="_back" value="users.php">
            <select name="role">
                <?php foreach (User::ROLES as $role): ?>
                    <option value="<?= h($role) ?>" <?= $role === $user->role ? 'selected' : '' ?>>
                        <?= h(User::roleLabel($role)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Change role</button>
        </form>
    </div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
