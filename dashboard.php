<?php
try{
    require_once("./db.php");
    require_once("./model.php");
}
catch(PDOException $e){
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

$user = get_user($pdo, 1);

/* First-visit gate: an empty stored name means the landing page (index.php)
   has not been completed yet — bounce there instead of greeting "" with a
   null balance. Other routes don't read the name, so only this page gates. */
if(username_is_missing($user)){
    header("Location: /");
    exit;
}

if(isset($_GET["registered"])){
    $welcome_msg = "Welcome, ";
}

else{
    $welcome_msg = "Welcome back, ";
}

$today = new DateTimeImmutable("today");

/* Stat cards read today's movement (26 Oct revision): income and expenses
   for the current day only, bounded [today, today]. */
$todayStr      = $today->format("Y-m-d");
$todayIncome   = sum_amount($pdo, "+", $todayStr, $todayStr);
$todayExpenses = sum_amount($pdo, "-", $todayStr, $todayStr);
$totalGoals    = count_rows($pdo, "goals");
$totalTxns      = count_rows($pdo, "transactions");
$recent         = get_recent_transactions($pdo, 5);

$saved = $_GET["saved"] ?? "";
$isSaved = in_array($saved, ["income", "expense"], true);

$pageTitle = "Dashboard";
$activePage = "dashboard";

require_once("./partials/header.php");
require_once("./partials/sidebar.php");
?>

<main class="app-main">

    <header class="page-header">
        <div>
            <h1 class="h4 mb-1">Dashboard</h1>
            <p class="text-muted mb-0">
                <?=$welcome_msg . htmlspecialchars($user["username"]) ?>
                &middot; <?= $today->format("D, M j, Y") ?>
            </p>
        </div>
    </header>

    <?php if($isSaved): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= mode_label($saved === "income" ? "+" : "-") ?> transaction saved.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <section class="card balance-card mb-4">
        <div class="balance-main">
            <span class="balance-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 7a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2v1"/>
                    <path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H5a2 2 0 0 1-2-2z"/>
                    <circle cx="16.5" cy="13" r="1.1" fill="currentColor" stroke="none"/>
                </svg>
            </span>
            <div>
                <div class="text-muted small mb-2">Current Balance</div>
                <div class="balance-amount"><?= peso($user["balance"]) ?></div>
            </div>
        </div>
        <a class="btn btn-primary balance-cta" href="/make-transactions.php">Make Transaction</a>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card p-3 h-100">
                <div class="text-muted small mb-2">Today &middot; Income</div>
                <div class="h5 mb-0 stat-income"><?= peso($todayIncome) ?></div>
                <div class="text-muted tiny mt-1"><?= $today->format("F j") ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 h-100">
                <div class="text-muted small mb-2">Today &middot; Expenses</div>
                <div class="h5 mb-0 stat-expense"><?= peso($todayExpenses) ?></div>
                <div class="text-muted tiny mt-1"><?= $today->format("F j") ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 h-100 d-flex flex-column">
                <div class="text-muted small mb-2">Total Goals</div>
                <div class="h5 mb-0"><?= $totalGoals ?></div>

                <div class="text-muted tiny mt-1">
                <?php
                    $goal_msg;
                    switch ($totalGoals) {
                        case 0:
                            $goal_msg = "<a style=\"text-decoration: none;\" href=\"/goals.php?set=goal\">Set a goal</a> to get started.";
                            break;
                        
                        case 1:
                            $goal_msg = "Keep working towards your <a style=\"text-decoration: none;\" href=\"/goals.php\">goal</a> :>";
                            break;
                        default: 
                            $goal_msg = "Keep working toward your <a style=\"text-decoration: none;\" href=\"/goals.php\">goals</a> :>";
                            break;
                    }
                    echo $goal_msg;
                ?>
                </div>
            </div>
        </div>
    </div>

    <section class="card">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h5 mb-0">Recent Transactions</h2>
                <a href="/history.php" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>

            <?php if(empty($recent)): ?>
                <p class="text-muted mb-0">No transactions yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Type</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($recent as $txn): ?>
                            <?php $isIncome = mode_is_income($txn["mode"]); ?>
                            <tr>
                                <td class="col-date"><?= htmlspecialchars($txn["date"]) ?></td>
                                <td><?= htmlspecialchars($txn["category"]) ?></td>
                                <td class="<?= $isIncome ? "stat-income" : "stat-expense" ?>">
                                    <?= mode_label($txn["mode"]) ?>
                                </td>
                                <td class="text-end fw-semibold <?= $isIncome ? "stat-income" : "stat-expense" ?>">
                                    <?= peso($txn["amount"]) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

</main>

<?php require_once("./partials/footer.php"); ?>