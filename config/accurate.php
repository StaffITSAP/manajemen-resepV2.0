<?php

return [
    'base_url'   => env('ACCURATE_BASE_URL', 'https://zeus.accurate.id/accurate/api'),
    'app_key'    => env('ACCURATE_APP_KEY'),
    'secret'     => env('ACCURATE_SIGNATURE_SECRET'),
    'token'      => env('ACCURATE_BEARER_TOKEN'),
    'tz'         => env('ACCURATE_TZ', 'Asia/Jakarta'),
    'db_id'      => env('ACCURATE_DB_ID'),
    'page_size'  => (int) env('ACCURATE_PAGE_SIZE', 100),
    'sync_per_loop'   => env('ACCURATE_SYNC_PER_LOOP', 20),
    'delay_per_loop'  => env('ACCURATE_DELAY_PER_LOOP', 1),
    'sync_latest_limit' => env('ACCURATE_SYNC_LATEST_LIMIT', 15),
    'purchase_requisition_cost_value_full_refresh_hours' => (int) env('PURCHASE_REQUISITION_COST_VALUE_FULL_REFRESH_HOURS', 24),
    'purchase_requisition_smart_sync_detail_sleep_ms' => (int) env('PURCHASE_REQUISITION_SMART_SYNC_DETAIL_SLEEP_MS', 500),
    'purchase_requisition_smart_sync_inter_batch_delay_seconds' => (int) env('PURCHASE_REQUISITION_SMART_SYNC_INTER_BATCH_DELAY_SECONDS', 10),
    // timeout total per request
    'timeout'    => env('ACCURATE_TIMEOUT', 120),
    // SSL
    'verify_ssl' => filter_var(env('ACCURATE_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
    'ca_path'    => env('ACCURATE_CA_PATH'), // optional: path ke cacert.pem kalau mau pakai
    'default_warehouse_id'   => env('ACCURATE_DEFAULT_WAREHOUSE_ID'),
    'default_warehouse_code' => env('ACCURATE_DEFAULT_WAREHOUSE_CODE'),
    'default_warehouse'      => env('ACCURATE_DEFAULT_WAREHOUSE', 'KITCHEN'),
];
