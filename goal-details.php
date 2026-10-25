<?php
try{
    require_once("./db.php");
    require_once("./model.php");
}
catch(PDOException $e){
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

$user = get_user($pdo, 1);
$userId = (int) $user["id"];

$pageTitle = "Goal Details";
$activePage = "goals";

$idInput = trim($_GET["id"] ?? "");
$id = ctype_digit($idInput) && (int) $idInput > 0 ? (int) $idInput : 0;
$goal = $id > 0 ? get_goal($pdo, $id) : null;

$errors = [];
$form = ["id" => "", "name" => "", "target_amount" => "", "target_date" => ""];
$reopenGoalModal = false;
$reopenInvestModal = false;
$reopenTakeBackModal = false;
$investAmount = "";
$takeBackAmount = "";

/* Whole-positive-integer amount rules, shared by invest and take-back. */
function goal_amount_error(string $input){
    if($input === ""){
        return "Amount is required.";
    }
    if(!ctype_digit($input) || (int) $input <= 0){
        return "Amount must be a whole positive number.";
    }
    if(strlen($input) > 10 || (int) $input > 2147483647){
        return "Amount is too large.";
    }
    return null;
}

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $action = trim($_POST["action"] ?? "");

    if($action === "goal_delete"){
        $postedId = trim($_POST["goal_id"] ?? "");

        if($goal === null || !ctype_digit($postedId) || (int) $postedId !== $id){
            $errors[] = "That goal no longer exists.";
        }
        else{
            delete_goal($pdo, $id);
            header("Location: /goals.php?saved=deleted");
            exit;
        }
    }
    elseif($action === "goal_save"){
        $form["id"] = trim($_POST["goal_id"] ?? "");
        $form["name"] = trim($_POST["name"] ?? "");
        $form["target_amount"] = trim($_POST["target_amount"] ?? "");
        $form["target_date"] = trim($_POST["target_date"] ?? "");

        if($form["name"] === ""){
            $errors[] = "Name is required.";
        }
        elseif(mb_strlen($form["name"]) > 255){
            $errors[] = "Name must be 255 characters or fewer.";
        }

        $targetAmount = null;
        if($form["target_amount"] === ""){
            $errors[] = "Target amount is required.";
        }
        elseif(!ctype_digit($form["target_amount"]) || (int) $form["target_amount"] <= 0){
            $errors[] = "Target amount must be a whole positive number.";
        }
        elseif(strlen($form["target_amount"]) > 10 || (int) $form["target_amount"] > 2147483647){
            $errors[] = "Target amount is too large.";
        }
        else{
            $targetAmount = (int) $form["target_amount"];
        }

        if(mb_strlen($form["target_date"]) > 255){
            $errors[] = "Target date must be 255 characters or fewer.";
        }

        if($goal === null){
            $errors[] = "That goal no longer exists.";
        }

        if(empty($errors) && $targetAmount !== null){
            update_goal($pdo, $id, $form["name"], $targetAmount, $form["target_date"]);
            header("Location: " . "/goal-details.php?id=" . $id . "&saved=goal");
            exit;
        }

        $reopenGoalModal = true;
    }
    elseif($action === "invest" || $action === "takeback"){
        if($goal === null){
            $errors[] = "That goal no longer exists.";
        }
        else{
            $amountInput = trim($_POST["amount"] ?? "");
            $amountError = goal_amount_error($amountInput);

            if($amountError !== null){
                $errors[] = $amountError;
            }

            /* Take back is capped at whatever is currently invested in the goal. */
            if($action === "takeback" && $amountError === null && (int) $amountInput > (int) $goal["investment"]){
                $errors[] = "You can take back at most " . peso((int) $goal["investment"]) . ".";
            }

            if(empty($errors)){
                record_goal_investment(
                    $pdo,
                    (int) $goal["id"],
                    (int) $amountInput,
                    (string) $goal["name"],
                    $userId,
                    $action === "invest" ? "-" : "+"
                );

                header("Location: " . "/goal-details.php?id=" . $id . "&saved=" . ($action === "invest" ? "invested" : "taken"));
                exit;
            }

            if($action === "invest"){
                $investAmount = $amountInput;
                $reopenInvestModal = true;
            }
            else{
                $takeBackAmount = $amountInput;
                $reopenTakeBackModal = true;
            }
        }
    }
    else{
        $errors[] = "Choose a valid action.";
    }
}

