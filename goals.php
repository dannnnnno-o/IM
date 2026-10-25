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

$goals = get_goals($pdo);

$flashText = match(trim($_GET["saved"] ?? "")){
    "goal"    => "Goal saved.",
    "deleted" => "Goal deleted.",
    default   => "",
};

$errors = [];
$form = ["id" => "", "name" => "", "target_amount" => "", "target_date" => ""];
$reopenGoalModal = false;

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $action = trim($_POST["action"] ?? "");

    if($action === "goal_save"){
        $form["id"] = trim($_POST["goal_id"] ?? "");
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
            if(!ctype_digit($form["id"])){
                $errors[] = "Choose a valid goal.";
            }
            elseif(get_goal($pdo, (int) $form["id"]) === null){
                $errors[] = "That goal no longer exists.";
            }
        }

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

        /* Failed validation: the shared modal partial re-opens with $form. */
        $reopenGoalModal = true;
    }
    elseif($action === "goal_delete"){
        $idInput = trim($_POST["goal_id"] ?? "");

        if(!ctype_digit($idInput)){
            $errors[] = "Choose a valid goal.";
        }
        elseif(get_goal($pdo, (int) $idInput) === null){
            $errors[] = "That goal could not be found to delete.";
        }
        else{
            delete_goal($pdo, (int) $idInput);
            header("Location: /goals.php?saved=deleted");
            exit;
        }
    }
    else{
        $errors[] = "Choose a valid action.";
    }
}

require_once("./partials/header.php");
require_once("./partials/sidebar.php");
?>

<main class="app-main">
    <header class="page-header">
        <div>
            <h1 class="h4 mb-1">Goals</h1>
            <p class="text-muted mb-0">Set savings targets and invest toward them.</p>
        </div>
    </header>

    <?php if($flashText !== ""): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flashText) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(!empty($errors) && !$reopenGoalModal): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="h5 mb-0">All Goals</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#goalModal" data-goal-new>Set New Goal</button>
    </div>

    <?php if(empty($goals)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="text-muted mb-3">No goals yet.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#goalModal" data-goal-new>Set New Goal</button>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach($goals as $goal): ?>
                <?php
                    $goalId = (int) $goal["id"];
                    $investment = (int) $goal["investment"];
                    $target = (int) $goal["target_amount"];
                    $share = goal_share($investment, $target);
                    $isComplete = $target > 0 && $investment >= $target;
                    $hasDate = trim((string) $goal["target_date"]) !== "";
                ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                                <h3 class="h5 mb-0"><?= htmlspecialchars($goal["name"]) ?></h3>
                                <?php if($isComplete): ?>
                                    <span class="badge" style="background: var(--balance-accent); color: var(--bg-sidebar);">Completed</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Ongoing</span>
                                <?php endif; ?>
                            </div>

                            <?php if($hasDate): ?>
                                <div class="tiny text-muted mb-3">Target date: <?= htmlspecialchars($goal["target_date"]) ?></div>
                            <?php else: ?>
                                <div class="tiny text-muted mb-3">No target date set</div>
                            <?php endif; ?>

                            <div class="progress mb-1" role="progressbar"
                                 aria-label="Progress toward <?= htmlspecialchars($goal["name"]) ?>"
                                 aria-valuenow="<?= $share ?>" aria-valuemin="0" aria-valuemax="100"
                                 style="height: 8px;">
                                <div class="progress-bar" style="width: <?= $share ?>%; background: var(<?= $isComplete ? "--balance-accent" : "--brand" ?>);"></div>
                            </div>
                            <div class="tiny text-muted mb-4"><?= peso($investment) ?> of <?= peso($target) ?> &middot; <?= $share ?>%</div>

                            <div class="d-flex gap-2 mt-auto">
                                <a class="btn btn-sm btn-primary" href="/goal-details.php?id=<?= $goalId ?>">View</a>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#goalModal"
                                        data-goal-edit data-goal-id="<?= $goalId ?>"
                                        data-goal-name="<?= htmlspecialchars($goal["name"]) ?>"
                                        data-goal-target="<?= $target ?>"
                                        data-goal-date="<?= htmlspecialchars($goal["target_date"]) ?>">Edit</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#goalDeleteModal"
                                        data-goal-delete data-goal-id="<?= $goalId ?>"
                                        data-goal-name="<?= htmlspecialchars($goal["name"]) ?>">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
        $goalModalAction = "/goals.php";
        require_once("./partials/goal-modals.php");
    ?>
</main>

<?php require_once("./partials/footer.php"); ?>
