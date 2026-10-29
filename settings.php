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

/* First-visit gate, same as the dashboard (§6): Settings is a named-user
   page — an unnamed account belongs on the landing prompt. */
if(username_is_missing($user)){
    header("Location: /");
    exit;
}

$pageTitle = "Settings";
$activePage = "settings";

/* ---- request state ----------------------------------------------------- */

/* Danger-zone modal copy is server-rendered with live counts (Sprint 12);
   a confirm modal that names its blast radius reads better than a generic
   "are you sure". */
$txCount   = count_rows($pdo, "transactions");
$goalCount = count_rows($pdo, "goals");

/* Custom-category preview for the Clear modal checkbox: at most
   CATEGORY_PREVIEW_LIMIT names, then "..." if the account has more. User
   input — escaped at render like every other category name (§10). */
const CATEGORY_PREVIEW_LIMIT = 3;

$customCategories = get_custom_categories($pdo, $userId);
$categoryPreview  = implode(", ", array_slice($customCategories, 0, CATEGORY_PREVIEW_LIMIT));

if(count($customCategories) == 0){
    $category_msg = "No Custom Categories have been added.";
}
elseif(count($customCategories) == 1){
    $category_msg = "Custom Category Added: \"". $categoryPreview . "\"";
}
elseif(count($customCategories) > 0 && count($customCategories) <= CATEGORY_PREVIEW_LIMIT){
    $category_msg = "Custom Categories: \"". $categoryPreview . "\"";
}
elseif(count($customCategories) > CATEGORY_PREVIEW_LIMIT){
    $category_msg = "Custom Categories: \"";
    $category_msg = $category_msg. $categoryPreview . "...\"";
}

/* The checkbox only earns its keep when there is something to erase:
   zero custom categories ⇒ disabled + wrapper-span tooltip (markup below). */
$hasCustomCategories = count($customCategories) > 0;

$flashText = match(trim($_GET["saved"] ?? "")){
    "profile" => "Name updated.",
    "cleared" => "All records cleared.",
    default   => "",
};

$errors = [];
$form = ["name" => (string) $user["username"]];

