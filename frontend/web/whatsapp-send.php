<?php

declare(strict_types=1);

/**
 * WhatsApp UI now lives inside the admin dashboard shell.
 */
require __DIR__ . '/admin-guard.php';
header('Location: admin-dashboard.php?section=whatsapp');
exit;
