<?php
$activePage = $activePage ?? '';

$navItems = [
    ["key" => "dashboard", "label" => "Dashboard",         "href" => "/dashboard.php"],
    ["key" => "update",    "label" => "Make Transaction", "href" => "/make-transactions.php"],
    ["key" => "history",   "label" => "Transaction History", "href" => "/history.php"],
    ["key" => "summary",   "label" => "Summary",           "href" => "/summary.php"],
    ["key" => "goals",     "label" => "Goals",             "href" => "/goals.php"],
    ["key" => "settings",  "label" => "Settings",          "href" => "/settings.php"],
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
</aside>
<script>
(function(){
    /* Mobile topbar: with the nav items on one nowrap row, the current page's
       chip can end up outside the visible strip (e.g. Summary at 414px).
       Snap it into view on load — inline-center scrolls horizontally only,
       block-nearest keeps the page from jumping vertically. */
    var active = document.querySelector(".sidebar-nav .nav-link.is-active");
    if(active && active.scrollIntoView){
        try{
            active.scrollIntoView({inline: "center", block: "nearest", behavior: "auto"});
        }
        catch(error){
            active.scrollIntoView(false);
        }
    }
})();
</script>