<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/gold_api') {
    require __DIR__ . '/gold_api.php';
    return true;
}

return false;
