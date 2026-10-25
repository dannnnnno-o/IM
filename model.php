<?php 
const PREDEFINED_CATEGORIES = [
    "Food",
    "Groceries",
    "Transport",
    "Bills",
    "Salary",
    "Entertainment",
    "Health",
    "Other",
    "Goal Investment",
];

/* Fixed category for invest/take-back rows written by goal-details.php.
   Also part of PREDEFINED_CATEGORIES so History/Summary label it naturally. */
const GOAL_CATEGORY = "Goal Investment";

const MAX_CUSTOM_CATEGORIES = 20;
const MAX_CATEGORY_NAME_LENGTH = 30;

function get_custom_categories(object $pdo, int $userId){
    $sql = "SELECT categories FROM users WHERE id = :id; ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":id", $userId, PDO::PARAM_INT);
    $statement -> execute();

    $row = $statement -> fetch(PDO::FETCH_ASSOC);
    $raw = (string) ($row["categories"] ?? "");

    if($raw === ""){
        return [];
    }

    $decoded = json_decode($raw, true);
    if(!is_array($decoded)){
        return [];
    }

    $clean = [];
    foreach($decoded as $name){
        if(is_string($name) && trim($name) !== ""){
            $clean[] = trim($name);
        }
    }
    return $clean;
}

function all_categories(object $pdo, int $userId){
    return array_values(array_merge(PREDEFINED_CATEGORIES, get_custom_categories($pdo, $userId)));
}

function category_is_known(object $pdo, int $userId, string $category){
    $category = trim($category);
    if($category === "" || mb_strlen($category) > MAX_CATEGORY_NAME_LENGTH){
        return false;
    }

    $known = all_categories($pdo, $userId);
    foreach($known as $name){
        if(mb_strtolower($name) === mb_strtolower($category)){
            return true;
        }
    }
    return false;
}

function save_custom_category(object $pdo, int $userId, string $name){
    $name = trim($name);

    if($name === ""){
        return ["ok" => false, "error" => "Category name is required.", "categories" => get_custom_categories($pdo, $userId)];
    }

    if(mb_strlen($name) > MAX_CATEGORY_NAME_LENGTH){
        return ["ok" => false, "error" => "Category name must be " . MAX_CATEGORY_NAME_LENGTH . " characters or fewer.", "categories" => get_custom_categories($pdo, $userId)];
    }

    $existing = get_custom_categories($pdo, $userId);

    if(count($existing) >= MAX_CUSTOM_CATEGORIES){
        return ["ok" => false, "error" => "You can have at most " . MAX_CUSTOM_CATEGORIES . " custom categories.", "categories" => $existing];
    }

    foreach(PREDEFINED_CATEGORIES as $known){
        if(mb_strtolower($known) === mb_strtolower($name)){
            return ["ok" => false, "error" => "That category already exists.", "categories" => $existing];
        }
    }

    foreach($existing as $known){
        if(mb_strtolower($known) === mb_strtolower($name)){
            return ["ok" => false, "error" => "That category already exists.", "categories" => $existing];
        }
    }

    $existing[] = $name;
    $encoded = json_encode($existing, JSON_UNESCAPED_UNICODE);
    if($encoded === false || strlen($encoded) > 2500){
        return ["ok" => false, "error" => "Category list would exceed its storage limit.", "categories" => get_custom_categories($pdo, $userId)];
    }

    $sql = "UPDATE users SET categories = :categories WHERE id = :id; ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":categories", $encoded);
    $statement -> bindParam(":id", $userId, PDO::PARAM_INT);
    $statement -> execute();

    return ["ok" => true, "error" => null, "categories" => $existing];
}

function get_user(object $pdo, int $id){
    $sql = "SELECT * FROM users WHERE id = :id; ";
    $statement = $pdo -> prepare($sql);
    $statement->bindParam(":id", $id);
    $statement->execute();

    $result = $statement->fetch(PDO::FETCH_ASSOC);
    return $result;
}

