<?php
$host = '127.0.0.1';
$db   = 'job_scraper';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}
?>
