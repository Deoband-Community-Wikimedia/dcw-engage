<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/models/ApplicationModel.php';

$appModel = new ApplicationModel();
$db = DB::getInstance()->getConnection();

$stmt = $db->query("SELECT * FROM magic_links ORDER BY id DESC LIMIT 1");
$link = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Latest Link:\n";
print_r($link);

$stmt = $db->query("SELECT NOW() as mysql_now");
$now = $stmt->fetch(PDO::FETCH_ASSOC);

echo "\nMySQL NOW: " . $now['mysql_now'] . "\n";
echo "PHP date: " . date('Y-m-d H:i:s') . "\n";
