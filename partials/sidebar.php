<?php
$activePage = $activePage ?? '';

$navItems = [
    ["key" => "dashboard", "label" => "Dashboard",         "href" => "/dashboard.php"],
    ["key" => "update",    "label" => "Make Transaction", "href" => "/make-transactions.php"],
    ["key" => "history",   "label" => "Transaction History", "href" => "/history.php"],
    ["key" => "summary",   "label" => "Summary",           "href" => "/summary.php"],
    ["key" => "goals",     "label" => "Goals",             "href" => "/goals.php"],
];
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <span class="brand-mark">J</span>
        <span class="brand-name">J-Budget</span>
    </div>

    <nav class="sidebar-nav">
        <?php foreach($navItems as $item): ?>
            <?php if(!empty($item["disabled"])): ?>
                <span class="nav-link is-disabled" aria-disabled="true">
                    <span class="nav-label"><?= htmlspecialchars($item["label"]) ?></span>
                    <span class="nav-soon">soon</span>
                </span>
            <?php else: ?>
                <a class="nav-link <?= $activePage === $item["key"] ? "is-active" : "" ?>"
                   href="<?= htmlspecialchars($item["href"]) ?>">
                    <span class="nav-label"><?= htmlspecialchars($item["label"]) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <span class="nav-link is-disabled" aria-disabled="true">
            <span class="nav-label">Logout</span>
        </span>
    </div>
</aside>