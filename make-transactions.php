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

$categories = all_categories($pdo, $userId);

$pageTitle = "Make Transaction";
$activePage = "update";

$errors = [];
$form = ["mode" => "income", "amount" => "", "category" => "", "comment" => ""];

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $form["mode"] = trim($_POST["mode"] ?? "");
    $form["amount"] = trim($_POST["amount"] ?? "");
    $form["category"] = trim($_POST["category"] ?? "");
    $form["comment"] = trim($_POST["comment"] ?? "");

    $mode = match($form["mode"]){
        "income" => "+",
        "expense" => "-",
        default => null,
    };

    if($mode === null){
        $form["mode"] = "income";
        $errors[] = "Choose a valid transaction mode.";
    }

    $amount = null;
    if($form["amount"] === ""){
        $errors[] = "Amount is required.";
    }
    elseif(!ctype_digit($form["amount"]) || (int) $form["amount"] <= 0){
        $errors[] = "Amount must be a whole positive number.";
    }
    elseif(strlen($form["amount"]) > 10 || (int) $form["amount"] > 2147483647){
        $errors[] = "Amount is too large.";
    }
    else{
        $amount = (int) $form["amount"];
    }

    if($form["category"] === ""){
        $errors[] = "Category is required.";
    }
    elseif(!category_is_known($pdo, $userId, $form["category"])){
        $errors[] = "Choose a valid category.";
    }

    if(mb_strlen($form["comment"]) > 500){
        $errors[] = "Comment must be 500 characters or fewer.";
    }

    if(empty($errors) && $amount !== null){
        $comment = $form["comment"] !== "" ? $form["comment"] : null;
        create_transaction($pdo, $mode, $amount, $form["category"], $comment, $userId);
        header("Location: /dashboard.php?saved=" . ($mode === "+" ? "income" : "expense"));
        exit;
    }
}

require_once("./partials/header.php");
require_once("./partials/sidebar.php");
?>

<main class="app-main">
    <header class="page-header is-centered">
        <div>
            <h1 class="h4 mb-1">Make Transaction</h1>
            <p class="text-muted mb-0">Log an income or expense against your balance.</p>
        </div>
    </header>

    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body p-4">

                    <?php if(!empty($errors)): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php foreach($errors as $error): ?>
                                <div><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/make-transactions.php" novalidate>
                        <div class="mb-3">
                            <label class="form-label" for="mode">Transaction Mode</label>
                            <select class="form-select" id="mode" name="mode">
                                <option value="income"  <?= $form["mode"] === "income"  ? "selected" : "" ?>>Income</option>
                                <option value="expense" <?= $form["mode"] === "expense" ? "selected" : "" ?>>Expense</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="amount">Amount</label>
                            <input type="text" inputmode="numeric" class="form-control"
                                   id="amount" name="amount"
                                   placeholder="Enter amount (integer only)"
                                   value="<?= htmlspecialchars($form["amount"]) ?>"
                                   autocomplete="off">
                        </div>

                        <div class="mb-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <label class="form-label" for="category">Category</label>
                                <button type="button" class="btn-add-category" data-bs-toggle="modal" data-bs-target="#addCategoryModal">+ Add Category</button>
                            </div>
                            <select class="form-select" id="category" name="category" required>
                                <option value="" disabled <?= $form["category"] === "" ? "selected" : "" ?>>Choose a category&hellip;</option>
                                <?php foreach($categories as $categoryName): ?>
                                    <option value="<?= htmlspecialchars($categoryName) ?>"
                                        <?= $form["category"] === $categoryName ? "selected" : "" ?>>
                                        <?= htmlspecialchars($categoryName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="comment">Comment (Optional)</label>
                            <textarea class="form-control" style="resize: none;" rows="5" maxlength="500"
                                      id="comment" name="comment"
                                      placeholder="Add more detail about this transaction."><?= htmlspecialchars($form["comment"]) ?></textarea>
                            <div class="form-text"><span id="commentCount">0</span>/500 characters</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Confirm Transaction</button>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="addCategoryLabel">Add Category</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addCategoryForm" novalidate>
                        <div class="mb-3">
                            <label class="form-label" for="newCategoryName">Category Name</label>
                            <input type="text" class="form-control" id="newCategoryName"
                                   maxlength="30" placeholder="e.g. School, Savings, etc.">
                            <div class="form-text"><span id="newCategoryCount">0</span>/30 characters</div>
                        </div>
                        <div id="addCategoryError" class="alert alert-danger py-2 mb-0 d-none" role="alert"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveCategory">Save</button>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function(){
    var box = document.getElementById("comment");
    var count = document.getElementById("commentCount");
    if(box && count){
        function sync(){
            count.textContent = Array.from(box.value).length;
        }

        box.addEventListener("input", sync);
        sync();
    }

    var nameBox = document.getElementById("newCategoryName");
    var nameCount = document.getElementById("newCategoryCount");
    if(nameBox && nameCount){
        function syncName(){
            nameCount.textContent = Array.from(nameBox.value).length;
        }

        nameBox.addEventListener("input", syncName);
        syncName();
    }

    var addForm = document.getElementById("addCategoryForm");
    var saveButton = document.getElementById("saveCategory");
    var errorBox = document.getElementById("addCategoryError");
    var modalEl = document.getElementById("addCategoryModal");
    var categorySelect = document.getElementById("category");

    if(addForm){
        addForm.addEventListener("submit", function(event){
            event.preventDefault();
            saveButton.click();
        });
    }

    if(saveButton && errorBox && modalEl && categorySelect){
        function showAddError(message){
            errorBox.textContent = message;
            errorBox.classList.remove("d-none");
        }

        saveButton.addEventListener("click", function(){
            var name = nameBox.value.trim();
            if(name === ""){
                showAddError("Category name is required.");
                return;
            }

            saveButton.disabled = true;
            errorBox.classList.add("d-none");

            var payload = new FormData();
            payload.append("name", name);

            fetch("/categories.php", { method: "POST", body: payload })
                .then(function(response){
                    return response.json().then(function(data){
                        return { status: response.status, data: data };
                    });
                })
                .then(function(result){
                    if(!result.data || result.data.ok !== true){
                        showAddError((result.data && result.data.error) || "Could not add the category.");
                        return;
                    }

                    var lower = name.toLowerCase();
                    var existing = null;
                    for(var i = 0; i < categorySelect.options.length; i++){
                        if(categorySelect.options[i].value.toLowerCase() === lower){
                            existing = categorySelect.options[i];
                            break;
                        }
                    }

                    if(!existing){
                        existing = new Option(name, name);
                        categorySelect.add(existing);
                    }
                    existing.selected = true;

                    nameBox.value = "";
                    nameCount.textContent = "0";

                    var modal = bootstrap.Modal.getInstance(modalEl);
                    if(modal){
                        modal.hide();
                    }
                })
                .catch(function(){
                    showAddError("Could not reach the server. Try again.");
                })
                .finally(function(){
                    saveButton.disabled = false;
                });
        });
    }
})();
</script>

<?php require_once("./partials/footer.php"); ?>