function get_recent_transactions(object $pdo, int $limit = 5){
    $limit = max(1, (int) $limit);

    $sql = "SELECT date, category, mode, amount
            FROM transactions
            ORDER BY date DESC, id DESC
            LIMIT $limit; ";

    $statement = $pdo -> prepare($sql);
    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function sum_amount(object $pdo, string $mode, string $start, string $end){
    $sql = "SELECT COALESCE(SUM(amount), 0) AS total
            FROM transactions
            WHERE mode = :mode
              AND date BETWEEN :start AND :end; ";

    $statement = $pdo -> prepare($sql);
    $statement->bindParam(":mode", $mode);
    $statement->bindParam(":start", $start);
    $statement->bindParam(":end", $end);
    $statement->execute();

    $result = $statement->fetch(PDO::FETCH_ASSOC);
    return (int) $result["total"];
}

function count_rows(object $pdo, string $table){
    $allowed = ["transactions", "goals", "users"];
    if(!in_array($table, $allowed, true)){
        throw new InvalidArgumentException("Unknown table: $table");
    }

    $sql = "SELECT COUNT(*) AS total FROM $table; ";
    $statement = $pdo -> prepare($sql);
    $statement->execute();

    $result = $statement->fetch(PDO::FETCH_ASSOC);
    return (int) $result["total"];
}

function week_range(DateTimeImmutable $today){
    return [
        $today->modify("monday this week")->format("Y-m-d"),
        $today->modify("sunday this week")->format("Y-m-d"),
    ];
}

function month_range(DateTimeImmutable $today){
    return [
        $today->modify("first day of this month")->format("Y-m-d"),
        $today->modify("last day of this month")->format("Y-m-d"),
    ];
}

function peso(int $amount){
    return "&#8369;" . number_format($amount);
}

function mode_label(string $mode){
    return trim($mode) === "+" ? "Income" : "Expense";
}

function mode_is_income(string $mode){
    return trim($mode) === "+";
}

/* Core write used by create_transaction() and the goal investment flows.
   Assumes an OPEN pdo transaction; commits/rollbacks are the caller's job.
   Returns the new transaction id. */
function insert_transaction(object $pdo, string $mode, int $amount, string $category, ?string $comment, int $userId, int $goal = 0){
    $sql = "INSERT INTO transactions (date, amount, mode, category, comment, goal)
            VALUES (CURDATE(), :amount, :mode, :category, :comment, :goal); ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":amount", $amount, PDO::PARAM_INT);
    $statement -> bindParam(":mode", $mode);
    $statement -> bindParam(":category", $category);
    $statement -> bindParam(":comment", $comment);
    $statement -> bindParam(":goal", $goal, PDO::PARAM_INT);
    $statement -> execute();

    $operator = $mode === "+" ? "+" : "-";

    $balanceSql = "UPDATE users SET balance = balance $operator :amount WHERE id = :id; ";

    $balanceStatement = $pdo -> prepare($balanceSql);
    $balanceStatement -> bindParam(":amount", $amount, PDO::PARAM_INT);
    $balanceStatement -> bindParam(":id", $userId, PDO::PARAM_INT);
    $balanceStatement -> execute();

    return (int) $pdo -> lastInsertId();
}

function create_transaction(object $pdo, string $mode, int $amount, string $category, ?string $comment, int $userId, int $goal = 0){
    $pdo -> beginTransaction();

    try{
        $transactionId = insert_transaction($pdo, $mode, $amount, $category, $comment, $userId, $goal);

        $pdo -> commit();
        return $transactionId;
    }
    catch(PDOException $e){
        $pdo -> rollBack();
        throw $e;
    }
}

