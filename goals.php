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

$pageTitle = "Goals";
$activePage = "goals";

/* ---- request state ----------------------------------------------------- */

$goals = get_goals($pdo);

$flashText = match(trim($_GET["saved"] ?? "")){
    "goal"      => "Goal saved.",
    "deleted"   => "Goal deleted.",
    "completed" => "Marked as complete.",
    "invested"  => "Investment recorded.",
    "taken"     => "Amount taken back.",
    default     => "",
};

$errors = [];
$form = ["id" => "", "name" => "", "target_amount" => "", "target_date" => ""];
$reopenGoalModal = false;
$reopenInvestModal = false;
$reopenTakeBackModal = false;
$investAmount = "";
$takeBackAmount = "";
$investGoalId = "";
$investGoalName = "";
$takeBackGoalId = "";
$takeBackGoalName = "";
$takeBackCap = 0;

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

/* ---- POST actions ------------------------------------------------------ */

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $action = trim($_POST["action"] ?? "");

    /* Shared look-up: every goal-scoped action needs a valid goal. */
    $postedId = trim($_POST["goal_id"] ?? "");
    $goal = ctype_digit($postedId) && (int) $postedId > 0 ? get_goal($pdo, (int) $postedId) : null;
    $goalMissing = $goal === null;

    if($action === "goal_save"){
        $form["id"] = $postedId;
        $form["name"] = trim($_POST["name"] ?? "");
        $form["target_amount"] = trim($_POST["target_amount"] ?? "");
        $form["target_date"] = trim($_POST["target_date"] ?? "");

        $isEdit = $form["id"] !== "";

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

        if($isEdit){
            if($goalMissing){
                $errors[] = "That goal no longer exists.";
            }
            elseif((int) $goal["completed"] === 1){
                /* Completed goals are frozen (26 Oct revision): no target or
                   name adjustments after completion, matching the card. */
                $errors[] = "That goal is already completed.";
            }
            elseif($targetAmount !== null && (int) $goal["investment"] > $targetAmount){
                /* flow.png: an edit may not undercut what is already invested. */
                $errors[] = "Target amount cannot be lower than the invested amount.";
            }
        }

        /* New goals always start at ₱0 invested; only edits carry the
           target-must-not-undercut-investment rule above. */

        if(empty($errors) && $targetAmount !== null){
            if($isEdit){
                update_goal($pdo, (int) $form["id"], $form["name"], $targetAmount, $form["target_date"]);
            }
            else{
                create_goal($pdo, $form["name"], $targetAmount, $form["target_date"]);
            }

            header("Location: /goals.php?saved=goal");
            exit;
        }

        $reopenGoalModal = true;
    }
    elseif($action === "goal_delete"){
        if($goalMissing){
            $errors[] = "That goal no longer exists.";
        }
        elseif((int) $goal["completed"] === 1){
            /* Mirrors the completed card's tooltip (26 Oct revision): the
               frozen goal reads as locked-for-completion, not blocked-by-funds. */
            $errors[] = "That goal is already completed.";
        }
        elseif((int) $goal["investment"] > 0){
            /* flow.png: Remove_Goal throws "Still has investments". The card
               disables the button with a tooltip; this is the hard stop. */
            $errors[] = "This goal still has investments. Take the funds back first.";
        }
        else{
            delete_goal($pdo, (int) $postedId);
            header("Location: /goals.php?saved=deleted");
            exit;
        }
    }
    elseif($action === "complete"){
        if($goalMissing){
            $errors[] = "That goal no longer exists.";
        }
        elseif((int) $goal["completed"] === 1){
            $errors[] = "That goal is already completed.";
        }
        elseif((int) $goal["investment"] < (int) $goal["target_amount"]){
            $errors[] = "The goal has not reached its target amount yet.";
        }
        else{
            mark_goal_complete($pdo, (int) $postedId);
            header("Location: /goals.php?saved=completed");
            exit;
        }
    }
    elseif($action === "invest" || $action === "takeback"){
        if($goalMissing){
            $errors[] = "That goal no longer exists.";
        }
        else{
            $amountInput = trim($_POST["amount"] ?? "");
            $amountError = goal_amount_error($amountInput);

            if($amountError !== null){
                $errors[] = $amountError;
            }

            /* Blocked states (per the card state machine). */
            if(empty($errors) && (int) $goal["completed"] === 1){
                $errors[] = "That goal is already completed.";
            }

            if($action === "takeback"){
                if(empty($errors) && (int) $amountInput > (int) $goal["investment"]){
                    $errors[] = "You can take back at most " . peso((int) $goal["investment"]) . ".";
                }
            }
            elseif(empty($errors) && (int) $goal["investment"] >= (int) $goal["target_amount"]){
                /* Invest locks at 100%: the user marks completion instead. */
                $errors[] = "The goal has reached its target amount. Mark it complete or take funds back.";
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

                header("Location: /goals.php?saved=" . ($action === "invest" ? "invested" : "taken"));
                exit;
            }

            if($action === "invest"){
                $investAmount = $amountInput;
                $investGoalId = $postedId;
                $investGoalName = (string) $goal["name"];
                $reopenInvestModal = true;
            }
            else{
                $takeBackAmount = $amountInput;
                $takeBackGoalId = $postedId;
                $takeBackGoalName = (string) $goal["name"];
                $takeBackCap = (int) $goal["investment"];
                $reopenTakeBackModal = true;
            }
        }
    }
    else{
        $errors[] = "Choose a valid action.";
    }
}

