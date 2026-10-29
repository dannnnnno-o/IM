<?php
/* Shared goal modals: Set/Edit Goal + Delete Goal confirm.
   Page scope expects:
     $goalModalAction  — POST target for the goal form (both modals)
     $form             — ["id", "name", "target_amount", "target_date"] state
                         (defaults on first load; failed-POST values on reopen)
     $errors           — validation errors for the last goal_save POST
     $reopenGoalModal  — true after a failed goal_save POST to auto-show

   Behaviour notes:
   - "Set New Goal" buttons carry data-goal-new and reset the form via JS.
   - Row "Edit" buttons carry data-goal-edit + data-goal-* attributes and
     prefill the same single modal.
   - Row "Delete" buttons carry data-goal-delete + data-goal-* attributes
     which prefill the confirm modal. */
$goalModalAction = $goalModalAction ?? "/goals.php";
$form = $form ?? ["id" => "", "name" => "", "target_amount" => "", "target_date" => ""];
$errors = $errors ?? [];
$reopenGoalModal = !empty($reopenGoalModal);
?>
<div class="modal fade" id="goalModal" tabindex="-1" aria-labelledby="goalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="goalModalLabel">Set New Goal</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="<?= htmlspecialchars($goalModalAction) ?>" id="goalForm" novalidate>
                <div class="modal-body">
                    <?php if($reopenGoalModal && !empty($errors)): ?>
                        <div class="alert alert-danger py-2" role="alert">
                            <?php foreach($errors as $goalError): ?>
                                <div><?= htmlspecialchars($goalError) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="action" value="goal_save">
                    <input type="hidden" name="goal_id" id="goalId" value="<?= htmlspecialchars($form["id"]) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="goalName">Goal Name</label>
                        <input type="text" autocomplete="off" class="form-control" id="goalName" name="name" maxlength="255"
                               placeholder="e.g. New Laptop, Emergency Fund" value="<?= htmlspecialchars($form["name"]) ?>">
                        <div class="form-text"><span id="goalNameCount">0</span>/255 characters</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="goalTarget">Target Amount</label>
                        <input type="text" autocomplete="off" inputmode="numeric" class="form-control" id="goalTarget" name="target_amount"
                               placeholder="Enter amount (integer only)" value="<?= htmlspecialchars($form["target_amount"]) ?>">
                    </div>

                    <div class="mb-1">
                        <label class="form-label" for="goalDate">Target Date</label>
                        <input type="text" autocomplete="off" class="form-control" id="goalDate" name="target_date" maxlength="255"
                               placeholder="e.g. November 5, 2026 — free text, no validation"
                               value="<?= htmlspecialchars($form["target_date"]) ?>">
                        <div class="form-text">Any format is accepted; the app does not validate goal dates.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="goalDeleteModal" tabindex="-1" aria-labelledby="goalDeleteLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="goalDeleteLabel">Delete Goal</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete <strong id="goalDeleteName"></strong>? This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form method="post" action="<?= htmlspecialchars($goalModalAction) ?>">
                    <input type="hidden" name="action" value="goal_delete">
                    <input type="hidden" name="goal_id" id="goalDeleteId" value="">
                    <button type="submit" class="btn btn-outline-danger">Delete</button>
                </form>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    /* Goal name character counter. */
    var nameBox = document.getElementById("goalName");
    var nameCount = document.getElementById("goalNameCount");
    if(nameBox && nameCount){
        function syncName(){
            nameCount.textContent = Array.from(nameBox.value).length;
        }

        nameBox.addEventListener("input", syncName);
        syncName();
    }

    /* Row Edit buttons prefill the shared modal. */
    document.querySelectorAll("[data-goal-edit]").forEach(function(button){
        button.addEventListener("click", function(){
            var set = function(fieldId, value){
                var field = document.getElementById(fieldId);
                if(field){ field.value = value; }
            };

            set("goalId", button.getAttribute("data-goal-id") || "");
            set("goalName", button.getAttribute("data-goal-name") || "");
            set("goalTarget", button.getAttribute("data-goal-target") || "");
            set("goalDate", button.getAttribute("data-goal-date") || "");

            if(nameCount){ nameCount.textContent = Array.from(nameBox.value).length; }

            var label = document.getElementById("goalModalLabel");
            if(label){ label.textContent = "Edit Goal"; }
        });
    });

    /* "Set New Goal" resets the shared modal. */
    document.querySelectorAll("[data-goal-new]").forEach(function(button){
        button.addEventListener("click", function(){
            var set = function(fieldId, value){
                var field = document.getElementById(fieldId);
                if(field){ field.value = value; }
            };

            set("goalId", "");
            set("goalName", "");
            set("goalTarget", "");
            set("goalDate", "");

            if(nameCount){ nameCount.textContent = "0"; }

            var label = document.getElementById("goalModalLabel");
            if(label){ label.textContent = "Set New Goal"; }
        });
    });

    /* Row Delete buttons prefill the confirm modal. */
    var deleteModal = document.getElementById("goalDeleteModal");
    if(deleteModal){
        deleteModal.addEventListener("show.bs.modal", function(event){
            var button = event.relatedTarget;
            if(!button){ return; }

            var idField = document.getElementById("goalDeleteId");
            var nameField = document.getElementById("goalDeleteName");

            if(idField){ idField.value = button.getAttribute("data-goal-id") || ""; }
            if(nameField){
                nameField.textContent = button.getAttribute("data-goal-name") || "this goal";
            }
        });
    }

    /* After a failed goal_save POST the server re-rendered the form values;
       reopen the modal so the user sees the errors in context. */
    <?php if($reopenGoalModal): ?>
    document.addEventListener("DOMContentLoaded", function(){
        var label = document.getElementById("goalModalLabel");
        if(label){
            label.textContent = <?= $form["id"] !== "" ? '"Edit Goal"' : '"Set New Goal"' ?>;
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById("goalModal")).show();
        /* Deep-linked opens (dashboard "Set a goal" → /goals.php?set=goal,
           Sprint 9) drop the param once the modal is up so a refresh stays
           quiet; a failed-POST reopen has no param in the URL to begin with,
           so this rewrite is a no-op there. */
        if(window.history && window.history.replaceState){
            window.history.replaceState(null, "", "/goals.php");
        }
    });
    <?php endif; ?>
})();
</script>