function valid_date(string $value){
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)){
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function get_filtered_transactions(object $pdo, string $mode, string $start, string $end, int $limit, int $offset){
    $limit = max(1, (int) $limit);
    $offset = max(0, (int) $offset);

    $where = ["date BETWEEN :start AND :end"];
    if($mode !== ""){
        $where[] = "mode = :mode";
    }

    $sql = "SELECT id, date, category, comment, mode, amount
            FROM transactions
            WHERE " . implode(" AND ", $where) . "
            ORDER BY date DESC, id DESC
            LIMIT $limit OFFSET $offset; ";

    $statement = $pdo -> prepare($sql);
    if($mode !== ""){
        $statement -> bindParam(":mode", $mode);
    }
    $statement -> bindParam(":start", $start);
    $statement -> bindParam(":end", $end);
    $statement -> execute();

    return $statement -> fetchAll(PDO::FETCH_ASSOC);
}

function count_filtered_transactions(object $pdo, string $mode, string $start, string $end){
    $where = ["date BETWEEN :start AND :end"];
    if($mode !== ""){
        $where[] = "mode = :mode";
    }

    $sql = "SELECT COUNT(*) AS total
            FROM transactions
            WHERE " . implode(" AND ", $where) . "; ";

    $statement = $pdo -> prepare($sql);
    if($mode !== ""){
        $statement -> bindParam(":mode", $mode);
    }
    $statement -> bindParam(":start", $start);
    $statement -> bindParam(":end", $end);
    $statement -> execute();

    $result = $statement -> fetch(PDO::FETCH_ASSOC);
    return (int) $result["total"];
}

function get_first_transaction_year(object $pdo){
    $sql = "SELECT YEAR(MIN(date)) AS min_year FROM transactions; ";

    $statement = $pdo -> prepare($sql);
    $statement -> execute();

    $row = $statement -> fetch(PDO::FETCH_ASSOC);

    return $row["min_year"] === null ? null : (int) $row["min_year"];
}

function week_options(array $yearRange, string $maxMonday){
    $options = [];

    foreach($yearRange as $year){
        /* First Monday of the ISO year; guard is a loop-safety net only. */
        $cursor = (new DateTimeImmutable())->setISODate($year, 1);
        $guard = 0;

        while($guard++ < 60 && (int) $cursor->format("o") === $year){
            $monday = $cursor;
            /* No future weeks: the in-progress week is selectable, the next one is not. */
            if($monday->format("Y-m-d") <= $maxMonday){
                $sunday = $cursor->modify("+6 days");
                $label = $monday->format("M j") . " \u{2013} " . $sunday->format("M j, Y");
                $options[] = ["value" => $monday->format("Y-m-d"), "label" => $label];
            }
            $cursor = $cursor->modify("+7 days");
        }
    }

    /* Latest first. */
    return array_reverse($options);
}

function month_options(array $yearRange, string $maxMonth){
    $monthNames = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December",
    ];

    $options = [];

    foreach($yearRange as $year){
        foreach($monthNames as $index => $monthName){
            $monthNumber = $index + 1;
            $value = sprintf("%04d-%02d", $year, $monthNumber);
            /* No future months within the current year. */
            if($value <= $maxMonth){
                $options[] = ["value" => $value, "label" => $monthName . " " . $year];
            }
        }
    }

    /* Latest first. */
    return array_reverse($options);
}

/* Shared Daily/Weekly/Monthly (+ optionally All Time) period parsing used by
   summary.php and history.php. Reads view/date/week/month/year from the
   request, validates the ACTIVE view's picker against the generated option
   lists, and returns everything both pages need to render the shared
   partial and build their URLs. */
