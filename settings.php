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

/* ---- Manage Categories (Sprint 15, 29 Oct 2026) ---- */

/* The whole pickable map (predefined + customs, Goal Investment excluded)
   drives the section table; read-only by default, Edit toggles ON. Types
   that came from overrides or customs are already merged in here. */
$managedCategories = category_type_map($pdo, $userId);

/* "Do not show this again" flags live in the users row (user decision —
   no browser storage); read once, mirrored into data-attributes for the
   script below. */
$skipRenameConfirm = (bool) ($user["skip_rename_confirm"] ?? false);
$skipRemoveConfirm = (bool) ($user["skip_remove_confirm"] ?? false);

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
        <div class="alert alert-danger" role="alert">
            <?php foreach($errors as $settingsError): ?>
                <div><?= htmlspecialchars($settingsError) ?></div>
            <?php endforeach; ?>
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

    <!-- Manage Categories (Sprint 15): read-only by default — the Edit
         button is the single gate INTO editing and never toggles OFF
         (Cancel / Save Changes own the exit). Type checkboxes stage and
         apply via Save Changes; rename/remove act immediately through
         their modals. Predefined rows: type editable, name/deletion not
         (dimmed actions + hover tooltip). Goal Investment is absent by
         design (system label). -->
    <section class="card mb-4" id="manage-categories"
             data-skip-rename="<?= $skipRenameConfirm ? "1" : "0" ?>"
             data-skip-remove="<?= $skipRemoveConfirm ? "1" : "0" ?>">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h5 mb-0">Manage Categories</h2>
                <button type="button" class="btn-add-category" id="mcEditBtn">Edit</button>
            </div>

            <table class="table align-middle mb-3" id="mcTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="text-center" style="width:6em;">Income</th>
                        <th class="text-center" style="width:6em;">Expense</th>
                        <th class="text-end" style="width:6em;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($managedCategories as $mcName => $mcType):
                        $mcCustom = !isset(PREDEFINED_PICKABLE_CATEGORIES[$mcName]);
                        $mcIncome = $mcType === CATEGORY_INCOME || $mcType === CATEGORY_BOTH;
                        $mcExpense = $mcType === CATEGORY_EXPENSE || $mcType === CATEGORY_BOTH;
                    ?>
                    <tr data-category="<?= htmlspecialchars($mcName) ?>"
                        data-custom="<?= $mcCustom ? "1" : "0" ?>">
                        <td class="mc-name"><?= htmlspecialchars($mcName) ?></td>
                        <td class="text-center">
                            <input class="form-check-input mc-type" type="checkbox"
                                   data-kind="income" <?= $mcIncome ? "checked" : "" ?> disabled>
                        </td>
                        <td class="text-center">
                            <input class="form-check-input mc-type" type="checkbox"
                                   data-kind="expense" <?= $mcExpense ? "checked" : "" ?> disabled>
                        </td>
                        <td class="text-end">
                            <?php if($mcCustom): ?>
                                <button type="button" class="mc-icon-btn mc-rename" disabled
                                        aria-label="Rename category" title="Rename category">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5.2 2.6a.5.5 0 0 1-.63-.63l2.6-5.2a.5.5 0 0 1 .11-.168l10-10zM11.207 2.791l1.585 1.585 1.086-1.086-1.586-1.585-1.085 1.086zm1.985 2.798-2.553-2.553L4 9.586V12h2.414L13.192 5.589z"/></svg>
                                </button>
                                <button type="button" class="mc-icon-btn mc-remove text-danger" disabled
                                        aria-label="Remove category" title="Remove category">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
                                </button>
                            <?php else: ?>
                                <!-- Disabled buttons emit no hover, so the wrapper
                                     span carries the tooltip (§6c pattern family). -->
                                <span class="d-inline-block"
                                      data-bs-toggle="tooltip" data-bs-placement="top"
                                      title="Built-in categories can't be renamed or removed.">
                                    <button type="button" class="mc-icon-btn" disabled
                                            aria-label="Rename category">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5.2 2.6a.5.5 0 0 1-.63-.63l2.6-5.2a.5.5 0 0 1 .11-.168l10-10zM11.207 2.791l1.585 1.585 1.086-1.086-1.586-1.585-1.085 1.086zm1.985 2.798-2.553-2.553L4 9.586V12h2.414L13.192 5.589z"/></svg>
                                    </button>
                                    <button type="button" class="mc-icon-btn" disabled
                                            aria-label="Remove category">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
                                    </button>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="alert alert-danger py-2 mb-3 d-none" id="mcTypeError" role="alert"></div>

            <div class="d-flex justify-content-end gap-2 d-none" id="mcEditActions">
                <button type="button" class="btn btn-outline-secondary" id="mcCancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="mcSave">Save Changes</button>
            </div>
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

    <!-- Rename Category, step 1: input modal (custom categories only). -->
    <div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="renameModalLabel">Rename Category</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="renameName">Input new name for
                            "<span id="renameFromLabel"></span>"</label>
                        <input type="text" class="form-control" id="renameName"
                               maxlength="<?= MAX_CATEGORY_NAME_LENGTH ?>" autocomplete="off"
                               placeholder="Category Name">
                        <div class="form-text"><span id="renameCount">0</span>/<?= MAX_CATEGORY_NAME_LENGTH ?> characters</div>
                    </div>
                    <div class="alert alert-danger py-2 mb-0 d-none" id="renameError" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary" id="renameNext">Rename</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rename Category, step 2: confirm with the relabel scope spelled out.
         "Do not show this again" persists in the users row via the same
         rename POST (skip_confirm=1). -->
    <div class="modal fade" id="renameConfirmModal" tabindex="-1" aria-labelledby="renameConfirmLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="renameConfirmLabel">Rename Category</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Are you sure you want to rename
                        "<strong id="renameConfirmFrom"></strong>" to
                        "<strong id="renameConfirmTo"></strong>"? This will change every
                        related transaction to match the new name.
                    </p>
                    <p class="mb-2">This will reflect on the History and Summary tabs.</p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="renameSkipCheck">
                        <label class="form-check-label" for="renameSkipCheck">Do not show this again.</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger" id="renameProceed">Proceed</button>
                    <button type="button" class="btn btn-outline-secondary" id="renameBack">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Remove Category confirm: the note is the user-approved frame copy —
         removal is picker-only, past transactions keep the stored name. -->
    <div class="modal fade" id="removeModal" tabindex="-1" aria-labelledby="removeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="removeModalLabel">Remove Category</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Note: This will only make this category unusable for future
                        transactions. Any past transactions will be left untouched,
                        and will retain this category.
                    </p>
                    <p class="mb-2">
                        Are you sure you want to remove
                        "<strong id="removeLabel"></strong>"?
                    </p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="removeSkipCheck">
                        <label class="form-check-label" for="removeSkipCheck">Do not show this again.</label>
                    </div>
                    <div class="alert alert-danger py-2 mb-0 d-none mt-2" id="removeError" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger" id="removeProceed">Remove</button>
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

    <script>
    (function(){
        var section   = document.getElementById("manage-categories");
        var editBtn   = document.getElementById("mcEditBtn");
        var saveBtn   = document.getElementById("mcSave");
        var cancelBtn = document.getElementById("mcCancel");
        var editRow   = document.getElementById("mcEditActions");
        var errBox    = document.getElementById("mcTypeError");
        var table     = document.getElementById("mcTable");
        if(!section || !editBtn || !saveBtn || !cancelBtn || !editRow || !errBox || !table){
            return;
        }

        var editing = false;
        var staged = [];   /* checked-state snapshots taken when Edit was clicked */

        var rowName = function(row){ return row.getAttribute("data-category"); };
        var rowType = function(row){
            var income = row.querySelector(".mc-type[data-kind='income']").checked;
            var expense = row.querySelector(".mc-type[data-kind='expense']").checked;
            return income && expense ? "both" : (income ? "income" : (expense ? "expense" : ""));
        };
        var showTypeError = function(message){
            errBox.textContent = message;
            errBox.classList.remove("d-none");
        };
        var hideTypeError = function(){
            errBox.classList.add("d-none");
        };

        /* Edit toggles editing ON and never off — it is disabled while
           editing (user rule), and only Cancel / Save Changes exit. */
        editBtn.addEventListener("click", function(){
            if(editing){ return; }
            editing = true;

            staged = [];
            table.querySelectorAll("tbody tr").forEach(function(row){
                staged.push({
                    row: row,
                    income: row.querySelector(".mc-type[data-kind='income']").checked,
                    expense: row.querySelector(".mc-type[data-kind='expense']").checked,
                });
            });

            table.querySelectorAll(".mc-type").forEach(function(box){
                box.disabled = false;
            });
            table.querySelectorAll("tr[data-custom='1'] .mc-rename, tr[data-custom='1'] .mc-remove")
                .forEach(function(btn){ btn.disabled = false; });

            editRow.classList.remove("d-none");
            editBtn.disabled = true;
            hideTypeError();
        });

        var leaveEdit = function(restore){
            if(restore){
                staged.forEach(function(snap){
                    snap.row.querySelector(".mc-type[data-kind='income']").checked = snap.income;
                    snap.row.querySelector(".mc-type[data-kind='expense']").checked = snap.expense;
                });
            }
            staged = [];
            editing = false;

            table.querySelectorAll(".mc-type").forEach(function(box){
                box.disabled = true;
            });
            table.querySelectorAll(".mc-rename, .mc-remove").forEach(function(btn){
                btn.disabled = true;
            });

            editRow.classList.add("d-none");
            editBtn.disabled = false;
            hideTypeError();
        };

        cancelBtn.addEventListener("click", function(){
            leaveEdit(true);
        });

        saveBtn.addEventListener("click", function(){
            var types = {};
            var blank = false;
            table.querySelectorAll("tbody tr").forEach(function(row){
                var type = rowType(row);
                if(type === ""){
                    blank = true;
                }
                types[rowName(row)] = type;
            });

            if(blank){
                showTypeError("Each category must allow at least one mode.");
                return;
            }

            hideTypeError();
            saveBtn.disabled = true;

            var payload = new FormData();
            payload.append("action", "save_types");
            payload.append("types", JSON.stringify(types));

            fetch("/categories.php", { method: "POST", body: payload })
                .then(function(response){
                    return response.json().then(function(data){
                        return { status: response.status, data: data };
                    });
                })
                .then(function(result){
                    if(!result.data || result.data.ok !== true){
                        showTypeError((result.data && result.data.error) || "Could not save the changes.");
                        return;
                    }
                    leaveEdit(false);
                })
                .catch(function(){
                    showTypeError("Could not reach the server. Try again.");
                })
                .finally(function(){
                    saveBtn.disabled = false;
                });
        });

        /* ---- Rename flow (custom categories, step 1: input) ---- */

        var renameModalEl  = document.getElementById("renameModal");
        var renameConfirmEl = document.getElementById("renameConfirmModal");
        var renameInput    = document.getElementById("renameName");
        var renameCount    = document.getElementById("renameCount");
        var renameError    = document.getElementById("renameError");
        var renameNextBtn  = document.getElementById("renameNext");
        var renameFromLbl  = document.getElementById("renameFromLabel");
        var renameConfFrom = document.getElementById("renameConfirmFrom");
        var renameConfTo   = document.getElementById("renameConfirmTo");
        var renameSkip     = document.getElementById("renameSkipCheck");
        var renameProceed  = document.getElementById("renameProceed");
        var renameBack     = document.getElementById("renameBack");
        var currentRename  = "";

        renameInput.addEventListener("input", function(){
            renameCount.textContent = Array.from(renameInput.value).length;
        });

        table.addEventListener("click", function(event){
            var button = event.target.closest(".mc-rename, .mc-remove");
            if(!button || button.disabled){ return; }

            var row = button.closest("tr");
            var name = rowName(row);
            if(!name){ return; }

            if(button.classList.contains("mc-rename")){
                currentRename = name;
                renameFromLbl.textContent = name;
                renameInput.value = "";
                renameCount.textContent = "0";
                renameError.classList.add("d-none");
                bootstrap.Modal.getOrCreateInstance(renameModalEl).show();
            }
            else{
                document.getElementById("removeLabel").textContent = name;
                removeError.classList.add("d-none");

                /* Skip flag set: no confirm modal — trash IS the execution. */
                if(section.getAttribute("data-skip-remove") === "1"){
                    performRemove(name, false);
                    return;
                }

                bootstrap.Modal.getOrCreateInstance(removeModalEl).show();
            }
        });

        renameNextBtn.addEventListener("click", function(){
            var newName = renameInput.value.trim();
            if(newName === ""){
                renameError.textContent = "Category name is required.";
                renameError.classList.remove("d-none");
                return;
            }

            renameConfFrom.textContent = currentRename;
            renameConfTo.textContent = newName;
            renameSkip.checked = false;
            renameError.classList.add("d-none");

            /* Skip flag set: no confirm step — the input modal's Rename IS
               the execution. The modal STAYS OPEN during the fetch so a
               failure surfaces inline; success closes it below. */
            if(section.getAttribute("data-skip-rename") === "1"){
                renameProceedExecute();
                return;
            }

            var inputModal = bootstrap.Modal.getInstance(renameModalEl);
            if(inputModal){ inputModal.hide(); }
            bootstrap.Modal.getOrCreateInstance(renameConfirmEl).show();
        });

        /* Cancel on step 2 returns to the input modal (mockup back-flow). */
        renameBack.addEventListener("click", function(){
            var confirmModal = bootstrap.Modal.getInstance(renameConfirmEl);
            if(confirmModal){ confirmModal.hide(); }
            bootstrap.Modal.getOrCreateInstance(renameModalEl).show();
        });

        var postCategoryAction = function(payload){
            return fetch("/categories.php", { method: "POST", body: payload })
                .then(function(response){
                    return response.json().then(function(data){
                        return data;
                    });
                });
        };

        var renameProceedExecute = function(){
            renameProceed.disabled = true;

            var payload = new FormData();
            payload.append("action", "rename");
            payload.append("current", currentRename);
            payload.append("name", renameConfTo.textContent);
            if(renameSkip.checked){
                payload.append("skip_confirm", "1");
            }

            postCategoryAction(payload)
                .then(function(data){
                    if(!data || data.ok !== true){
                        /* Error text first, THEN show — a show() issued
                           during another modal's hide transition silently
                           no-ops in Bootstrap. */
                        renameError.textContent = (data && data.error) || "Could not rename the category.";
                        renameError.classList.remove("d-none");
                        if(!renameModalEl.classList.contains("show")){
                            bootstrap.Modal.getOrCreateInstance(renameModalEl).show();
                        }
                        return;
                    }

                    if(renameModalEl.classList.contains("show")){
                        bootstrap.Modal.getOrCreateInstance(renameModalEl).hide();
                    }
                    var confirmModal = bootstrap.Modal.getInstance(renameConfirmEl);
                    if(confirmModal && renameConfirmEl.classList.contains("show")){
                        confirmModal.hide();
                    }

                    table.querySelectorAll("tbody tr").forEach(function(row){
                        if(rowName(row).toLowerCase() === currentRename.toLowerCase()){
                            row.setAttribute("data-category", renameConfTo.textContent);
                            row.querySelector(".mc-name").textContent = renameConfTo.textContent;
                        }
                    });

                    if(renameSkip.checked){
                        section.setAttribute("data-skip-rename", "1");
                    }
                })
                .catch(function(){
                    renameError.textContent = "Could not reach the server. Try again.";
                    renameError.classList.remove("d-none");
                    if(!renameModalEl.classList.contains("show")){
                        bootstrap.Modal.getOrCreateInstance(renameModalEl).show();
                    }
                })
                .finally(function(){
                    renameProceed.disabled = false;
                });
        };

        renameProceed.addEventListener("click", renameProceedExecute);

        /* The confirm is skipped entirely once persisted (per-browser it
           would be localStorage; here it is a users-row column, so it is
           per-account everywhere). */
        var editOrConfirm = function(stepTwoFn, key){
            if(section.getAttribute(key) === "1"){ return; }
        };

        /* ---- Remove flow ---- */

        var removeModalEl   = document.getElementById("removeModal");
        var removeError     = document.getElementById("removeError");
        var removeSkip      = document.getElementById("removeSkipCheck");
        var removeProceed   = document.getElementById("removeProceed");

        var applyRemoveSuccess = function(name, flagWasChecked){
            var modal = bootstrap.Modal.getInstance(removeModalEl);
            if(modal){ modal.hide(); }

            table.querySelectorAll("tbody tr").forEach(function(row){
                if(rowName(row) === name){ row.remove(); }
            });

            if(flagWasChecked){
                section.setAttribute("data-skip-remove", "1");
            }
        };

        var performRemove = function(name, flagWasChecked){
            removeProceed.disabled = true;

            var payload = new FormData();
            payload.append("action", "remove");
            payload.append("name", name);
            if(flagWasChecked){
                payload.append("skip_confirm", "1");
            }

            postCategoryAction(payload)
                .then(function(data){
                    if(!data || data.ok !== true){
                        removeError.textContent = (data && data.error) || "Could not remove the category.";
                        removeError.classList.remove("d-none");
                        bootstrap.Modal.getOrCreateInstance(removeModalEl).show();
                        return;
                    }
                    applyRemoveSuccess(name, flagWasChecked);
                })
                .catch(function(){
                    removeError.textContent = "Could not reach the server. Try again.";
                    removeError.classList.remove("d-none");
                    bootstrap.Modal.getOrCreateInstance(removeModalEl).show();
                })
                .finally(function(){
                    removeProceed.disabled = false;
                });
        };

        removeProceed.addEventListener("click", function(){
            performRemove(document.getElementById("removeLabel").textContent, removeSkip.checked);
        });
    })();
    </script>

</main>

<?php require_once("./partials/footer.php"); ?>
