<?php

return [
    'base_url' => env('KD3_BASE_URL', 'https://www.keibado.net'),
    'login_path' => '/kdata/login.php',
    'download_path' => '/kdata/select_download_core.php?mmdd={md}&yyyy={year}&kdx=kd3',
    'username' => env('KD3_USERNAME'),
    'password' => env('KD3_PASSWORD'),
    'timeout' => 60,
    'storage_disk' => env('KD3_STORAGE_DISK', 'local'),
    'artifacts' => [
        'hb' => ['entry_pattern' => '/^kd3_hb{ymd2}\.lzh$/i'],
        'ib' => ['entry_pattern' => '/^kd3_ib{ymd2}\.lzh$/i'],
        'jb' => ['entry_pattern' => '/^kd3_jb{ymd2}\.lzh$/i'],
        'kd' => ['entry_pattern' => '/^kd3_kd{ymd2}\.lzh$/i'],
        'lb' => ['entry_pattern' => '/^kd3_lb{ymd2}\.lzh$/i'],
        'mb' => ['entry_pattern' => '/^kd3_mb{ymd2}\.lzh$/i'],
    ],
];
