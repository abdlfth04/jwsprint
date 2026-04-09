<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin', 'service');

header('Location: ' . pageUrl('produksi.php'));
exit;
