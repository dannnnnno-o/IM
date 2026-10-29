<?php
try{
    require_once("./db.php");
    require_once("./model.php");
}
catch(PDOException $e){
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

$today = new DateTimeImmutable("today");

$pageTitle = "Transaction History";
$activePage = "history";

$perPage = 10;

$typeInput = trim($_GET["type"] ?? "all");
if(!in_array($typeInput, ["all", "income", "expense", "goal"], true)){
    $typeInput = "all";
}

/* "goal" is orthogonal to direction: both invest (-) and take back (+) rows. */
$goalOnly = $typeInput === "goal";

$mode = match($typeInput){
    "income" => "+",
    "expense" => "-",
    default => "",
};

/* Shared Daily/Weekly/Monthly/All-Time period picker (same component as
   Summary). "all" = unbounded, which is History's default view. */
$period = resolve_period($pdo, $_GET, $today, ["allowAll" => true]);
$errors = $period["errors"];
$hasErrors = !empty($errors);

if($hasErrors){
    $total = 0;
    $totalPages = 1;
    $page = 1;
    $offset = 0;
    $rows = [];
}
else{
    $total = count_filtered_transactions($pdo, $mode, $period["start"], $period["end"], $goalOnly);
    $totalPages = max(1, (int) ceil($total / $perPage));

    $page = (int) ($_GET["page"] ?? 1);
    $page = max(1, min($page, $totalPages));

    $offset = ($page - 1) * $perPage;
    $rows = get_filtered_transactions($pdo, $mode, $period["start"], $period["end"], $perPage, $offset, $goalOnly);
}

$countLabel = $typeInput === "all"
    ? $total . " transaction" . ($total === 1 ? "" : "s")
    : $total . " " . $typeInput . " record" . ($total === 1 ? "" : "s");

/* Pagination and picker links carry the full period + type state. */
$historyBase = array_filter(array_merge($period["state"], ["type" => $typeInput]));

function history_url(array $overrides, array $base){
    $query = array_merge($base, $overrides);
    return "/history.php?" . http_build_query($query);
}

$periodConfig = [
    "action" => "/history.php",
    "allowAllTime" => true,
    "allTimeActive" => $period["view"] === "all",
    "extraHidden" => ["type" => $typeInput],
];

require_once("./partials/header.php");
require_once("./partials/sidebar.php");
?>

<main class="app-main">
    <header class="page-header">
        <div>
            <h1 class="h4 mb-1">Transaction History</h1>
            <p class="text-muted mb-0"><?= $countLabel ?> found</p>
        </div>
    </header>

    <div class="card">
        <div class="card-body">

            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
                <?php require_once("./partials/period-picker.php"); ?>

                <form method="get" action="/history.php" id="typeForm" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($period["state"]["view"]) ?>">
                    <?php foreach(array_filter(array_diff_key($period["state"], ["view" => true])) as $stateName => $stateValue): ?>
                        <input type="hidden" name="<?= htmlspecialchars($stateName) ?>" value="<?= htmlspecialchars($stateValue) ?>">
                    <?php endforeach; ?>
                    <label class="form-label mb-0 me-1" for="type">Type</label>
                    <select class="form-select form-select-sm w-auto" id="type" name="type">
                        <option value="all"     <?= $typeInput === "all"     ? "selected" : "" ?>>All Types</option>
                        <option value="income"  <?= $typeInput === "income"  ? "selected" : "" ?>>Income</option>
                        <option value="expense" <?= $typeInput === "expense" ? "selected" : "" ?>>Expense</option>
                        <option value="goal" <?= $typeInput === "goal" ? "selected" : "" ?>>Goal</option>
                    </select>
                </form>
            </div>

            <?php if(!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php foreach($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if($hasErrors): ?>
                <p class="text-muted mb-0">Adjust the filters above.</p>
            <?php elseif(empty($rows)): ?>
                <p class="text-muted mb-0">No records match this filter.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th class="text-end">Amount</th>
                                <th><span class="visually-hidden">Comment</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($rows as $txn): ?>
                            <?php
                                $isIncome = mode_is_income($txn["mode"]);
                                $comment = trim((string) ($txn["comment"] ?? ""));
                                $hasComment = $comment !== "";
                                $commentId = "comment-" . (int) $txn["id"];
                            ?>
                            <tr>
                                <td class="col-date"><?= htmlspecialchars($txn["date"]) ?></td>
                                <td><?= htmlspecialchars($txn["category"]) ?></td>
                                <td class="<?= $isIncome ? "stat-income" : "stat-expense" ?>">
                                    <?= mode_label($txn["mode"]) ?>
                                </td>
                                <td class="text-end fw-semibold <?= $isIncome ? "stat-income" : "stat-expense" ?>">
                                    <?= peso($txn["amount"]) ?>
                                </td>
                                <td class="text-end">
                                    <?php if($hasComment): ?>
                                        <button type="button" class="comment-toggle"
                                                aria-expanded="<?= $goalOnly ? "true" : "false" ?>"
                                                aria-controls="<?= $commentId ?>" aria-label="Toggle comment">
                                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M4 6l4 4 4-4"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if($hasComment): ?>
                                <tr class="comment-row" id="<?= $commentId ?>"
                                    <?= $goalOnly ? "" : "hidden" ?>>
                                    <td colspan="5" class="comment-cell"><?= htmlspecialchars($comment) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if($totalPages > 1): ?>
                    <nav class="mt-4 d-flex justify-content-center">
                        <ul class="pagination mb-0">
                            <li class="page-item <?= $page <= 1 ? "disabled" : "" ?>">
                                <a class="page-link" href="<?= htmlspecialchars(history_url(["page" => $page - 1], $historyBase)) ?>">Prev</a>
                            </li>
                            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i === $page ? "active" : "" ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(history_url(["page" => $i], $historyBase)) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $totalPages ? "disabled" : "" ?>">
                                <a class="page-link" href="<?= htmlspecialchars(history_url(["page" => $page + 1], $historyBase)) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</main>

<script>
(function(){
    var form = document.getElementById("typeForm");
    if(form){
        var type = document.getElementById("type");
        if(type){
            type.addEventListener("change", function(){
                form.submit();
            });
        }
    }

    document.querySelectorAll(".comment-toggle").forEach(function(toggle){
        toggle.addEventListener("click", function(){
            var target = document.getElementById(toggle.getAttribute("aria-controls"));
            if(!target) return;

            var willOpen = toggle.getAttribute("aria-expanded") !== "true";
            toggle.setAttribute("aria-expanded", String(willOpen));
            target.hidden = !willOpen;
        });
    });
})();
</script>

<?php require_once("./partials/footer.php"); ?>
