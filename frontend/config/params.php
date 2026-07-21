<?php

declare(strict_types=1);

return [
    'adminEmail' => 'admin@example.com',
    'tisApiUpstream' => getenv('TIS_API_UPSTREAM') ?: 'http://127.0.0.1:5000',
];