function resolve_period(object $pdo, array $request, DateTimeImmutable $today, array $options){
    $allowAll = !empty($options["allowAll"]);

    $firstYear = get_first_transaction_year($pdo);
    $todayYear = (int) $today->format("Y");
    $minYear = min($todayYear - 5, $firstYear ?? $todayYear);
    $todayWeekMonday = $today->modify("monday this week")->format("Y-m-d");
    $todayMonth = $today->format("Y-m");

    $allowedViews = $allowAll ? ["all", "daily", "weekly", "monthly"] : ["daily", "weekly", "monthly"];
    $defaultView = $allowAll ? "all" : "daily";

    $viewInput = trim($request["view"] ?? "");
    $view = in_array($viewInput, $allowedViews, true) ? $viewInput : $defaultView;

    /* Year navigation scope: how far back the pickers currently reach.
       Like `view`, an invalid value silently normalises to the default —
       it only controls which options are listed, never what data is read. */
    $yearInput = trim($request["year"] ?? "");
    $oldestYear = $todayYear;
    if(preg_match('/^\d{4}$/', $yearInput)){
        $yearCandidate = (int) $yearInput;
        if($yearCandidate >= $minYear && $yearCandidate <= $todayYear){
            $oldestYear = $yearCandidate;
        }
    }

    $yearRange = range($oldestYear, $todayYear);
    /* Latest first. */
    $weekOptions = week_options($yearRange, $todayWeekMonday);
    $monthOptions = month_options($yearRange, $todayMonth);

    $errors = [];

    /* Always defined: every picker row renders on every view (hidden on
       non-matching views), see docs §9 gotcha #3. */
    $pickedDate = $today;
    $pickedWeek = $today->modify("monday this week");
    $pickedMonth = null;

    $dateInput = trim($request["date"] ?? "");
    $weekInput = trim($request["week"] ?? "");
    $monthInput = trim($request["month"] ?? "");

    if($view === "all"){
        $start = "1000-01-01";
        $end = "9999-12-31";
        $rangeLabel = "All time";
    }
    elseif($view === "daily"){
        if($dateInput === ""){
            $pickedDate = $today;
        }
        elseif(valid_date($dateInput)){
            $pickedDate = new DateTimeImmutable($dateInput);
        }
        else{
            $errors[] = "The date is not a valid date.";
            $pickedDate = $today;
        }

        $start = $pickedDate->format("Y-m-d");
        $end = $start;
        $rangeLabel = $pickedDate->format("D, M j, Y");
    }
    elseif($view === "weekly"){
        if($weekInput === ""){
            $pickedWeek = $today->modify("monday this week");
        }
        elseif(in_array($weekInput, array_column($weekOptions, "value"), true)){
            $pickedWeek = new DateTimeImmutable($weekInput);
        }
        else{
            $errors[] = "The week is not an available week.";
            $pickedWeek = $today->modify("monday this week");
        }

        [$start, $end] = week_range($pickedWeek);
        $rangeLabel = $pickedWeek->format("M j") . " \u{2013} " . $pickedWeek->modify("+6 days")->format("M j, Y");
    }
    else{
        if($monthInput === ""){
            $pickedMonth = null; /* "This Month" */
        }
        elseif(in_array($monthInput, array_column($monthOptions, "value"), true)){
            [$pickedYear, $pickedMonthNumber] = array_map("intval", explode("-", $monthInput));
            $pickedMonth = $today->setDate($pickedYear, $pickedMonthNumber, 1);
        }
        else{
            $errors[] = "The month is not an available month.";
            $pickedMonth = null;
        }

        $monthAnchor = $pickedMonth ?? $today;
        [$start, $end] = month_range($monthAnchor);
        $rangeLabel = $monthAnchor->format("F Y");
    }

    /* URL state for toggle/pagination links. Only validated values are
       carried; non-active-view values are carried as previously validated. */
    $state = ["view" => $view];
    if($dateInput !== "" && valid_date($dateInput)){
        $state["date"] = $dateInput;
    }
    if($weekInput !== "" && in_array($weekInput, array_column($weekOptions, "value"), true)){
        $state["week"] = $weekInput;
    }
    if($monthInput !== "" && in_array($monthInput, array_column($monthOptions, "value"), true)){
        $state["month"] = $monthInput;
    }
    if($oldestYear !== $todayYear){
        $state["year"] = (string) $oldestYear;
    }

    return [
        "view" => $view,
        "start" => $start,
        "end" => $end,
        "errors" => $errors,
        "rangeLabel" => $rangeLabel,
        "pickedDate" => $pickedDate,
        "pickedWeek" => $pickedWeek,
        "pickedMonth" => $pickedMonth,
        "weekOptions" => $weekOptions,
        "monthOptions" => $monthOptions,
        "yearScope" => ["min" => $minYear, "oldest" => $oldestYear, "current" => $todayYear],
        "state" => $state,
    ];
}

function expense_by_category(object $pdo, string $start, string $end){
    $sql = "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS category,
                   SUM(amount) AS total
            FROM transactions
            WHERE mode = '-'
              AND date BETWEEN :start AND :end
            GROUP BY category
            ORDER BY total DESC, category ASC; ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":start", $start);
    $statement -> bindParam(":end", $end);
    $statement -> execute();

    $rows = $statement -> fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach($rows as $row){
        $result[] = ["category" => $row["category"], "total" => (int) $row["total"]];
    }
    return $result;
}

/* ---------- Goals ---------- */

function get_goals(object $pdo){
    $sql = "SELECT id, name, target_amount, target_date, investment
            FROM goals
            ORDER BY id ASC; ";

    $statement = $pdo -> prepare($sql);
    $statement -> execute();

    return $statement -> fetchAll(PDO::FETCH_ASSOC);
}

function get_goal(object $pdo, int $id){
    $sql = "SELECT id, name, target_amount, target_date, investment
            FROM goals
            WHERE id = :id; ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":id", $id, PDO::PARAM_INT);
    $statement -> execute();

    $row = $statement -> fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function create_goal(object $pdo, string $name, int $targetAmount, string $targetDate){
    $sql = "INSERT INTO goals (name, target_amount, target_date, investment)
            VALUES (:name, :target_amount, :target_date, 0); ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":name", $name);
    $statement -> bindParam(":target_amount", $targetAmount, PDO::PARAM_INT);
    $statement -> bindParam(":target_date", $targetDate);
    $statement -> execute();

    return true;
}

