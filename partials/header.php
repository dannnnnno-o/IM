<?php
/* Cache-busting for global.css: bump on every stylesheet change. */
define("CSS_VERSION", "20261023-2");

$pageTitle = $pageTitle ?? 'J-Budget';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> &middot; J-Budget</title>
    <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
    <!-- Bump CSS_VERSION whenever styles/global.css changes; forces cached
         browsers to refetch (no hard refresh needed). -->
    <link rel="stylesheet" href="/styles/global.css?v=<?= CSS_VERSION ?>">
</head>
<body class="d-flex min-vh-100">