/* ---- list split -------------------------------------------------------- */

$ongoingGoals = [];
$completedGoals = [];
foreach($goals as $goal){
    if((int) $goal["completed"] === 1){
        $completedGoals[] = $goal;
    }
    else{
        $ongoingGoals[] = $goal;
    }
}

require_once("./partials/header.php");
require_once("./partials/sidebar.php");
?>

<main class="app-main">
    <header class="page-header">
        <div>
            <h1 class="h4 mb-1">Goals</h1>
            <p class="text-muted mb-0">Set target savings and invest toward them.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#goalModal" data-goal-new>Set New Goal</button>
    </header>

    <?php if($flashText !== ""): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flashText) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(!empty($errors) && !$reopenGoalModal && !$reopenInvestModal && !$reopenTakeBackModal): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
        /* One card renderer for both sections (docs: single implementation).
           Expects $goal, and the disabled-logic derives per card. */
        function render_goal_card(array $goal){
            $goalId = (int) $goal["id"];
            $investment = (int) $goal["investment"];
            $target = (int) $goal["target_amount"];
            $share = goal_share($investment, $target);
            $isCompleted = (int) $goal["completed"] === 1;
            $isFull = $target > 0 && $investment >= $target;
            $hasDate = trim((string) $goal["target_date"]) !== "";
            $deleteBlocked = $investment > 0;

            /* Card state machine (tasks.txt 26 Oct 2026):
               ₱0          → Invest ✓, Take Back ✗ (nothing invested)
               X < target  → Invest ✓, Take Back ✓
               100%        → Invest ✗, Take Back ✓, Complete ✓
               completed   → Invest ✗, Take Back ✗ (Complete button gone) */
            $investDisabled = $isCompleted || $isFull;
            $takeBackDisabled = $isCompleted || $investment === 0;
            /* Completed goals are fully frozen — no target/scope wiggle room
               after completion (26 Oct revision). */
            $editDisabled = $isCompleted;
            $editHint = "This goal is already completed.";
            $investHint = $isCompleted
                ? "This goal is already completed."
                : "Target reached — take funds back or mark it complete.";
            $takeBackHint = $isCompleted
                ? "This goal is already completed."
                : "Nothing has been invested yet.";
            /* 26 Oct revision: on a completed card every blocked button
               says the same thing — the funds reason would confuse there,
               since deletion can never resume on a frozen goal. Ongoing
               cards keep the true reason (money invested). */
            $deleteHint = $isCompleted
                ? "This goal is already completed."
                : "This goal still has investments. Take the funds back first.";
    ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="card h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                                    <h3 class="h5 mb-0"><?= htmlspecialchars((string) $goal["name"]) ?></h3>
                                    <?php if($isCompleted): ?>
                                        <span class="badge" style="background: var(--balance-accent); color: var(--bg-sidebar);">Completed</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Ongoing</span>
                                    <?php endif; ?>
                                </div>

                                <?php if($isCompleted): ?>
                                    <div class="tiny text-muted mb-1">Date Completed: <?= $goal["date_completed"] !== null ? htmlspecialchars((string) $goal["date_completed"]) : "&mdash;" ?></div>
                                <?php endif; ?>
                                <?php if($hasDate): ?>
                                    <div class="tiny text-muted mb-3">Target Date: <?= htmlspecialchars($goal["target_date"]) ?></div>
                                <?php else: ?>
                                    <div class="tiny text-muted mb-3">No target date set</div>
                                <?php endif; ?>

                                <div class="progress mb-1" role="progressbar"
                                     aria-label="Progress toward <?= htmlspecialchars((string) $goal["name"]) ?>"
                                     aria-valuenow="<?= number_format($share, 2) ?>" aria-valuemin="0" aria-valuemax="100"
                                     style="height: 8px;">
                                    <div class="progress-bar" style="width: <?= number_format($share, 2) ?>%; background: var(<?= ($isCompleted || $isFull) ? "--balance-accent" : "--brand" ?>);"></div>
                                </div>
                                <div class="tiny text-muted mb-3"><?= peso($investment) ?> of <?= peso($target) ?> &middot; <?= number_format($share, 2) ?>%</div>

                                <div class="d-flex flex-wrap gap-2 mt-auto">
                                    <span <?= $investDisabled ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . $investHint . '"' : "" ?>>
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#investModal"
                                                data-goal-invest data-goal-id="<?= $goalId ?>"
                                                data-goal-name="<?= htmlspecialchars((string) $goal["name"]) ?>"
                                                <?= $investDisabled ? "disabled" : "" ?>>Invest</button>
                                    </span>
                                    <span <?= $takeBackDisabled ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . $takeBackHint . '"' : "" ?>>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#takebackModal"
                                                data-goal-takeback data-goal-id="<?= $goalId ?>"
                                                data-goal-name="<?= htmlspecialchars((string) $goal["name"]) ?>"
                                                data-goal-invested="<?= $investment ?>"
                                                <?= $takeBackDisabled ? "disabled" : "" ?>>Take Investment</button>
                                    </span>
                                    <?php if($isFull && !$isCompleted): ?>
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#completeModal"
                                                data-goal-complete data-goal-id="<?= $goalId ?>"
                                                data-goal-name="<?= htmlspecialchars((string) $goal["name"]) ?>">Complete</button>
                                    <?php endif; ?>
                                    <span <?= $editDisabled ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . $editHint . '"' : "" ?>>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#goalModal"
                                                data-goal-edit data-goal-id="<?= $goalId ?>"
                                                data-goal-name="<?= htmlspecialchars($goal["name"]) ?>"
                                                data-goal-target="<?= $target ?>"
                                                data-goal-date="<?= htmlspecialchars($goal["target_date"]) ?>"
                                                <?= $editDisabled ? "disabled" : "" ?>>Edit</button>
                                    </span>
                                    <span <?= $deleteBlocked ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . $deleteHint . '"' : "" ?>>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#goalDeleteModal"
                                                data-goal-delete data-goal-id="<?= $goalId ?>"
                                                data-goal-name="<?= htmlspecialchars($goal["name"]) ?>"
                                                <?= $deleteBlocked ? "disabled" : "" ?>>Delete</button>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
    <?php
        }
    ?>

    <h2 class="h5 mb-3">On-going Goals</h2>
    <?php if(empty($ongoingGoals)): ?>
        <div class="card mb-4">
            <div class="card-body text-center py-4">
                <p class="text-muted mb-0">No ongoing goals.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <?php foreach($ongoingGoals as $goal){ render_goal_card($goal); } ?>
        </div>
    <?php endif; ?>

    <h2 class="h5 mb-3">Completed Goals</h2>
    <?php if(empty($completedGoals)): ?>
        <div class="card mb-4">
            <div class="card-body text-center py-4">
                <p class="text-muted mb-0">No completed goals yet.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <?php foreach($completedGoals as $goal){ render_goal_card($goal); } ?>
        </div>
    <?php endif; ?>

    <?php
        $goalModalAction = "/goals.php";
        require_once("./partials/goal-modals.php");
    ?>

    <!-- Invest modal (single instance, prefilled from card buttons) -->
    <div class="modal fade" id="investModal" tabindex="-1" aria-labelledby="investModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="investModalLabel"><?= $reopenInvestModal ? "Invest in " . htmlspecialchars($investGoalName) : "Invest" ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="/goals.php" id="investForm" novalidate>
                    <div class="modal-body">
                        <?php if($reopenInvestModal && !empty($errors)): ?>
                            <div class="alert alert-danger py-2" role="alert">
                                <?php foreach($errors as $investError): ?>
                                    <div><?= htmlspecialchars($investError) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <input type="hidden" name="action" value="invest">
                        <input type="hidden" name="goal_id" id="investGoalId" value="<?= htmlspecialchars($investGoalId) ?>">

                        <div class="mb-3">
                            <label class="form-label" for="investAmount">Amount</label>
                            <input type="text" inputmode="numeric" class="form-control" id="investAmount" name="amount"
                                   placeholder="Enter amount (integer only)" autocomplete="off"
                                   value="<?= htmlspecialchars($investAmount) ?>">
                        </div>

                        <div class="form-text mb-0">This amount will be added as investment on this goal and will be deducted from your balance.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Invest</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Take Back modal -->
    <div class="modal fade" id="takebackModal" tabindex="-1" aria-labelledby="takebackModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="takebackModalLabel"><?= $reopenTakeBackModal ? "Take Back from " . htmlspecialchars($takeBackGoalName) : "Take Back" ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="/goals.php" id="takebackForm" novalidate>
                    <div class="modal-body">
                        <?php if($reopenTakeBackModal && !empty($errors)): ?>
                            <div class="alert alert-danger py-2" role="alert">
                                <?php foreach($errors as $takeBackError): ?>
                                    <div><?= htmlspecialchars($takeBackError) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <input type="hidden" name="action" value="takeback">
                        <input type="hidden" name="goal_id" id="takebackGoalId" value="<?= htmlspecialchars($takeBackGoalId) ?>">

                        <div class="mb-3">
                            <label class="form-label" for="takeBackAmount">Amount</label>
                            <input type="text" inputmode="numeric" class="form-control" id="takeBackAmount" name="amount"
                                   placeholder="Enter amount (integer only)" autocomplete="off"
                                   value="<?= htmlspecialchars($takeBackAmount) ?>">
                        </div>

                        <div class="form-text mb-0" id="takebackHint"><?= $reopenTakeBackModal ? "You can take back up to " . peso($takeBackCap) . ". " : "" ?>Recorded as income back to your balance.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Take Back</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirm Completion modal -->
    <div class="modal fade" id="completeModal" tabindex="-1" aria-labelledby="completeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="completeModalLabel">Mark as Complete</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Are you sure you want to mark <strong id="completeGoalName"></strong> as complete?</p>
                </div>
                <div class="modal-footer">
                    <form method="post" action="/goals.php">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="goal_id" id="completeGoalId" value="">
                        <button type="submit" class="btn btn-primary">Confirm</button>
                    </form>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        /* Card hint tooltips (blocked Delete, etc.): the hint lives on the
           wrapper span because disabled buttons never fire hover events.
           Bootstrap bundle loads after this script, so init on DOMContentLoaded. */
        document.addEventListener("DOMContentLoaded", function(){
            if(!window.bootstrap){ return; }
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
                bootstrap.Tooltip.getOrCreateInstance(el);
            });
        });

        document.querySelectorAll("#investModal, #takebackModal, #completeModal").forEach(function(modalEl){
            if(!modalEl){ return; }

            modalEl.addEventListener("show.bs.modal", function(event){
                var button = event.relatedTarget;
                if(!button){ return; }

                var id = button.getAttribute("data-goal-id") || "";
                var name = button.getAttribute("data-goal-name") || "";

                if(modalEl.id === "investModal"){
                    var idField = document.getElementById("investGoalId");
                    var amount = document.getElementById("investAmount");
                    var label = document.getElementById("investModalLabel");
                    if(idField){ idField.value = id; }
                    if(amount){ amount.value = ""; }
                    if(label){ label.textContent = "Invest in " + name; }
                }
                else if(modalEl.id === "takebackModal"){
                    var idField = document.getElementById("takebackGoalId");
                    var amount = document.getElementById("takeBackAmount");
                    var label = document.getElementById("takebackModalLabel");
                    var hint = document.getElementById("takebackHint");
                    var cap = button.getAttribute("data-goal-invested") || "0";
                    if(idField){ idField.value = id; }
                    if(amount){ amount.value = ""; }
                    if(label){ label.textContent = "Take Back from " + name; }
                    if(hint){ hint.textContent = "You can take back up to \u20B1" + cap + ". Recorded as income back to your balance."; }
                }
                else if(modalEl.id === "completeModal"){
                    var idField = document.getElementById("completeGoalId");
                    var nameField = document.getElementById("completeGoalName");
                    if(idField){ idField.value = id; }
                    if(nameField){ nameField.textContent = name; }
                }
            });
        });

        /* After a failed goal_save POST the server re-rendered the form values;
           reopen the modal so the user sees the errors in context. */
        <?php if($reopenGoalModal): ?>
        document.addEventListener("DOMContentLoaded", function(){
            var label = document.getElementById("goalModalLabel");
            if(label){
                label.textContent = <?= $form["id"] !== "" ? '"Edit Goal"' : '"Set New Goal"' ?>;
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById("goalModal")).show();
        });
        <?php endif; ?>

        <?php if($reopenInvestModal || $reopenTakeBackModal): ?>
        document.addEventListener("DOMContentLoaded", function(){
            /* The server already rendered amount, goal id, label and hint
               into the modal; just reopen it. */
            var target = document.getElementById("<?= $reopenInvestModal ? "investModal" : "takebackModal" ?>");
            if(target){
                bootstrap.Modal.getOrCreateInstance(target).show();
            }
        });
        <?php endif; ?>
    })();
    </script>

</main>

<?php require_once("./partials/footer.php"); ?>

