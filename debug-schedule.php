<?php
/**
 * WP Engine Backup Schedule Debug Script
 * Upload this to your WordPress root directory and visit it in your browser
 * Remember to delete it after debugging for security
 */

// Load WordPress
require_once('wp-config.php');
require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('Access denied. Must be logged in as administrator.');
}

echo "<h2>WP Engine Backup Schedule Debug</h2>";

// 1. Check plugin settings
$settings = get_option('wpengine_backup_settings', array());
echo "<h3>Current Settings:</h3>";
echo "<pre>" . json_encode($settings, JSON_PRETTY_PRINT) . "</pre>";

// 2. Check next scheduled backup
$next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
echo "<h3>Next Scheduled Backup:</h3>";
if ($next_backup) {
    echo "<p><strong>Timestamp:</strong> " . $next_backup . "</p>";
    echo "<p><strong>Date/Time:</strong> " . date('Y-m-d H:i:s', $next_backup) . "</p>";
    echo "<p><strong>Time Until:</strong> " . human_time_diff($next_backup, time()) . "</p>";
} else {
    echo "<p>No backup scheduled</p>";
}

// 3. Check all scheduled events for our hook
echo "<h3>All Scheduled Events for wpengine_backup_cron_hook:</h3>";
$cron_array = _get_cron_array();
$our_events = array();
foreach ($cron_array as $timestamp => $cron) {
    if (isset($cron['wpengine_backup_cron_hook'])) {
        $our_events[] = array(
            'timestamp' => $timestamp,
            'date' => date('Y-m-d H:i:s', $timestamp),
            'schedule' => $cron['wpengine_backup_cron_hook']
        );
    }
}

if (empty($our_events)) {
    echo "<p>No events found</p>";
} else {
    echo "<p><strong>Found " . count($our_events) . " scheduled event(s):</strong></p>";
    foreach ($our_events as $event) {
        echo "<p>• " . $event['date'] . " (" . $event['timestamp'] . ")</p>";
    }
    
    if (count($our_events) > 1) {
        echo "<p style='color: red;'><strong>WARNING: Multiple events found! This is likely the problem.</strong></p>";
    }
}

// 4. Check available cron schedules
echo "<h3>Available Cron Schedules:</h3>";
$schedules = wp_get_schedules();
$backup_schedules = array_filter($schedules, function($key) {
    return strpos($key, 'every_') === 0 || $key === 'hourly' || $key === 'daily';
}, ARRAY_FILTER_USE_KEY);

foreach ($backup_schedules as $key => $schedule) {
    echo "<p>• <strong>{$key}:</strong> {$schedule['display']} ({$schedule['interval']} seconds)</p>";
}

// 5. Recent backup logs
global $wpdb;
$table_name = $wpdb->prefix . 'wpengine_backup_logs';
if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
    $recent_logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 5", ARRAY_A);
    echo "<h3>Recent Backup Activity (Last 5):</h3>";
    if ($recent_logs) {
        foreach ($recent_logs as $log) {
            echo "<p>• {$log['created_at']}: {$log['type']} - {$log['status']} - {$log['message']}</p>";
        }
    } else {
        echo "<p>No backup logs found</p>";
    }
}

echo "<hr>";
echo "<p><em>Remember to delete this debug file after use for security!</em></p>";
?>