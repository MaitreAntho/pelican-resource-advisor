<?php

return [
    // How many days of usage the recommendations look at.
    'window_days' => env('RESOURCE_ADVISOR_WINDOW_DAYS', 7),

    // How many days of samples are kept.
    'retention_days' => env('RESOURCE_ADVISOR_RETENTION_DAYS', 35),

    // Hours of data needed before memory and CPU are judged (hours the server was running).
    'min_running_hours' => env('RESOURCE_ADVISOR_MIN_RUNNING_HOURS', 12),

    // Hours of data needed before disk is judged.
    'min_hours' => env('RESOURCE_ADVISOR_MIN_HOURS', 24),

    // Usage above this share of the limit means the server needs more.
    'increase_threshold' => env('RESOURCE_ADVISOR_INCREASE_THRESHOLD', 0.9),

    // Peak usage below this share of the limit, over a full window, means the server could do with less.
    'decrease_threshold' => env('RESOURCE_ADVISOR_DECREASE_THRESHOLD', 0.4),

    // A disk that would be full within this many days needs more space.
    'disk_days_warning' => env('RESOURCE_ADVISOR_DISK_DAYS_WARNING', 14),

    // Suggest lower limits too, not only higher ones.
    'suggest_decrease' => env('RESOURCE_ADVISOR_SUGGEST_DECREASE', true),

    // Discord webhook that receives the alerts (empty: no Discord messages).
    'discord_webhook' => env('RESOURCE_ADVISOR_DISCORD_WEBHOOK'),

    // Also send the alerts as panel notifications to the root administrators.
    'notify_admins' => env('RESOURCE_ADVISOR_NOTIFY_ADMINS', false),

    // Alert as soon as a server is killed for lack of memory.
    'notify_oom' => env('RESOURCE_ADVISOR_NOTIFY_OOM', true),

    // Hour of the daily summary (0 to 23, in the panel timezone), empty to turn it off.
    'digest_hour' => env('RESOURCE_ADVISOR_DIGEST_HOUR', 9),
];
