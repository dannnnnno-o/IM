<?php
require_once("./db.php");
require_once("./model.php");

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");

if($_SERVER["REQUEST_METHOD"] !== "POST"){
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Method not allowed.", "categories" => []]);
    exit;
}

$userId = 1;
$name = trim((string) ($_POST["name"] ?? ""));

$result = save_custom_category($pdo, $userId, $name);

if($result["ok"]){
    http_response_code(201);
}
else{
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
