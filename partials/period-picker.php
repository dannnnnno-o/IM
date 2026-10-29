<?php
/* Shared period picker (Daily/Weekly/Monthly + optional All Time).
   Expects in scope:
     $period        — return of resolve_period() from model.php
     $periodConfig  — [
         "action"       => form/link target, e.g. "/summary.php",
         "allowAllTime" => bool,
         "allTimeActive"=> bool,
         "extraHidden"  => assoc of hidden inputs each form carries (e.g. type),
     ]

   Renders: toggle button-group + the three picker rows (hidden unless
   active) + year-graph navigation links. Auto-submit + Go fallbacks included. */

$pickLinkState = $period["state"];
unset($pickLinkState["view"]);
$pickExtra = $periodConfig["extraHidden"] ?? [];
$pickAllowAllTime = !empty($periodConfig["allowAllTime"]);
$pickActiveView = !empty($periodConfig["allTimeActive"]) ? "all" : $period["view"];
$pickToday = !empty($periodConfig["today"]) ? $periodConfig["today"] : new DateTimeImmutable("today");

$pickViewLabels = [
    "daily"   => "Daily",
    "weekly"  => "Weekly",
    "monthly" => "Monthly",
];

/* Year navigation targets, only when they exist on that side. */
$pickPrevYear = $period["yearScope"]["oldest"] - 1;
$pickNextYear = $period["yearScope"]["oldest"] + 1;
$pickHasPrev = $pickPrevYear >= $period["yearScope"]["min"];
$pickHasNext = $period["yearScope"]["oldest"] < $period["yearScope"]["current"];
?>
<div class="btn-group view-toggle" role="group" aria-label="Period">
    <?php if($pickAllowAllTime): ?>
        <a class="btn <?= $pickActiveView === "all" ? "btn-primary is-active" : "btn-outline-secondary" ?>"
           href="<?= htmlspecialchars($periodConfig["action"] . "?" . http_build_query(array_filter(array_merge($pickExtra, ["view" => "all"])))) ?>"
           aria-current="<?= $pickActiveView === "all" ? "page" : "false" ?>">All Time</a>
    <?php endif; ?>
    <?php foreach($pickViewLabels as $pickKey => $pickLabel): ?>
        <a class="btn <?= $pickActiveView === $pickKey ? "btn-primary is-active" : "btn-outline-secondary" ?>"
           href="<?= htmlspecialchars($periodConfig["action"] . "?" . http_build_query(array_filter(array_merge(["view" => $pickKey], $pickLinkState, $pickExtra)))) ?>"
           aria-current="<?= $pickActiveView === $pickKey ? "page" : "false" ?>"><?= $pickLabel ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= htmlspecialchars($periodConfig["action"]) ?>" id="dailyForm"
      class="d-flex flex-wrap align-items-center gap-2 <?= $pickActiveView === "daily" ? "" : "d-none" ?>">
    <input type="hidden" name="view" value="daily">
    <?php foreach($pickExtra as $pickName => $pickValue): ?>
        <input type="hidden" name="<?= htmlspecialchars($pickName) ?>" value="<?= htmlspecialchars($pickValue) ?>">
    <?php endforeach; ?>
    <label class="form-label mb-0 me-1" for="date">Date</label>
    <input type="date" class="form-control form-control-sm w-auto" id="date" name="date"
           max="<?= htmlspecialchars($pickToday->format("Y-m-d")) ?>"
           value="<?= htmlspecialchars($period["pickedDate"]->format("Y-m-d")) ?>">
    <button type="submit" class="btn btn-sm btn-outline-secondary">Go</button>
</form>

