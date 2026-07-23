<?php

declare(strict_types=1);

define('INVOICE_RECEIPT_MODE', 'user');
$id = (int) ($_GET['id'] ?? 0);
$share = trim((string) ($_GET['t'] ?? $_GET['share'] ?? ''));
define('INVOICE_PUBLIC_SHARE_REQUEST', $share !== '' && $id > 0);
require __DIR__ . '/admin-invoice.php';
