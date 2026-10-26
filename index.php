<?php
try{
    require_once("./db.php");
    require_once("./model.php");
}
catch(PDOException $e){
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

$user = get_user($pdo, 1);

/* First-visit gate (docs §6): while users.username is empty/blank this route
   IS the landing page. Once a name is stored, "/" keeps the pre-landing
   behavior — a 302 straight to the dashboard. */
if(!username_is_missing($user)){
    header("Location: /dashboard.php");
    exit;
}

$errors = [];
$name = "";

if($_SERVER["REQUEST_METHOD"] === "POST"){
    $name = trim($_POST["name"] ?? "");

    if($name === ""){
        $errors[] = "Name is required.";
    }
    elseif(mb_strlen($name) > MAX_USERNAME_LENGTH){
        $errors[] = "Name must be " . MAX_USERNAME_LENGTH . " characters or fewer.";
    }
    else{
        // First visit complete: store the name, then straight to the app.
        save_username($pdo, 1, $name);
        header("Location: /dashboard.php?registered=true");
        exit;
    }
}

$pageTitle = "Welcome";
require_once("./partials/header.php");
?>

<main class="landing">
    <section class="card landing-card">
        <div class="card-body p-4">

            <div class="landing-header">
                <div class="landing-icon">
                    <!-- Mockup: landing-page.png. Line-art wallet, badge tile
                         mirrors the dashboard's .balance-icon pattern. -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/>
                        <path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/>
                        <path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>
                    </svg>
                </div>
                <div class="brand-name landing-title">J-Budget</div>
                <div class="landing-tagline">Track &middot; Save &middot; Achieve</div>
            </div>

            <h1 class="h4 text-center">What should we call you?</h1>

            <?php if(!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <?php foreach($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/" id="landingForm" novalidate>
                <div class="mb-3">
                    <label class="form-label" for="name">Your name</label>
                    <div class="landing-input-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="8" r="3.5"/>
                            <path d="M5 20a7 7 0 0 1 14 0"/>
                        </svg>
                        <input type="text" class="form-control landing-input" id="name" name="name"
                               maxlength="<?= MAX_USERNAME_LENGTH ?>"
                               placeholder="Enter your name"
                               value="<?= htmlspecialchars($name) ?>"
                               autocomplete="off" autofocus>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="confirmNameButton">Confirm</button>
            </form>

        </div>
    </section>

    <!-- Confirm-before-lock step (mockup: landing-page.png): the name cannot
         be renamed through the app once saved, so the user sees the exact
         value that is about to be locked in. -->
    <div class="modal fade" id="confirmNameModal" tabindex="-1" aria-labelledby="confirmNameTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="confirmNameTitle">J-Budget</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <p class="mb-0">Note that at this part of development, the name cannot be changed yet.
                    Proceed with the name <strong id="confirmNameValue"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Go Back</button>
                    <button type="button" class="btn btn-primary" id="proceedName">Proceed</button>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function(){
    /* The Bootstrap bundle loads after page scripts (footer.php), so any
       bootstrap.* access must wait for DOMContentLoaded — the same house
       pattern the goals pages use for tooltips and modal re-openers. */
    document.addEventListener("DOMContentLoaded", function(){
        var form = document.getElementById("landingForm");
        var nameInput = document.getElementById("name");
        var valueEl = document.getElementById("confirmNameValue");
        var modalEl = document.getElementById("confirmNameModal");
        var proceedButton = document.getElementById("proceedName");

        if(!form || !nameInput || !valueEl || !modalEl || !proceedButton){
            return;
        }

        form.addEventListener("submit", function(event){
            // Nothing to confirm — let the server render "Name is required."
            if(nameInput.value.trim() === ""){
                return;
            }

            event.preventDefault();

            /* textContent, never innerHTML — the value is user input and must
               land in the modal as plain text, not markup. The stored name is
               htmlspecialchars()-escaped server-side as usual. */
            valueEl.textContent = nameInput.value.trim();
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });

        proceedButton.addEventListener("click", function(){
            proceedButton.disabled = true;
            form.submit();      // real navigation POST, bypasses the listener above
        });

        // Go Back / Esc / close: leave the form ready for a second attempt.
        modalEl.addEventListener("hidden.bs.modal", function(){
            proceedButton.disabled = false;
        });
    });
})();
</script>

<?php require_once("./partials/footer.php"); ?>