<form method="get" action="<?= htmlspecialchars($periodConfig["action"]) ?>" id="weeklyForm"
      class="d-flex flex-wrap align-items-center gap-2 <?= $pickActiveView === "weekly" ? "" : "d-none" ?>">
    <input type="hidden" name="view" value="weekly">
    <?php foreach($pickExtra as $pickName => $pickValue): ?>
        <input type="hidden" name="<?= htmlspecialchars($pickName) ?>" value="<?= htmlspecialchars($pickValue) ?>">
    <?php endforeach; ?>
    <label class="form-label mb-0 me-1" for="week">Week</label>
    <select class="form-select form-select-sm w-auto" id="week" name="week">
        <option value="">This Week</option>
        <?php foreach($period["weekOptions"] as $pickOption): ?>
            <option value="<?= htmlspecialchars($pickOption["value"]) ?>"
                <?= ($pickLinkState["week"] ?? "") === $pickOption["value"] ? "selected" : "" ?>>
                <?= htmlspecialchars($pickOption["label"]) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Go</button>
    <nav class="btn-group" aria-label="Year visibility">
        <?php if($pickHasPrev): ?>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= htmlspecialchars($periodConfig["action"] . "?" . http_build_query(array_filter(array_merge(["view" => "weekly", "year" => $pickPrevYear], $pickLinkState, $pickExtra)))) ?>"
               title="Include <?= $pickPrevYear ?>">&lsaquo; <?= $pickPrevYear ?></a>
        <?php endif; ?>
        <?php if($pickHasNext): ?>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= htmlspecialchars($periodConfig["action"] . "?" . http_build_query(array_filter(array_merge(["view" => "weekly", "year" => $pickNextYear], $pickLinkState, $pickExtra)))) ?>"
               title="Stop at <?= $pickNextYear ?>"><?= $pickNextYear ?> &rsaquo;</a>
        <?php endif; ?>
    </nav>
</form>

<form method="get" action="<?= htmlspecialchars($periodConfig["action"]) ?>" id="monthlyForm"
      class="d-flex flex-wrap align-items-center gap-2 <?= $pickActiveView === "monthly" ? "" : "d-none" ?>">
    <input type="hidden" name="view" value="monthly">
    <?php foreach($pickExtra as $pickName => $pickValue): ?>
        <input type="hidden" name="<?= htmlspecialchars($pickName) ?>" value="<?= htmlspecialchars($pickValue) ?>">
    <?php endforeach; ?>
    <label class="form-label mb-0 me-1" for="month">Month</label>
    <select class="form-select form-select-sm w-auto" id="month" name="month">
        <option value="">This Month</option>
        <?php foreach($period["monthOptions"] as $pickOption): ?>
            <option value="<?= htmlspecialchars($pickOption["value"]) ?>"
                <?= ($pickLinkState["month"] ?? "") === $pickOption["value"] ? "selected" : "" ?>>
                <?= htmlspecialchars($pickOption["label"]) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Go</button>
    <nav class="btn-group" aria-label="Year visibility">
        <?php if($pickHasPrev): ?>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= htmlspecialchars($periodConfig["action"] . "?" . http_build_query(array_filter(array_merge(["view" => "monthly", "year" => $pickPrevYear], $pickLinkState, $pickExtra)))) ?>"
               title="Include <?= $pickPrevYear ?>">&lsaquo; <?= $pickPrevYear ?></a>
        <?php endif; ?>
        <?php if($pickHasNext): ?>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= htmlspecialchars($periodConfig["action"] . "?" . http_build_query(array_filter(array_merge(["view" => "monthly", "year" => $pickNextYear], $pickLinkState, $pickExtra)))) ?>"
               title="Stop at <?= $pickNextYear ?>"><?= $pickNextYear ?> &rsaquo;</a>
        <?php endif; ?>
    </nav>
</form>

<script>
(function(){
    ["dailyForm", "weeklyForm", "monthlyForm"].forEach(function(formId){
        var form = document.getElementById(formId);
        if(!form) return;

        Array.prototype.forEach.call(form.querySelectorAll("input, select"), function(control){
            control.addEventListener("change", function(){
                form.submit();
            });
        });
    });
})();
</script>
