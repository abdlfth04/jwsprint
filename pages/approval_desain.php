<?php
require_once __DIR__ . '/../app/bootstrap/app.php';

header('Location: ' . pageUrl('siap_cetak.php'), true, 302);
exit;