function update_goal(object $pdo, int $id, string $name, int $targetAmount, string $targetDate){
    $sql = "UPDATE goals
            SET name = :name, target_amount = :target_amount, target_date = :target_date
            WHERE id = :id; ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":name", $name);
    $statement -> bindParam(":target_amount", $targetAmount, PDO::PARAM_INT);
    $statement -> bindParam(":target_date", $targetDate);
    $statement -> bindParam(":id", $id, PDO::PARAM_INT);
    $statement -> execute();

    return true;
}

/* Deletes the goal and its investment ledger rows in one atomic write.
   The ledger has no FK by design (house style), so cleanup is app-level.
   Transactions already recorded through investing stay in history. */
function delete_goal(object $pdo, int $id){
    $pdo -> beginTransaction();

    try{
        $ledgerSql = "DELETE FROM goal_investments WHERE goal_id = :id; ";

        $ledgerStatement = $pdo -> prepare($ledgerSql);
        $ledgerStatement -> bindParam(":id", $id, PDO::PARAM_INT);
        $ledgerStatement -> execute();

        $goalSql = "DELETE FROM goals WHERE id = :id; ";

        $goalStatement = $pdo -> prepare($goalSql);
        $goalStatement -> bindParam(":id", $id, PDO::PARAM_INT);
        $goalStatement -> execute();

        $pdo -> commit();
        return true;
    }
    catch(PDOException $e){
        $pdo -> rollBack();
        throw $e;
    }
}

/* Investment ledger for one goal, joined to its transaction for the date.
   Ordered latest first. */
function get_goal_investments(object $pdo, int $goalId){
    $sql = "SELECT gi.id, gi.amount, gi.created_at, t.date, t.mode
            FROM goal_investments gi
            JOIN transactions t ON t.id = gi.transaction_id
            WHERE gi.goal_id = :goal_id
            ORDER BY t.date DESC, gi.id DESC; ";

    $statement = $pdo -> prepare($sql);
    $statement -> bindParam(":goal_id", $goalId, PDO::PARAM_INT);
    $statement -> execute();

    return $statement -> fetchAll(PDO::FETCH_ASSOC);
}

/* Progress percent for badges/bars; floors and never exceeds 100. */
function goal_share(int $investment, int $targetAmount){
    if($targetAmount <= 0){
        return 0;
    }

    $share = (int) floor($investment / $targetAmount * 100);
    return max(0, min(100, $share));
}

/* Invest ("-" mode) or take back ("+" mode) for one goal, atomically:
   1. a real transaction (goal = 1, fixed GOAL_CATEGORY, auto comment),
   2. a signed goal_investments ledger row (+invest / -take back),
   3. the goals.investment aggregate moved the same direction.
   Returns the transaction id. Callers validate amounts (integer, and the
   take-back cap) BEFORE calling this. */
function record_goal_investment(object $pdo, int $goalId, int $amount, string $goalName, int $userId, string $mode){
    if(!in_array($mode, ["-", "+"], true)){
        throw new InvalidArgumentException("Invalid investment mode: $mode");
    }

    $isInvest = $mode === "-";
    $comment = ($isInvest ? "Invested in " : "Taken back from ") . $goalName;
    $ledgerAmount = $isInvest ? $amount : -$amount;

    $pdo -> beginTransaction();

    try{
        $transactionId = insert_transaction($pdo, $mode, $amount, GOAL_CATEGORY, $comment, $userId, 1);

        $ledgerSql = "INSERT INTO goal_investments (goal_id, transaction_id, amount)
                      VALUES (:goal_id, :transaction_id, :amount); ";

        $ledgerStatement = $pdo -> prepare($ledgerSql);
        $ledgerStatement -> bindParam(":goal_id", $goalId, PDO::PARAM_INT);
        $ledgerStatement -> bindParam(":transaction_id", $transactionId, PDO::PARAM_INT);
        $ledgerStatement -> bindParam(":amount", $ledgerAmount, PDO::PARAM_INT);
        $ledgerStatement -> execute();

        $operator = $isInvest ? "+" : "-";
        $aggSql = "UPDATE goals SET investment = investment $operator :amount WHERE id = :id; ";

        $aggStatement = $pdo -> prepare($aggSql);
        $aggStatement -> bindParam(":amount", $amount, PDO::PARAM_INT);
        $aggStatement -> bindParam(":id", $goalId, PDO::PARAM_INT);
        $aggStatement -> execute();

        $pdo -> commit();
        return $transactionId;
    }
    catch(PDOException $e){
        $pdo -> rollBack();
        throw $e;
    }
}
?>