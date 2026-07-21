<?php

declare(strict_types=1);

return [
    'adminEmail' => 'admin@example.com',
    'tisApiUpstream' => getenv('TIS_API_UPSTREAM') ?: 'https://getways-app.onrender.com',
    'adminDataUpstream' => getenv('ADMIN_DATA_UPSTREAM') ?: 'https://getways-app.onrender.com',
];
