<?php
try{
    require_once("./db.php");
    require_once("./model.php");
}
catch(PDOException $e){
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

$today = new DateTimeImmutable("today");

$pageTitle = "Summary";
$activePage = "summary";

$period = resolve_period($pdo, $_GET, $today, ["allowAll" => false]);
$errors = $period["errors"];
$hasErrors = !empty($errors);

if($hasErrors){
    $breakdown = [];
}
else{
    $breakdown = expense_by_category($pdo, $period["start"], $period["end"]);
}

$total = 0;
foreach($breakdown as $slice){
    $total += $slice["total"];
}

$chartColors = [
    "--chart-1", "--chart-2", "--chart-3", "--chart-4",
    "--chart-5", "--chart-6", "--chart-7", "--chart-8",
];

$donutRadius = 82;
$donutStroke = 28;
$donutCircumference = 2 * M_PI * $donutRadius;

foreach($breakdown as $index => $slice){
    $share = $total > 0 ? $slice["total"] / $total * 100 : 0;
    $breakdown[$index]["share"] = (int) round($share);
    $breakdown[$index]["color"] = $chartColors[$index % count($chartColors)];
    $breakdown[$index]["length"] = $total > 0 ? $slice["total"] / $total * $donutCircumference : 0;
    $breakdown[$index]["offset"] = 0;

    if($index > 0){
        $breakdown[$index]["offset"] = -($breakdown[$index - 1]["offset"] + $breakdown[$index - 1]["length"]);
    }
}

$ariaSlices = [];
foreach($breakdown as $slice){
    $ariaSlices[] = $slice["category"] . " " . $slice["share"] . "%";
}
$donutAria = "Donut chart of expenses, " . $period["rangeLabel"] . ": "
    . ($breakdown ? implode(", ", $ariaSlices) : "no expenses") . ".";

$periodConfig = [
    "action" => "/summary.php",
    "allowAllTime" => false,
    "allTimeActive" => false,
];

require_once("./partials/header.php");
require_once("./partials/sidebar.php");
?>

<main class="app-main">

    <header class="page-header">
        <div>
            <h1 class="h4 mb-1">Expense Summary</h1>
            <p class="text-muted mb-0">Where your money goes &middot; <?= $period["rangeLabel"] ?></p>
        </div>
    </header>

    <div class="card mb-4">
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
            <?php require_once("./partials/period-picker.php"); ?>
        </div>
    </div>

    <?php if(!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if(empty($breakdown)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="text-muted mb-0">No expenses in this period.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-md-5">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center">
                        <svg class="donut-chart" viewBox="0 0 210 210" role="img"
                             aria-label="<?= htmlspecialchars($donutAria) ?>">
                            <?php foreach($breakdown as $slice): ?>
                                <circle cx="105" cy="105" r="<?= $donutRadius ?>"
                                        fill="none" stroke="var(<?= $slice["color"] ?>)"
                                        stroke-width="<?= $donutStroke ?>"
                                        stroke-dasharray="<?= $slice["length"] ?> <?= $donutCircumference ?>"
                                        stroke-dashoffset="<?= $slice["offset"] ?>"
                                        transform="rotate(-90 105 105)" />
                            <?php endforeach; ?>
                            <text x="105" y="97" text-anchor="middle" class="donut-label">Total Expenses</text>
                            <text x="105" y="123" text-anchor="middle" class="donut-total"><?= peso($total) ?></text>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 summary-table">
                                <thead>
                                    <tr>
                                        <th>Category</th>
                                        <th class="text-end">Share</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($breakdown as $slice): ?>
                                    <tr>
                                        <td>
                                            <span class="d-inline-flex align-items-center gap-2">
                                                <span class="legend-dot" style="background: var(<?= $slice["color"] ?>);" aria-hidden="true"></span>
                                                <?= htmlspecialchars($slice["category"]) ?>
                                            </span>
                                        </td>
                                        <td class="text-end text-muted"><?= $slice["share"] ?>%</td>
                                        <td class="text-end fw-semibold stat-expense"><?= peso($slice["total"]) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</main>

<?php require_once("./partials/footer.php"); ?>
