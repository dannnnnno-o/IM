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

$chartColors = [
    "--chart-1", "--chart-2", "--chart-3", "--chart-4",
    "--chart-5", "--chart-6", "--chart-7", "--chart-8",
];

/* One donut per direction (Income Summary / Expense Summary, 26 Oct 2026
   revision). Same hand-rolled SVG math for both; chart tokens only. */
function sum_donut(array $rows, string $mode, array $chartColors){
    $radius = 82;
    $stroke = 28;
    $circumference = 2 * M_PI * $radius;

    $total = 0;
    foreach($rows as $slice){
        $total += $slice["total"];
    }

    /* Stacked-dash math: slice i must START where slices 0..i-1 ended, i.e.
       dashoffset = -(cumulative length so far). The earlier double-negating
       recursion displaced every second slice (26 Oct regression). */
    $cumulative = 0.0;
    foreach($rows as $index => $slice){
        $share = $total > 0 ? $slice["total"] / $total * 100 : 0;
        $rows[$index]["share"] = round($share, 2);
        $rows[$index]["color"] = $chartColors[$index % count($chartColors)];
        $rows[$index]["length"] = $total > 0 ? $slice["total"] / $total * $circumference : 0;
        $rows[$index]["offset"] = -$cumulative;
        $cumulative += $rows[$index]["length"];
    }

    /* Close the ring exactly: float ratios can leave a sub-pixel shortfall
       which reads as a visible notch at the 12-o'clock seam. */
    if(!empty($rows) && $total > 0){
        $last = count($rows) - 1;
        $rows[$last]["length"] += $circumference - $cumulative;
    }

    $label = $mode === "+" ? "income" : "expenses";
    $ariaSlices = [];
    foreach($rows as $slice){
        $ariaSlices[] = $slice["category"] . " " . number_format($slice["share"], 2) . "%";
    }
    $aria = "Donut chart of " . $label . ": "
        . ($rows ? implode(", ", $ariaSlices) : "no " . $label) . ".";

    return [
        "slices"       => $rows,
        "total"        => $total,
        "aria"         => $aria,
        "radius"       => $radius,
        "stroke"       => $stroke,
        "circumference" => $circumference,
    ];
}

if($hasErrors){
    $incomeRows = [];
    $expenseRows = [];
}
else{
    /* Goal transfers stay included by design: invest rows sit among the
       expenses, take-back rows among the income (locked 24 Oct 2026). */
    $incomeRows = sum_by_category($pdo, $period["start"], $period["end"], "+");
    $expenseRows = sum_by_category($pdo, $period["start"], $period["end"], "-");
}

$incomeDonut = sum_donut($incomeRows, "+", $chartColors);
$expenseDonut = sum_donut($expenseRows, "-", $chartColors);
$net = $incomeDonut["total"] - $expenseDonut["total"];

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
            <h1 class="h4 mb-1">Summary</h1>
            <p class="text-muted mb-0">Money in and out &middot; <?= $period["rangeLabel"] ?></p>
        </div>
    </header>

    <div class="card mb-4">
        <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
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

    <?php
        /* One renderer for both sections (docs: single implementation). */
        function render_summary_section(array $donut, string $heading, string $centerFirst, string $amountClass, string $emptyText){
    ?>
        <h2 class="h5 mb-3"><?= $heading ?></h2>
        <?php if(empty($donut["slices"])): ?>
            <div class="card mb-4">
                <div class="card-body text-center py-5">
                    <p class="text-muted mb-0"><?= htmlspecialchars($emptyText) ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center">
                            <svg class="donut-chart" viewBox="0 0 210 210" role="img"
                                 aria-label="<?= htmlspecialchars($donut["aria"]) ?>">
                                <?php foreach($donut["slices"] as $slice): ?>
                                    <circle cx="105" cy="105" r="<?= $donut["radius"] ?>"
                                            fill="none" stroke="var(<?= $slice["color"] ?>)"
                                            stroke-width="<?= $donut["stroke"] ?>"
                                            stroke-dasharray="<?= $slice["length"] ?> <?= $donut["circumference"] ?>"
                                            stroke-dashoffset="<?= $slice["offset"] ?>"
                                            transform="rotate(-90 105 105)" />
                                <?php endforeach; ?>
                                <text x="105" y="97" text-anchor="middle" class="donut-label"><?= $centerFirst ?></text>
                                <text x="105" y="123" text-anchor="middle" class="donut-total"><?= peso($donut["total"]) ?></text>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
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
                                    <?php foreach($donut["slices"] as $slice): ?>
                                        <tr>
                                            <td>
                                                <span class="d-inline-flex align-items-center gap-2">
                                                    <span class="legend-dot" style="background: var(<?= $slice["color"] ?>);" aria-hidden="true"></span>
                                                    <?= htmlspecialchars($slice["category"]) ?>
                                                </span>
                                            </td>
                                            <td class="text-end text-muted"><?= number_format($slice["share"], 2) ?>%</td>
                                            <td class="text-end fw-semibold <?= $amountClass ?>"><?= peso($slice["total"]) ?></td>
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
    <?php
        }

        render_summary_section($incomeDonut, "Income Summary", "Total Income", "stat-income", "No income in this period.");
        render_summary_section($expenseDonut, "Expense Summary", "Total Expenses", "stat-expense", "No expenses in this period.");
    ?>

    <h2 class="h5 mb-3">Net</h2>
    <div class="card">
        <div class="card-body">
            <div class="row text-center gy-3">
                <div class="col-md-4">
                    <div class="tiny text-muted mb-1">Total Income</div>
                    <div class="h5 mb-0 stat-income"><?= peso($incomeDonut["total"]) ?></div>
                </div>
                <div class="col-md-4">
                    <div class="tiny text-muted mb-1">Total Expenses</div>
                    <div class="h5 mb-0 stat-expense"><?= peso($expenseDonut["total"]) ?></div>
                </div>
                <div class="col-md-4">
                    <div class="tiny text-muted mb-1">Net change in balance</div>
                    <div class="h5 mb-0 <?= ($net > 0 ? "stat-income" : ($net < 0 ? "stat-expense" : "text-muted")) ?>"><?= peso($net) ?></div>
                </div>
            </div>
        </div>
    </div>

</main>

<?php require_once("./partials/footer.php"); ?>