/* GET without an id goes back to the list; GET with an unknown id renders
   a friendly not-found card below. POST falls through to not-found handling. */
if($_SERVER["REQUEST_METHOD"] === "GET" && $goal === null && $id === 0){
    header("Location: /goals.php");
    exit;
}

$notFound = $goal === null;
$detailAction = "/goal-details.php?id=" . $id;

if(!$notFound){
    $investment = (int) $goal["investment"];
    $target = (int) $goal["target_amount"];
    $share = goal_share($investment, $target);
    $isComplete = $target > 0 && $investment >= $target;
    $hasDate = trim((string) $goal["target_date"]) !== "";
    $ledger = get_goal_investments($pdo, $id);
    $goalName = (string) $goal["name"];

    /* The shared goal modal opens prefilled with the current goal. */
    $form = [
        "id" => (string) $goal["id"],
        "name" => (string) $goal["name"],
        "target_amount" => (string) $goal["target_amount"],
        "target_date" => (string) $goal["target_date"],
    ];
}

$flashText = match(trim($_GET["saved"] ?? "")){
    "invested" => "Investment recorded.",
    "taken"    => "Amount taken back.",
    "goal"     => "Goal updated.",
    default    => "",
};

require_once("./partials/header.php");
require_once("./partials/sidebar.php");
?>

