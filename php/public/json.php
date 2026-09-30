<?php

declare(strict_types=1);

use Assembly\Data\Store;

require __DIR__ . '/_init.php';

// Whitelisted document types only; id must be a plain document key.
$type = (string) ($_GET['type'] ?? '');
$id = (string) ($_GET['id'] ?? '');
if (!in_array($type, Store::TYPES, true) || !preg_match('/^[a-z0-9-]+$/', $id)) {
    http_response_code(400);
    exit('Invalid type or id');
}
$data = $store->load($type, $id);
if ($data === null) {
    http_response_code(404);
    exit('Document not found');
}

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$pageTitle = "Assembly sandbox — $type/$id.json";
require __DIR__ . '/_header.php';
?>
<p><a href="<?= h($type) ?>.php">← <?= h($type) ?></a></p>
<h1><?= h($type) ?>/<?= h($id) ?>.json</h1>
<p class="meta">The raw document, exactly as stored on disk. This structure is the point of the sandbox.</p>
<pre class="json-view"><?= h($json) ?></pre>
<?php require __DIR__ . '/_footer.php'; ?>
