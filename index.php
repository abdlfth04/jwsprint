<?php
require_once __DIR__ . '/app/bootstrap/app.php';

if (isLoggedIn()) {
    header('Location: ' . pageUrl('dashboard.php'));
    exit;
}

header('Location: ' . pageUrl('login.php'));
exit;