<main class="app-main">

    <?php if($notFound): ?>
        <header class="page-header">
            <div>
                <h1 class="h4 mb-1">Goal Details</h1>
            </div>
        </header>

        <?php if(!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body text-center py-5">
                <p class="text-muted mb-3">This goal does not exist.</p>
                <a class="btn btn-primary" href="/goals.php">Back to Goals</a>
            </div>
        </div>
    <?php else: ?>

        <header class="page-header">
            <div>
                <a class="tiny text-decoration-none" href="/goals.php">&lsaquo; All Goals</a>
                <h1 class="h4 mb-1 mt-1"><?= htmlspecialchars($goalName) ?></h1>
                <p class="text-muted mb-0"><?= $hasDate ? "Target date: " . htmlspecialchars((string) $goal["target_date"]) : "No target date set" ?></p>
            </div>
        </header>

        <?php if($flashText !== ""): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flashText) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <section class="card mb-4">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <div class="text-muted small mb-1">Invested</div>
                        <div class="h5 mb-0"><?= peso($investment) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted small mb-1">Target</div>
                        <div class="h5 mb-0"><?= peso($target) ?></div>
                    </div>
                </div>

                <div class="progress mb-2" role="progressbar"
                     aria-label="Progress toward <?= htmlspecialchars($goalName) ?>"
                     aria-valuenow="<?= $share ?>" aria-valuemin="0" aria-valuemax="100"
                     style="height: 8px;">
                    <div class="progress-bar" style="width: <?= $share ?>%; background: var(<?= $isComplete ? "--balance-accent" : "--brand" ?>);"></div>
                </div>

                <div class="d-flex align-items-center justify-content-between">
                    <span class="tiny text-muted"><?= $share ?>% of target</span>
                    <?php if($isComplete): ?>
                        <span class="badge" style="background: var(--balance-accent); color: var(--bg-sidebar);">Completed</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">Ongoing</span>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#investModal">Invest</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#takebackModal">Take Back</button>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#goalModal"
                    data-goal-edit data-goal-id="<?= (int) $goal["id"] ?>"
                    data-goal-name="<?= htmlspecialchars($goalName) ?>"
                    data-goal-target="<?= $target ?>"
                    data-goal-date="<?= htmlspecialchars((string) $goal["target_date"]) ?>">Edit</button>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#goalDeleteModal"
                    data-goal-delete data-goal-id="<?= (int) $goal["id"] ?>"
                    data-goal-name="<?= htmlspecialchars($goalName) ?>">Delete</button>
        </div>

        <section class="card">
            <div class="card-body">
                <h2 class="h5 mb-3">Investment Ledger</h2>

                <?php if(empty($ledger)): ?>
                    <p class="text-muted mb-0">No investments yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach($ledger as $entry): ?>
                                <?php
                                    /* Ledger amounts are signed: + = invested (an
                                       expense transaction), - = taken back (income). */
                                    $entryIsInvestment = (int) $entry["amount"] > 0;
                                ?>
                                <tr>
                                    <td class="col-date"><?= htmlspecialchars((string) $entry["date"]) ?></td>
                                    <td class="<?= $entryIsInvestment ? "stat-expense" : "stat-income" ?>">
                                        <?= $entryIsInvestment ? "Invested" : "Taken Back" ?>
                                    </td>
                                    <td class="text-end fw-semibold <?= $entryIsInvestment ? "stat-expense" : "stat-income" ?>">
                                        <?= peso((int) abs((int) $entry["amount"])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="modal fade" id="investModal" tabindex="-1" aria-labelledby="investModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="investModalLabel">Invest in <?= htmlspecialchars($goalName) ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post" action="<?= htmlspecialchars($detailAction) ?>" id="investForm" novalidate>
                        <div class="modal-body">
                            <?php if($reopenInvestModal && !empty($errors)): ?>
                                <div class="alert alert-danger py-2" role="alert">
                                    <?php foreach($errors as $investError): ?>
                                        <div><?= htmlspecialchars($investError) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <input type="hidden" name="action" value="invest">

                            <div class="mb-3">
                                <label class="form-label" for="investAmount">Amount</label>
                                <input type="text" inputmode="numeric" class="form-control" id="investAmount" name="amount"
                                       placeholder="Enter amount (integer only)" value="<?= htmlspecialchars($investAmount) ?>">
                            </div>

                            <div class="form-text mb-0">Recorded as an expense under &ldquo;<?= htmlspecialchars(GOAL_CATEGORY) ?>&rdquo; and deducted from your balance.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Invest</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="takebackModal" tabindex="-1" aria-labelledby="takebackModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="takebackModalLabel">Take Back from <?= htmlspecialchars($goalName) ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="post" action="<?= htmlspecialchars($detailAction) ?>" id="takebackForm" novalidate>
                        <div class="modal-body">
                            <?php if($reopenTakeBackModal && !empty($errors)): ?>
                                <div class="alert alert-danger py-2" role="alert">
                                    <?php foreach($errors as $takeBackError): ?>
                                        <div><?= htmlspecialchars($takeBackError) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <input type="hidden" name="action" value="takeback">

                            <div class="mb-3">
                                <label class="form-label" for="takeBackAmount">Amount</label>
                                <input type="text" inputmode="numeric" class="form-control" id="takeBackAmount" name="amount"
                                       placeholder="Enter amount (integer only)" value="<?= htmlspecialchars($takeBackAmount) ?>">
                            </div>

                            <div class="form-text mb-0">You can take back up to <?= peso($investment) ?>. Recorded as income back to your balance.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Take Back</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php
            $goalModalAction = $detailAction;
            require_once("./partials/goal-modals.php");
        ?>

        <?php if($reopenInvestModal || $reopenTakeBackModal): ?>
            <script>
            document.addEventListener("DOMContentLoaded", function(){
                var target = document.getElementById("<?= $reopenInvestModal ? "investModal" : "takebackModal" ?>");
                if(target){
                    bootstrap.Modal.getOrCreateInstance(target).show();
                }
            });
            </script>
        <?php endif; ?>

    <?php endif; ?>

</main>

<?php require_once("./partials/footer.php"); ?>