/* ---- POST actions ------------------------------------------------------ */

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $action = trim($_POST["action"] ?? "");

    if($action === "profile"){
        $form["name"] = trim($_POST["name"] ?? "");

        if($form["name"] === ""){
            $errors[] = "Name is required.";
        }
        elseif(mb_strlen($form["name"]) > MAX_USERNAME_LENGTH){
            /* Same expression as index.php's landing table — one message
               family (§8), length single-sourced via MAX_USERNAME_LENGTH. */
            $errors[] = "Name must be " . MAX_USERNAME_LENGTH . " characters or fewer.";
        }

        if(empty($errors)){
            /* save_username() writes username ONLY (prepared) — balance and
               categories are untouched, same as the landing flow. */
            save_username($pdo, $userId, $form["name"]);
            header("Location: /settings.php?saved=profile");
            exit;
        }
        /* Failure re-renders below with $form["name"] kept in the input. */
    }
    elseif($action === "clear"){
        /* The modal checkbox is opt-in: absent when unchecked, "1" when
           checked. Default keeps the user's custom categories (§14). */
        clear_records_and_balance($pdo, $userId, isset($_POST["clear_categories"]));
        reset_record_counters($pdo);
        header("Location: /settings.php?saved=cleared");
        exit;
    }
    elseif($action === "reset"){
        factory_reset_user($pdo, $userId);
        reset_record_counters($pdo);
        /* The first-visit gate is open again — land on the prompt. */
        header("Location: /");
        exit;
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
            <h1 class="h4 mb-1">Settings</h1>
            <p class="text-muted mb-0">Manage your profile and your data.</p>
        </div>
    </header>

    <?php if($flashText !== ""): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $flashText ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php foreach($errors as $settingsError): ?>
                <div><?= htmlspecialchars($settingsError) ?></div>
            <?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Profile: edit the dashboard-greeting name. Reversible, so no confirm
         modal — only the Danger Zone earns one. -->
    <section class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">Profile</h2>
            <form method="post" action="/settings.php" novalidate>
                <input type="hidden" name="action" value="profile">
                <div class="mb-3">
                    <label class="form-label" for="settingsName">Name</label>
                    <input type="text" class="form-control" id="settingsName" name="name"
                           maxlength="<?= MAX_USERNAME_LENGTH ?>" autocomplete="off"
                           value="<?= htmlspecialchars($form["name"]) ?>">
                    <div class="form-text mb-0">
                        The name shown in your dashboard greeting.
                        Up to <?= MAX_USERNAME_LENGTH ?> characters.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Name</button>
            </form>
        </div>
    </section>

    <!-- Danger Zone: the two destructive actions. Details + confirmation
         live in the modals below (counts interpolated server-side). -->
    <section class="card mb-4">
        <div class="card-body">
            <h2 class="h5 text-danger mb-1">Danger Zone</h2>
            <p class="text-muted small mb-3">These actions are permanent and cannot be undone.</p>

            <div class="d-flex flex-column flex-md-row gap-3">
                <div class="border rounded p-3 flex-fill">
                    <strong class="d-block mb-1">Clear data &amp; reset balance</strong>
                    <p class="text-muted small mb-2">
                        Erases every transaction and goal — including their fund
                        history — and resets your balance to <?= peso(0) ?>.
                    </p>
                    <button type="button" class="btn btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#clearModal">
                        Clear Data
                    </button>
                </div>

                <div class="border rounded p-3 flex-fill">
                    <strong class="d-block mb-1">Delete account</strong>
                    <p class="text-muted small mb-2">
                        Factory reset: everything is wiped — even your name.
                    </p>
                    <button type="button" class="btn btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#resetModal">
                        Delete Account
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Clear data confirm: names the blast radius + the categories checkbox
         (opt-in destructive extra, default keeps categories). -->
    <div class="modal fade" id="clearModal" tabindex="-1" aria-labelledby="clearModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="clearModalLabel">Clear Data &amp; Reset Balance</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="/settings.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="clear">
                        <p class="mb-3">
                            This permanently erases
                            <strong><?= $txCount ?> transaction<?= $txCount === 1 ? "" : "s" ?></strong> and
                            <strong><?= $goalCount ?> goal<?= $goalCount === 1 ? "" : "s" ?></strong>
                            — including their fund history — and resets your balance
                            to <strong><?= peso(0) ?></strong>.
                        </p>
                        <?php $clearTooltip = $hasCustomCategories ? "" : ' data-bs-toggle="tooltip" data-bs-placement="top" title="No Custom Categories can be cleared."'; ?>
                        <div class="form-check mb-2"<?= $clearTooltip ?>>
                            <?php if($hasCustomCategories): ?>
                                <input class="form-check-input" type="checkbox" id="clearCategories"
                                       name="clear_categories" value="1">
                            <?php else: ?>
                                <!-- Disabled needs no wrapper-span (§6c): the checkbox
                                     is position:absolute inside an inline span, so the
                                     wrapping span collapses and Chromium hovers the
                                     label instead. The tooltip anchors on the whole
                                     .form-check row — a real in-flow element that
                                     reliably receives hover. -->
                                <input class="form-check-input" type="checkbox" id="clearCategories"
                                       name="clear_categories" value="1" disabled>
                            <?php endif; ?>
                            <label class="form-check-label <?= $hasCustomCategories ? "" : "text-muted" ?>"
                                   for="clearCategories">
                                Also clear my custom categories
                            </label>
                            <div class="form-text">
                                <?= htmlspecialchars($category_msg) ?>
                            </div>
                        </div>
                        <p class="text-muted small mb-0 d-none" id="clearCategoriesWarning">
                            Your custom categories will also be erased. This cannot be undone.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-outline-danger">Clear Data</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Factory reset confirm: the wording states the identity wipe
         explicitly — this is the one action that signs the user out of
         their name, back to the landing prompt. -->
    <div class="modal fade" id="resetModal" tabindex="-1" aria-labelledby="resetModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="resetModalLabel">Delete Account</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        This wipes <strong>everything</strong> — all records, your
                        balance, your custom categories,
                        <em>and your name</em>.
                    </p>
                    <p class="mb-0">You will be asked for a new name again after this action.</p>
                </div>
                <div class="modal-footer">
                    <form method="post" action="/settings.php">
                        <input type="hidden" name="action" value="reset">
                        <button type="submit" class="btn btn-outline-danger">Delete Account</button>
                    </form>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        /* Reveal the "cannot be undone" line only while the opt-in category
           checkbox is ticked. Vanilla DOM only — no bootstrap.* at parse
           time (the bundle loads after page scripts, §6c trap). */
        var box = document.getElementById("clearCategories");
        var warning = document.getElementById("clearCategoriesWarning");
        if(box && warning){
            var sync = function(){
                warning.classList.toggle("d-none", !box.checked);
            };
            box.addEventListener("change", sync);
            sync();
        }

        /* Disabled-checkbox wrapper tooltip (§6c wrapper-span pattern),
           initialized after the bundle lands. */
        document.addEventListener("DOMContentLoaded", function(){
            if(!window.bootstrap){ return; }
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el){
                bootstrap.Tooltip.getOrCreateInstance(el);
            });
        });
    })();
    </script>

</main>

<?php require_once("./partials/footer.php"); ?>
