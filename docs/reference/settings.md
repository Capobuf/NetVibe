# Settings reference

NetVibe usa **spatie/laravel-settings**. I gruppi sono in `app/Settings/*`.

## discovery
File: `app/Settings/DiscoverySettings.php`
- `default_scan_interval_minutes` (int)
- `agent_polling_interval_minutes` (int)
- `auto_discovery_enabled` (bool)
- `icmp_timeout_ms` (int)
- `icmp_retries` (int)
- `prune_logs_days` (int)
- `timeout_rest_ms` (int)
- `timeout_snmp_ms` (int)
- `max_records_per_dataset` (int)

## system_state
File: `app/Settings/SystemStateSettings.php`
- `installed` (bool)
- `configured` (bool)
- `last_scheduler_run_at` (?string)
- `last_worker_heartbeat_at` (?string)
- `last_health_check_at` (?string)

Altri gruppi:
- `general` (`GeneralSettings`)
- `security` (`SecuritySettings`)
- `feature_flags` (`FeatureFlagSettings`)
- `notifications` (`NotificationSettings`)
