<?php
require_once("./db.php");
require_once("./model.php");

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");

if($_SERVER["REQUEST_METHOD"] !== "POST"){
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Method not allowed."]);
    exit;
}

$userId = 1;

/* Sprint 15: action-routed category management. The default stays "add"
   so the Make Transaction modal's payload (name + type, no action) works
   unchanged; Settings' Manage Categories posts explicit actions. Every
   response keeps the {ok, error} contract the page scripts render. */
$action = trim((string) ($_POST["action"] ?? "add"));

/* The confirm modals' "Do not show this again" checkbox arrives as
   skip_confirm=1 on the same POST that performs the action — the user did
   see and accept the confirm, so the flag persists even if the action
   itself then fails validation. */
if(isset($_POST["skip_confirm"]) && in_array($action, ["rename", "remove"], true)){
    set_confirmation_skip($pdo, $userId, $action);
}

$result = match($action){
    "rename" => rename_custom_category(
        $pdo,
        $userId,
        (string) ($_POST["current"] ?? ""),
        (string) ($_POST["name"] ?? "")
    ),
    "remove" => remove_custom_category($pdo, $userId, (string) ($_POST["name"] ?? "")),
    "save_types" => (function() use ($pdo, $userId){
        $types = json_decode((string) ($_POST["types"] ?? ""), true);
        if(!is_array($types)){
            return ["ok" => false, "error" => "Choose a valid category type."];
        }
        return save_category_types($pdo, $userId, $types);
    })(),
    "add" => save_custom_category(
        $pdo,
        $userId,
        (string) ($_POST["name"] ?? ""),
        (string) ($_POST["type"] ?? CATEGORY_BOTH)
    ),
    default => ["ok" => false, "error" => "Choose a valid action."],
};

if($result["ok"]){
    http_response_code(200);
}
else{
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
