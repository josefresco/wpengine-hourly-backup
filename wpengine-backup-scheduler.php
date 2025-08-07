<?php
/**
 * Plugin Name: WP Engine Backup Scheduler
 * Plugin URI: https://github.com/yourname/wpengine-backup-scheduler
 * Description: Automated backup scheduling for WP Engine hosted sites using the WP Engine API
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wpengine-backup-scheduler
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPENGINE_BACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPENGINE_BACKUP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WPENGINE_BACKUP_VERSION', '1.0.0');

/**
 * Main plugin class
 */
class WPEngineBackupScheduler {
    
    private $api_base_url = 'https://api.wpengineapi.com/v1';
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wpengine_create_backup', array($this, 'ajax_create_backup'));
        add_action('wp_ajax_wpengine_save_schedule', array($this, 'ajax_save_schedule'));
        add_action('wp_ajax_wpengine_save_api_settings', array($this, 'ajax_save_api_settings'));
        add_action('wp_ajax_wpengine_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_wpengine_fetch_backups', array($this, 'ajax_fetch_backups'));
        add_action('wp_ajax_wpengine_fetch_installs', array($this, 'ajax_fetch_installs'));
        add_action('wp_ajax_wpengine_auto_detect_install', array($this, 'ajax_auto_detect_install'));
        add_action('wp_ajax_wpengine_debug_settings', array($this, 'ajax_debug_settings'));
        add_action('wp_ajax_wpengine_cancel_schedule', array($this, 'ajax_cancel_schedule'));
        
        // Hook for scheduled backups
        add_action('wpengine_backup_cron_hook', array($this, 'execute_scheduled_backup'));
        
        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        load_plugin_textdomain('wpengine-backup-scheduler', false, dirname(plugin_basename(__FILE__)) . '/languages');
        $this->setup_cron_schedules();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database table for backup logs
        $this->create_backup_logs_table();
        
        // Set default options
        if (!get_option('wpengine_backup_settings')) {
            add_option('wpengine_backup_settings', array(
                'api_username' => '',
                'api_password' => '',
                'install_id' => '',
                'install_name' => '',
                'backup_frequency' => '24',
                'email_notifications' => '',
                'enabled' => false
            ));
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('wpengine_backup_cron_hook');
    }
    
    /**
     * Create backup logs table
     */
    private function create_backup_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            backup_type varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            backup_id varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Setup custom cron schedules
     */
    public function setup_cron_schedules() {
        add_filter('cron_schedules', function($schedules) {
            // Add hourly intervals from 1-23 hours
            for ($i = 1; $i <= 23; $i++) {
                $schedules["every_{$i}_hours"] = array(
                    'interval' => $i * HOUR_IN_SECONDS,
                    'display' => sprintf(__('Every %d Hours', 'wpengine-backup-scheduler'), $i)
                );
            }
            return $schedules;
        });
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            __('WP Engine Backups', 'wpengine-backup-scheduler'),
            __('WP Engine Backups', 'wpengine-backup-scheduler'),
            'manage_options',
            'wpengine-backup-scheduler',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_wpengine-backup-scheduler') {
            return;
        }
        
        wp_enqueue_script(
            'wpengine-backup-admin',
            WPENGINE_BACKUP_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            WPENGINE_BACKUP_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wpengine-backup-admin',
            WPENGINE_BACKUP_PLUGIN_URL . 'assets/admin.css',
            array(),
            WPENGINE_BACKUP_VERSION
        );
        
        wp_localize_script('wpengine-backup-admin', 'wpengineBackup', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpengine_backup_nonce'),
            'strings' => array(
                'creating_backup' => __('Creating backup...', 'wpengine-backup-scheduler'),
                'backup_created' => __('Backup created successfully!', 'wpengine-backup-scheduler'),
                'backup_failed' => __('Backup creation failed.', 'wpengine-backup-scheduler'),
                'testing_api' => __('Testing API connection...', 'wpengine-backup-scheduler'),
                'api_success' => __('API connection successful!', 'wpengine-backup-scheduler'),
                'api_failed' => __('API connection failed.', 'wpengine-backup-scheduler')
            )
        ));
    }
    
    /**
     * Get current WP Engine install ID and name from environment
     */
    private function get_current_install_info() {
        $install_info = array(
            'id' => null,
            'name' => null,
            'environment' => null,
            'wpe_detected' => false
        );
        
        // Method 1: Extract from ABSPATH (most reliable for WP Engine)
        if (defined('ABSPATH')) {
            $abspath = ABSPATH;
            if (preg_match('/\/nas\/content\/(live|staging|development)\/([^\/]+)\//', $abspath, $matches)) {
                $install_info['name'] = $matches[2];
                $install_info['environment'] = $matches[1] === 'live' ? 'production' : $matches[1];
                $install_info['wpe_detected'] = true;
            }
        }
        
        // Method 2: Check server name patterns
        if (empty($install_info['name']) && isset($_SERVER['SERVER_NAME'])) {
            $server_name = $_SERVER['SERVER_NAME'];
            
            if (strpos($server_name, '.wpengine.com') !== false) {
                $install_name = str_replace('.wpengine.com', '', $server_name);
                $install_info['name'] = $install_name;
                $install_info['environment'] = 'production';
                $install_info['wpe_detected'] = true;
            }
            elseif (strpos($server_name, '.staging.wpengine.com') !== false) {
                $install_name = str_replace('.staging.wpengine.com', '', $server_name);
                $install_info['name'] = $install_name;
                $install_info['environment'] = 'staging';
                $install_info['wpe_detected'] = true;
            }
            elseif (strpos($server_name, '.dev.wpengine.com') !== false) {
                $install_name = str_replace('.dev.wpengine.com', '', $server_name);
                $install_info['name'] = $install_name;
                $install_info['environment'] = 'development';
                $install_info['wpe_detected'] = true;
            }
        }
        
        // Method 3: Check for WP Engine specific constants
        if (defined('WPE_APIKEY')) {
            $install_info['wpe_detected'] = true;
        }
        
        // Method 4: Check document root path
        if (empty($install_info['name']) && isset($_SERVER['DOCUMENT_ROOT'])) {
            $doc_root = $_SERVER['DOCUMENT_ROOT'];
            if (preg_match('/\/nas\/content\/(live|staging|development)\/([^\/]+)/', $doc_root, $matches)) {
                $install_info['name'] = $matches[2];
                $install_info['environment'] = $matches[1] === 'live' ? 'production' : $matches[1];
                $install_info['wpe_detected'] = true;
            }
        }
        
        return $install_info;
    }
    
    /**
     * Get list of installs from WP Engine API (with pagination support)
     */
    private function get_installs() {
        $settings = get_option('wpengine_backup_settings');
        
        if (empty($settings['api_username']) || empty($settings['api_password'])) {
            return array('success' => false, 'message' => __('API credentials not configured.', 'wpengine-backup-scheduler'));
        }
        
        $all_installs = array();
        $limit = 100; // Maximum allowed by WP Engine API
        $offset = 0;
        $has_more = true;
        
        // Loop through all pages to get all installs
        while ($has_more) {
            $url = $this->api_base_url . '/installs?limit=' . $limit . '&offset=' . $offset;
            
            error_log('WP Engine Backup - Fetching installs: ' . $url);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($settings['api_username'] . ':' . $settings['api_password']),
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
                ),
                'timeout' => 30,
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                error_log('WP Engine Backup - Error fetching installs: ' . $response->get_error_message());
                return array(
                    'success' => false, 
                    'message' => __('Failed to fetch installs: ', 'wpengine-backup-scheduler') . $response->get_error_message()
                );
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                error_log('WP Engine Backup - API error: ' . $response_code . ' - ' . $response_body);
                return array(
                    'success' => false,
                    'message' => sprintf(__('Failed to fetch installs. HTTP %d: %s', 'wpengine-backup-scheduler'), $response_code, $response_body)
                );
            }
            
            $installs_data = json_decode($response_body, true);
            
            if (!is_array($installs_data)) {
                error_log('WP Engine Backup - Invalid response format: ' . $response_body);
                return array(
                    'success' => false,
                    'message' => __('Invalid response format from WP Engine API', 'wpengine-backup-scheduler')
                );
            }
            
            // Handle different response structures
            $current_installs = array();
            $total_count = 0;
            
            if (isset($installs_data['results'])) {
                // Paginated response format
                $current_installs = $installs_data['results'];
                $total_count = $installs_data['count'] ?? 0;
                
                error_log('WP Engine Backup - Page results: ' . count($current_installs) . ' installs, total: ' . $total_count);
            } else {
                // Direct array format (fallback)
                $current_installs = $installs_data;
                $total_count = count($current_installs);
                
                error_log('WP Engine Backup - Direct results: ' . count($current_installs) . ' installs');
            }
            
            // Add current page results to our collection
            $all_installs = array_merge($all_installs, $current_installs);
            
            // Check if we need to fetch more pages
            $current_count = count($current_installs);
            $offset += $limit;
            
            // Stop if we got fewer results than the limit (last page) or if we have all installs
            if ($current_count < $limit || count($all_installs) >= $total_count) {
                $has_more = false;
            }
            
            error_log('WP Engine Backup - Progress: ' . count($all_installs) . ' of ' . $total_count . ' installs fetched');
            
            // Safety check to prevent infinite loops
            if ($offset > 1000) { // Reasonable safety limit
                error_log('WP Engine Backup - Safety limit reached, stopping pagination');
                $has_more = false;
            }
        }
        
        error_log('WP Engine Backup - Final result: ' . count($all_installs) . ' total installs fetched');
        
        return array(
            'success' => true,
            'installs' => $all_installs
        );
    }
    
    /**
     * Get install ID by name using API
     */
    private function get_install_id_by_name($install_name) {
        $installs_result = $this->get_installs();
        
        if (!$installs_result['success']) {
            return null;
        }
        
        foreach ($installs_result['installs'] as $install) {
            if (isset($install['name']) && $install['name'] === $install_name) {
                return $install['id'];
            }
        }
        
        return null;
    }
    
    /**
     * Auto-detect and configure current install
     */
    private function auto_configure_install() {
        $install_info = $this->get_current_install_info();
        
        if (empty($install_info['name'])) {
            return array(
                'success' => false,
                'message' => __('Could not detect WP Engine install name. This plugin must be run on a WP Engine hosted site.', 'wpengine-backup-scheduler')
            );
        }
        
        // Try to get the install ID via API
        $install_id = $this->get_install_id_by_name($install_info['name']);
        
        if ($install_id) {
            $settings = get_option('wpengine_backup_settings', array());
            $settings['install_id'] = $install_id;
            $settings['install_name'] = $install_info['name'];
            $settings['environment'] = $install_info['environment'];
            update_option('wpengine_backup_settings', $settings);
            
            return array(
                'success' => true,
                'message' => sprintf(__('Auto-detected install: %s (ID: %s)', 'wpengine-backup-scheduler'), $install_info['name'], $install_id),
                'install_id' => $install_id,
                'install_name' => $install_info['name'],
                'environment' => $install_info['environment']
            );
        } else {
            return array(
                'success' => false,
                'message' => sprintf(__('Detected install name "%s" but could not find corresponding install ID via API. Please check your API credentials.', 'wpengine-backup-scheduler'), $install_info['name'])
            );
        }
    }
    
    /**
     * Admin page HTML
     */
    public function admin_page() {
        $settings = get_option('wpengine_backup_settings', array());
        $backup_logs = $this->get_recent_backup_logs();
        
        ?>
        <div class="wrap">
            <h1><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?></h1>
            
            <div class="wpengine-backup-container">
                <div class="wpengine-backup-main">
                    <!-- API Settings Section -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('API Settings', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <form id="wpengine-api-settings">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('API Username', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <input type="text" name="api_username" value="<?php echo esc_attr($settings['api_username'] ?? ''); ?>" class="regular-text" />
                                            <p class="description"><?php _e('Your WP Engine API username', 'wpengine-backup-scheduler'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php _e('API Password', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <input type="password" name="api_password" value="<?php echo esc_attr($settings['api_password'] ?? ''); ?>" class="regular-text" />
                                            <p class="description"><?php _e('Your WP Engine API password', 'wpengine-backup-scheduler'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php _e('Current Install', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <?php
                                            $current_install = $this->get_current_install_info();
                                            if (!empty($current_install['name'])) {
                                                echo '<div class="notice notice-info inline" style="margin: 0 0 10px 0; padding: 8px 12px;">';
                                                echo '<p style="margin: 0;"><strong>' . __('Detected Current Install:', 'wpengine-backup-scheduler') . '</strong> ';
                                                echo esc_html($current_install['name']);
                                                if (!empty($current_install['environment'])) {
                                                    echo ' <span style="color: #0073aa;">(' . esc_html($current_install['environment']) . ')</span>';
                                                }
                                                echo '</p></div>';
                                            }
                                            ?>
                                            
                                            <button type="button" id="auto-detect-install-btn" class="button button-primary">
                                                <?php _e('Auto-Detect & Configure Current Install', 'wpengine-backup-scheduler'); ?>
                                            </button>
                                            <span id="auto-detect-status"></span>
                                            
                                            <p class="description" style="margin-top: 10px;">
                                                <?php _e('This will automatically detect the current WP Engine install and configure the plugin.', 'wpengine-backup-scheduler'); ?>
                                            </p>
                                            
                                            <details style="margin-top: 15px;">
                                                <summary style="cursor: pointer; font-weight: bold;"><?php _e('Manual Configuration', 'wpengine-backup-scheduler'); ?></summary>
                                                <div style="margin-top: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">
                                            
                                            <button type="button" id="fetch-installs-btn" class="button" style="margin-bottom: 10px;">
                                                <?php _e('Load All My Installs', 'wpengine-backup-scheduler'); ?>
                                            </button>
                                            <span id="fetch-installs-status"></span>
                                            <div id="installs-list" style="margin-top: 10px;"></div>
                                            
                                            <p><strong><?php _e('Or enter manually:', 'wpengine-backup-scheduler'); ?></strong></p>
                                            <label><?php _e('Install ID:', 'wpengine-backup-scheduler'); ?></label><br>
                                            <input type="text" name="install_id" value="<?php echo esc_attr($settings['install_id'] ?? ''); ?>" class="regular-text" placeholder="e.g., 12345" />
                                            <p class="description"><?php _e('Your WP Engine install ID (numeric)', 'wpengine-backup-scheduler'); ?></p>
                                            
                                            <label><?php _e('Install Name (for reference):', 'wpengine-backup-scheduler'); ?></label><br>
                                            <input type="text" name="install_name" value="<?php echo esc_attr($settings['install_name'] ?? ''); ?>" class="regular-text" placeholder="e.g., mysite" readonly />
                                            <p class="description"><?php _e('This will be filled automatically when you select an install above', 'wpengine-backup-scheduler'); ?></p>
                                            
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                </table>
                                <p>
                                    <button type="submit" class="button-primary"><?php _e('Save API Settings', 'wpengine-backup-scheduler'); ?></button>
                                    <button type="button" id="test-api-connection" class="button"><?php _e('Test API Connection', 'wpengine-backup-scheduler'); ?></button>
                                    <button type="button" id="debug-settings" class="button" style="margin-left: 10px;"><?php _e('Debug Settings', 'wpengine-backup-scheduler'); ?></button>
                                    <span id="api-test-result"></span>
                                </p>
                                <div id="debug-output" style="display: none; margin-top: 10px; padding: 10px; background: #f0f0f0; border: 1px solid #ccc; font-family: monospace; font-size: 12px;"></div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Email Notifications Section -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Email Notifications', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <p><?php _e('Configure email notifications for backup completion. This is required by the WP Engine API.', 'wpengine-backup-scheduler'); ?></p>
                            <form id="wpengine-email-settings">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('Email Address', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <input type="email" name="email_notifications" value="<?php echo esc_attr($settings['email_notifications'] ?? ''); ?>" class="regular-text" required />
                                            <p class="description"><strong><?php _e('Required:', 'wpengine-backup-scheduler'); ?></strong> <?php _e('Email address to notify when backups complete. This is required by the WP Engine API.', 'wpengine-backup-scheduler'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                                <p>
                                    <button type="submit" class="button-primary"><?php _e('Save Email Settings', 'wpengine-backup-scheduler'); ?></button>
                                </p>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Backup Schedule Section -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Backup Schedule', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <form id="wpengine-backup-schedule">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('Enable Automatic Backups', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled'] ?? false); ?> />
                                                <?php _e('Enable scheduled backups', 'wpengine-backup-scheduler'); ?>
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php _e('Backup Frequency', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <select name="backup_frequency">
                                                <?php for ($i = 1; $i <= 23; $i++) : ?>
                                                    <option value="<?php echo $i; ?>" <?php selected($settings['backup_frequency'] ?? 24, $i); ?>>
                                                        <?php printf(_n('Every %d Hour', 'Every %d Hours', $i, 'wpengine-backup-scheduler'), $i); ?>
                                                    </option>
                                                <?php endfor; ?>
                                                <option value="24" <?php selected($settings['backup_frequency'] ?? 24, 24); ?>>
                                                    <?php _e('Every 24 Hours (Daily)', 'wpengine-backup-scheduler'); ?>
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                                <p>
                                    <button type="submit" class="button-primary"><?php _e('Save Schedule Settings', 'wpengine-backup-scheduler'); ?></button>
                                    <?php if ($settings['enabled'] ?? false) : ?>
                                        <button type="button" id="cancel-schedule-btn" class="button button-secondary" style="margin-left: 10px;">
                                            <?php _e('Cancel Scheduled Backups', 'wpengine-backup-scheduler'); ?>
                                        </button>
                                    <?php endif; ?>
                                </p>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Manual Backup Section -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Manual Backup', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <p><?php _e('Create an on-demand backup of your website.', 'wpengine-backup-scheduler'); ?></p>
                            <form id="wpengine-manual-backup">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('Backup Description', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <input type="text" name="backup_description" placeholder="<?php _e('e.g., Before plugin update', 'wpengine-backup-scheduler'); ?>" class="regular-text" />
                                        </td>
                                    </tr>
                                </table>
                                <p>
                                    <button type="submit" class="button-primary" id="create-backup-btn">
                                        <?php _e('Create Backup Now', 'wpengine-backup-scheduler'); ?>
                                    </button>
                                    <span id="backup-status"></span>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="wpengine-backup-sidebar">
                    <!-- Recent Backups -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Recent Backup Activity', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <?php if (!empty($backup_logs)) : ?>
                                <ul class="backup-log-list">
                                    <?php foreach ($backup_logs as $log) : ?>
                                        <li class="backup-log-item status-<?php echo esc_attr($log->status); ?>">
                                            <div class="backup-log-type"><?php echo esc_html(ucfirst($log->backup_type)); ?></div>
                                            <div class="backup-log-status"><?php echo esc_html(ucfirst($log->status)); ?></div>
                                            <div class="backup-log-date"><?php echo esc_html($log->created_at); ?></div>
                                            <?php if ($log->message) : ?>
                                                <div class="backup-log-message"><?php echo esc_html($log->message); ?></div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p><?php _e('No backup activity yet.', 'wpengine-backup-scheduler'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Next Scheduled Backup -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Next Scheduled Backup', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <?php
                            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
                            if ($next_backup && ($settings['enabled'] ?? false)) {
                                echo '<p>' . sprintf(__('Next backup: %s', 'wpengine-backup-scheduler'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_backup)) . '</p>';
                                echo '<p><button type="button" id="cancel-schedule-sidebar-btn" class="button button-secondary button-small">' . __('Cancel Schedule', 'wpengine-backup-scheduler') . '</button></p>';
                            } else {
                                echo '<p>' . __('No backup scheduled', 'wpengine-backup-scheduler') . '</p>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <!-- Help -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Help & Documentation', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <ul>
                                <li><a href="https://wpengine.com/support/enabling-wp-engine-api/" target="_blank"><?php _e('Enable WP Engine API', 'wpengine-backup-scheduler'); ?></a></li>
                                <li><a href="https://wpengineapi.com/reference" target="_blank"><?php _e('API Documentation', 'wpengine-backup-scheduler'); ?></a></li>
                                <li><a href="https://wpengine.com/support/restore/" target="_blank"><?php _e('Backup & Restore Guide', 'wpengine-backup-scheduler'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .wpengine-backup-container {
            display: flex;
            gap: 20px;
        }
        .wpengine-backup-main {
            flex: 2;
        }
        .wpengine-backup-sidebar {
            flex: 1;
        }
        .wpengine-backup-main .postbox,
        .wpengine-backup-sidebar .postbox {
            margin-bottom: 20px;
            padding: 1em;
        }
        .backup-log-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .backup-log-item {
            padding: 10px;
            margin-bottom: 5px;
            border-left: 4px solid #ddd;
            background: #f9f9f9;
        }
        .backup-log-item.status-success {
            border-left-color: #46b450;
        }
        .backup-log-item.status-error {
            border-left-color: #dc3232;
        }
        .backup-log-item.status-running {
            border-left-color: #ffb900;
        }
        .backup-log-type {
            font-weight: bold;
        }
        .backup-log-status {
            font-size: 12px;
            text-transform: uppercase;
        }
        .backup-log-date {
            font-size: 11px;
            color: #666;
        }
        .backup-log-message {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        #backup-status, #api-test-result {
            margin-left: 10px;
            font-weight: bold;
        }
        .status-success {
            color: #46b450;
        }
        .status-error {
            color: #dc3232;
        }
        .status-running {
            color: #ffb900;
        }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for canceling scheduled backups
     */
    public function ajax_cancel_schedule() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->cancel_scheduled_backups();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Cancel all scheduled backups
     */
    private function cancel_scheduled_backups() {
        // Clear all scheduled backup events
        $cleared = wp_clear_scheduled_hook('wpengine_backup_cron_hook');
        
        // Update settings to disable scheduling
        $settings = get_option('wpengine_backup_settings', array());
        $settings['enabled'] = false;
        update_option('wpengine_backup_settings', $settings);
        
        // Log the cancellation
        $this->log_backup_activity('scheduled', 'cancelled', __('Scheduled backups cancelled by user', 'wpengine-backup-scheduler'));
        
        return array(
            'success' => true,
            'message' => __('All scheduled backups have been cancelled and automatic backups are now disabled.', 'wpengine-backup-scheduler')
        );
    }
    
    /**
     * AJAX handler for debugging settings (temporary)
     */
    public function ajax_debug_settings() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $settings = get_option('wpengine_backup_settings');
        
        // Mask password for security
        if (!empty($settings['api_password'])) {
            $settings['api_password'] = str_repeat('*', strlen($settings['api_password']));
        }
        
        wp_send_json_success($settings);
    }
    
    /**
     * AJAX handler for auto-detecting install
     */
    public function ajax_auto_detect_install() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->auto_configure_install();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for fetching WP Engine installs
     */
    public function ajax_fetch_installs() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->get_installs();
        
        if ($result['success']) {
            wp_send_json_success($result['installs']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Get list of backups from WP Engine API
     */
    private function get_api_backups($install_id = null) {
        $settings = get_option('wpengine_backup_settings');
        
        if (empty($settings['api_username']) || empty($settings['api_password'])) {
            return array('success' => false, 'message' => __('API credentials not configured.', 'wpengine-backup-scheduler'));
        }
        
        $install = $install_id ?: $settings['install_id'];
        if (empty($install)) {
            return array('success' => false, 'message' => __('Install ID not configured.', 'wpengine-backup-scheduler'));
        }
        
        $url = $this->api_base_url . '/installs/' . urlencode($install) . '/backups';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['api_username'] . ':' . $settings['api_password']),
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ),
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false, 
                'message' => __('Failed to fetch backups: ', 'wpengine-backup-scheduler') . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            $backups_data = json_decode($response_body, true);
            return array(
                'success' => true,
                'backups' => $backups_data['results'] ?? $backups_data ?? array()
            );
        } else {
            return array(
                'success' => false,
                'message' => sprintf(__('Failed to fetch backups. HTTP %d: %s', 'wpengine-backup-scheduler'), $response_code, $response_body)
            );
        }
    }
    
    /**
     * AJAX handler for fetching WP Engine backups
     */
    public function ajax_fetch_backups() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->get_api_backups();
        
        if ($result['success']) {
            wp_send_json_success($result['backups']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for creating backup
     */
    public function ajax_create_backup() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $description = sanitize_text_field($_POST['description'] ?? '');
        
        $result = $this->create_backup('manual', $description);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for saving API settings only
     */
    public function ajax_save_api_settings() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        // Get existing settings first
        $settings = get_option('wpengine_backup_settings', array());
        
        // Update only the API-related settings
        $settings['api_username'] = sanitize_text_field($_POST['api_username'] ?? '');
        $settings['api_password'] = sanitize_text_field($_POST['api_password'] ?? '');
        $settings['install_id'] = sanitize_text_field($_POST['install_id'] ?? '');
        $settings['install_name'] = sanitize_text_field($_POST['install_name'] ?? '');
        $settings['email_notifications'] = sanitize_email($_POST['email_notifications'] ?? '');
        
        // Keep existing schedule settings if they exist
        if (!isset($settings['backup_frequency'])) {
            $settings['backup_frequency'] = '24';
        }
        if (!isset($settings['email_notifications'])) {
            $settings['email_notifications'] = '';
        }
        if (!isset($settings['enabled'])) {
            $settings['enabled'] = false;
        }
        
        update_option('wpengine_backup_settings', $settings);
        
        wp_send_json_success(__('API settings saved successfully!', 'wpengine-backup-scheduler'));
    }
    
    /**
     * AJAX handler for saving schedule
     */
    public function ajax_save_schedule() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $settings = array(
            'api_username' => sanitize_text_field($_POST['api_username'] ?? ''),
            'api_password' => sanitize_text_field($_POST['api_password'] ?? ''),
            'install_id' => sanitize_text_field($_POST['install_id'] ?? ''),
            'install_name' => sanitize_text_field($_POST['install_name'] ?? ''),
            'backup_frequency' => intval($_POST['backup_frequency'] ?? 24),
            'email_notifications' => sanitize_email($_POST['email_notifications'] ?? ''),
            'enabled' => isset($_POST['enabled'])
        );
        
        update_option('wpengine_backup_settings', $settings);
        
        // Update cron schedule
        wp_clear_scheduled_hook('wpengine_backup_cron_hook');
        
        if ($settings['enabled']) {
            $interval = $settings['backup_frequency'] == 24 ? 'daily' : "every_{$settings['backup_frequency']}_hours";
            wp_schedule_event(time(), $interval, 'wpengine_backup_cron_hook');
        }
        
        wp_send_json_success(__('Settings saved successfully!', 'wpengine-backup-scheduler'));
    }
    
    /**
     * AJAX handler for testing API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $username = sanitize_text_field($_POST['api_username'] ?? '');
        $password = sanitize_text_field($_POST['api_password'] ?? '');
        
        $result = $this->test_api_connection($username, $password);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Test API connection
     */
    private function test_api_connection($username, $password) {
        if (empty($username) || empty($password)) {
            return array(
                'success' => false,
                'message' => __('Please enter both username and password.', 'wpengine-backup-scheduler')
            );
        }
        
        $url = $this->api_base_url . '/accounts';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Connection failed: ', 'wpengine-backup-scheduler') . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            return array(
                'success' => true,
                'message' => __('API connection successful!', 'wpengine-backup-scheduler')
            );
        } else {
            return array(
                'success' => false,
                'message' => sprintf(__('API connection failed with status code: %d', 'wpengine-backup-scheduler'), $response_code)
            );
        }
    }
    
    /**
     * Create backup via API
     */
    private function create_backup($type = 'manual', $description = '') {
        $settings = get_option('wpengine_backup_settings');
        
        // Debug: Log the settings to see what we have
        error_log('WP Engine Backup - Settings check: ' . print_r($settings, true));
        
        // Check for API credentials
        $api_username = $settings['api_username'] ?? '';
        $api_password = $settings['api_password'] ?? '';
        $install_id = $settings['install_id'] ?? '';
        
        error_log('WP Engine Backup - Credentials check: username=' . (!empty($api_username) ? 'SET' : 'EMPTY') . ', password=' . (!empty($api_password) ? 'SET' : 'EMPTY') . ', install_id=' . (!empty($install_id) ? $install_id : 'EMPTY'));
        
        if (empty($api_username) || empty($api_password)) {
            return array(
                'success' => false,
                'message' => __('API username or password not configured. Please save your API credentials first.', 'wpengine-backup-scheduler')
            );
        }
        
        if (empty($install_id)) {
            return array(
                'success' => false,
                'message' => __('Install ID not configured. Please use "Auto-Detect & Configure Current Install" first.', 'wpengine-backup-scheduler')
            );
        }
        
        // Check for notification email - it's required by WP Engine API
        $notification_email = $settings['email_notifications'] ?? '';
        if (empty($notification_email)) {
            return array(
                'success' => false,
                'message' => __('Notification email is required for backups. Please add an email address in the backup schedule settings and save.', 'wpengine-backup-scheduler')
            );
        }
        
        // Log backup start
        $log_id = $this->log_backup_activity($type, 'running', __('Backup initiated', 'wpengine-backup-scheduler'));
        
        $url = $this->api_base_url . '/installs/' . urlencode($install_id) . '/backups';
        
        // Prepare backup data with required notification_emails field
        $backup_data = array(
            'notification_emails' => array($notification_email)
        );
        
        // Add description if provided
        if (!empty($description)) {
            $backup_data['description'] = $description;
        }
        
        error_log('WP Engine Backup - API Request: URL=' . $url . ', Data=' . json_encode($backup_data));
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_username . ':' . $api_password),
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ),
            'body' => json_encode($backup_data),
            'timeout' => 120,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('WP Engine Backup - WP Error: ' . $error_message);
            $this->update_backup_log($log_id, 'error', $error_message);
            return array(
                'success' => false,
                'message' => __('Backup failed: ', 'wpengine-backup-scheduler') . $error_message
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log the response for debugging
        error_log('WP Engine Backup API Response: ' . $response_code . ' - ' . $response_body);
        
        if ($response_code === 201 || $response_code === 200 || $response_code === 202) {
            $backup_response = json_decode($response_body, true);
            $backup_id = null;
            $backup_status = 'unknown';
            
            // Handle different response structures
            if (is_array($backup_response)) {
                $backup_id = $backup_response['id'] ?? $backup_response['backup_id'] ?? 'created';
                $backup_status = $backup_response['status'] ?? 'created';
            }
            
            // Determine success message based on status code
            $message = '';
            if ($response_code === 202) {
                // Accepted - backup is being processed
                $message = __('Backup request accepted! Your backup is being created and you will receive an email notification when it completes.', 'wpengine-backup-scheduler');
            } else {
                // 200/201 - backup completed immediately (less common)
                $message = __('Backup created successfully! You will receive an email notification when it completes.', 'wpengine-backup-scheduler');
            }
            
            $this->update_backup_log($log_id, 'success', sprintf(__('Backup %s (ID: %s)', 'wpengine-backup-scheduler'), $backup_status, $backup_id), $backup_id);
            
            return array(
                'success' => true,
                'message' => $message,
                'backup_id' => $backup_id,
                'status' => $backup_status
            );
        } else {
            // Parse error response for more details
            $error_message = '';
            $error_data = json_decode($response_body, true);
            
            if (is_array($error_data)) {
                // Log the full error response for debugging
                error_log('WP Engine Backup - Full Error Response: ' . print_r($error_data, true));
                
                if (isset($error_data['message'])) {
                    $error_message = $error_data['message'];
                } elseif (isset($error_data['error'])) {
                    $error_message = $error_data['error'];
                } elseif (isset($error_data['detail'])) {
                    $error_message = $error_data['detail'];
                } elseif (isset($error_data['errors']) && is_array($error_data['errors'])) {
                    $error_details = array();
                    foreach ($error_data['errors'] as $error) {
                        if (isset($error['message'])) {
                            $error_details[] = $error['message'];
                        }
                    }
                    $error_message = implode(', ', $error_details);
                } else {
                    $error_message = 'Unknown API error: ' . $response_body;
                }
            } else {
                $error_message = 'HTTP ' . $response_code . ': ' . $response_body;
            }
            
            // Handle specific error codes with more context
            switch ($response_code) {
                case 400:
                    $error_message = __('Bad request - The API request format is invalid. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 401:
                    $error_message = __('Authentication failed - Please check your API credentials. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 403:
                    $error_message = __('Access denied - You may not have permission to create backups for this install. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 404:
                    $error_message = __('Install not found - Please check your install ID. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 405:
                    $error_message = __('Method not allowed - The backup endpoint may not be available. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 429:
                    $error_message = __('Too many requests - Please wait before creating another backup. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 500:
                    $error_message = __('Server error - Please try again later or contact WP Engine support. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                default:
                    $error_message = sprintf(__('HTTP %d error: ', 'wpengine-backup-scheduler'), $response_code) . $error_message;
                    break;
            }
            
            $this->update_backup_log($log_id, 'error', $error_message);
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * Execute scheduled backup with WP Engine optimizations
     */
    public function execute_scheduled_backup() {
        // Prevent overlapping executions using transients
        $transient_key = 'wpengine_backup_running';
        if (get_transient($transient_key)) {
            error_log('WP Engine Backup - Skipping execution: backup already in progress');
            return false;
        }
        
        // Set transient to prevent overlap (expires in 5 minutes as safety net)
        set_transient($transient_key, time(), 5 * MINUTE_IN_SECONDS);
        
        // Add timeout protection for WP Engine Alternate Cron (must complete in under 60 seconds)
        $start_time = time();
        $max_execution_time = 50; // seconds - leave buffer for WP Engine's 60-second limit
        
        try {
            // Check if we're running on WP Engine
            $wpe_detected = $this->is_wpengine_hosting();
            
            if ($wpe_detected) {
                error_log('WP Engine Backup - Executing scheduled backup on WP Engine hosting');
            }
            
            // Execute the backup with timeout monitoring
            $result = $this->create_backup_with_timeout('scheduled', __('Automated scheduled backup', 'wpengine-backup-scheduler'), $max_execution_time, $start_time);
            
            // Clear the running transient on success
            delete_transient($transient_key);
            
            return $result;
            
        } catch (Exception $e) {
            error_log('WP Engine Backup - Scheduled backup failed with exception: ' . $e->getMessage());
            delete_transient($transient_key);
            
            // Log the failure
            $this->log_backup_activity('scheduled', 'error', 'Backup failed with exception: ' . $e->getMessage());
            
            return false;
        }
    }
    
    /**
     * Check if running on WP Engine hosting
     */
    public function is_wpengine_hosting() {
        // Multiple detection methods for WP Engine
        $indicators = [
            defined('WPE_APIKEY'),
            isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'WP Engine') !== false,
            defined('ABSPATH') && strpos(ABSPATH, '/nas/content/') !== false,
            isset($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['DOCUMENT_ROOT'], '/nas/content/') !== false,
            isset($_SERVER['SERVER_NAME']) && (
                strpos($_SERVER['SERVER_NAME'], '.wpengine.com') !== false ||
                strpos($_SERVER['SERVER_NAME'], '.staging.wpengine.com') !== false ||
                strpos($_SERVER['SERVER_NAME'], '.dev.wpengine.com') !== false
            )
        ];
        
        return in_array(true, $indicators, true);
    }
    
    /**
     * Create backup with timeout protection for WP Engine Alternate Cron
     */
    private function create_backup_with_timeout($type = 'manual', $description = '', $max_execution_time = 50, $start_time = null) {
        $start_time = $start_time ?: time();
        
        // Quick pre-flight checks to avoid timeouts
        $elapsed = time() - $start_time;
        if ($elapsed > ($max_execution_time - 10)) {
            return array(
                'success' => false,
                'message' => __('Backup skipped: insufficient time remaining for safe execution', 'wpengine-backup-scheduler')
            );
        }
        
        // Use the existing create_backup method but with timeout awareness
        return $this->create_backup($type, $description);
    }
    
    /**
     * Log backup activity
     */
    private function log_backup_activity($type, $status, $message, $backup_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'backup_type' => $type,
                'status' => $status,
                'message' => $message,
                'backup_id' => $backup_id,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update backup log
     */
    private function update_backup_log($log_id, $status, $message, $backup_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        $update_data = array(
            'status' => $status,
            'message' => $message,
            'completed_at' => current_time('mysql')
        );
        
        if ($backup_id) {
            $update_data['backup_id'] = $backup_id;
        }
        
        $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $log_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Get recent backup logs
     */
    private function get_recent_backup_logs($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }
}

// Initialize the plugin
new WPEngineBackupScheduler();

// Admin JavaScript (inline for simplicity - in production, this should be in a separate file)
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen->id !== 'tools_page_wpengine-backup-scheduler') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Save Email Settings
        $('#wpengine-email-settings').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Saving...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_api_settings',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val(),
                email_notifications: $('input[name="email_notifications"]').val()
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Save API Settings
        $('#wpengine-api-settings').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Saving...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_api_settings',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val()
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Debug Settings
        $('#debug-settings').on('click', function() {
            var $btn = $(this);
            var $output = $('#debug-output');
            
            $btn.prop('disabled', true).text('<?php _e('Loading...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_debug_settings',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    $output.html('<strong>Current Settings:</strong><br>' + JSON.stringify(response.data, null, 2)).show();
                } else {
                    $output.html('<strong>Error:</strong> ' + response.data).show();
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Debug Settings', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Test API Connection
        $('#test-api-connection').on('click', function() {
            var $btn = $(this);
            var $result = $('#api-test-result');
            
            $btn.prop('disabled', true).text(wpengineBackup.strings.testing_api);
            $result.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_test_api',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val()
            }, function(response) {
                if (response.success) {
                    $result.addClass('status-success').text(response.data);
                } else {
                    $result.addClass('status-error').text(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Test API Connection', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Auto-detect current install
        $('#auto-detect-install-btn').on('click', function() {
            var $btn = $(this);
            var $status = $('#auto-detect-status');
            
            $btn.prop('disabled', true).text('<?php _e('Detecting...', 'wpengine-backup-scheduler'); ?>');
            $status.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_auto_detect_install',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    $('input[name="install_id"]').val(response.data.install_id);
                    $('input[name="install_name"]').val(response.data.install_name);
                    
                    $status.addClass('status-success').text(response.data.message);
                    
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>')
                        .insertAfter('.wrap h1').delay(5000).fadeOut();
                } else {
                    $status.addClass('status-error').text(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Auto-Detect & Configure Current Install', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Fetch WP Engine Installs
        $('#fetch-installs-btn').on('click', function() {
            var $btn = $(this);
            var $status = $('#fetch-installs-status');
            var $list = $('#installs-list');
            
            $btn.prop('disabled', true).text('<?php _e('Loading all installs...', 'wpengine-backup-scheduler'); ?>');
            $status.removeClass('status-success status-error').text('<?php _e('This may take a moment for accounts with many installs...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_fetch_installs',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success && response.data) {
                    var installs = response.data;
                    var html = '';
                    
                    if (installs.length > 0) {
                        html = '<div style="border: 1px solid #ddd; padding: 10px; background: #f9f9f9; max-height: 300px; overflow-y: auto;">';
                        html += '<p><strong><?php _e('Found', 'wpengine-backup-scheduler'); ?> ' + installs.length + ' <?php _e('installs. Select your install:', 'wpengine-backup-scheduler'); ?></strong></p>';
                        
                        // Add search box for many installs
                        if (installs.length > 10) {
                            html += '<input type="text" id="install-search" placeholder="<?php _e('Search installs...', 'wpengine-backup-scheduler'); ?>" style="width: 100%; margin-bottom: 10px; padding: 5px;">';
                        }
                        
                        html += '<div id="install-options">';
                        installs.forEach(function(install) {
                            html += '<label style="display: block; margin: 5px 0; cursor: pointer;" class="install-option" data-name="' + (install.name || '').toLowerCase() + '">';
                            html += '<input type="radio" name="selected_install" value="' + install.id + '" data-name="' + (install.name || '') + '" style="margin-right: 8px;">';
                            html += '<span style="font-weight: bold;">' + (install.name || 'Unnamed') + '</span>';
                            html += ' <span style="color: #666;">(ID: ' + install.id + ')</span>';
                            if (install.environment) {
                                html += ' <span style="color: #0073aa;">(' + install.environment + ')</span>';
                            }
                            html += '</label>';
                        });
                        html += '</div>';
                        
                        html += '<button type="button" id="select-install-btn" class="button button-primary" style="margin-top: 10px;"><?php _e('Use Selected Install', 'wpengine-backup-scheduler'); ?></button>';
                        html += '</div>';
                    } else {
                        html = '<p class="error"><?php _e('No installs found.', 'wpengine-backup-scheduler'); ?></p>';
                    }
                    
                    $list.html(html);
                    $status.addClass('status-success').text('<?php _e('All installs loaded successfully', 'wpengine-backup-scheduler'); ?>');
                    
                    // Add search functionality
                    $('#install-search').on('input', function() {
                        var searchTerm = $(this).val().toLowerCase();
                        $('.install-option').each(function() {
                            var installName = $(this).data('name');
                            if (installName.indexOf(searchTerm) !== -1) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    });
                    
                } else {
                    $list.html('<p class="error"><?php _e('Failed to load installs.', 'wpengine-backup-scheduler'); ?></p>');
                    $status.addClass('status-error').text(response.data || '<?php _e('Unknown error occurred', 'wpengine-backup-scheduler'); ?>');
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Load All My Installs', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Handle install selection
        $(document).on('click', '#select-install-btn', function() {
            var selectedInstall = $('input[name="selected_install"]:checked');
            if (selectedInstall.length) {
                var installId = selectedInstall.val();
                var installName = selectedInstall.data('name');
                
                $('input[name="install_id"]').val(installId);
                $('input[name="install_name"]').val(installName);
                
                $('#installs-list').html('<p class="success"><?php _e('Install selected successfully!', 'wpengine-backup-scheduler'); ?></p>');
            } else {
                alert('<?php _e('Please select an install first.', 'wpengine-backup-scheduler'); ?>');
            }
        });
        
        // Cancel Scheduled Backups
        $('#cancel-schedule-btn, #cancel-schedule-sidebar-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            
            // Confirm before canceling
            if (!confirm('<?php _e('Are you sure you want to cancel all scheduled backups? This will disable automatic backups.', 'wpengine-backup-scheduler'); ?>')) {
                return;
            }
            
            $btn.prop('disabled', true).text('<?php _e('Canceling...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_cancel_schedule',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                    
                    // Update the UI
                    $('input[name="enabled"]').prop('checked', false);
                    $('#cancel-schedule-btn').hide();
                    
                    // Reload page to update the sidebar
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Save Schedule Settings
        $('#wpengine-backup-schedule').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            
            $btn.prop('disabled', true);
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_schedule',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val(),
                backup_frequency: $('select[name="backup_frequency"]').val(),
                email_notifications: $('input[name="email_notifications"]').val(),
                enabled: $('input[name="enabled"]').is(':checked') ? 1 : 0
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                    
                    // Reload page to update next backup time
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
        
        // Create Manual Backup
        $('#wpengine-manual-backup').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $('#create-backup-btn');
            var $status = $('#backup-status');
            var description = $('input[name="backup_description"]').val();
            
            $btn.prop('disabled', true).text(wpengineBackup.strings.creating_backup);
            $status.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_create_backup',
                nonce: wpengineBackup.nonce,
                description: description
            }, function(response) {
                if (response.success) {
                    $status.addClass('status-success').text(wpengineBackup.strings.backup_created);
                    $('input[name="backup_description"]').val('');
                    
                    // Reload page to update backup logs
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $status.addClass('status-error').text(wpengineBackup.strings.backup_failed + ' ' + response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Create Backup Now', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Auto-dismiss notices
        $(document).on('click', '.notice-dismissible .notice-dismiss', function() {
            $(this).parent().fadeOut();
        });
    });
    </script>
    <?php
});

/**
 * Additional helper functions and hooks
 */

// Add settings link to plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('tools.php?page=wpengine-backup-scheduler') . '">' . __('Settings', 'wpengine-backup-scheduler') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Add admin notice if API credentials are not set
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen->id !== 'tools_page_wpengine-backup-scheduler') {
        return;
    }
    
    $settings = get_option('wpengine_backup_settings', array());
    $plugin_instance = new WPEngineBackupScheduler();
    
    // Check for WP Engine Alternate Cron setup requirements
    $is_wpengine = $plugin_instance->is_wpengine_hosting();
    $is_wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    
    if ($is_wpengine && !$is_wp_cron_disabled) {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler - IMPORTANT SETUP REQUIRED', 'wpengine-backup-scheduler'); ?>:</strong><br>
                <?php _e('For reliable hourly backups on WP Engine hosting, please complete these steps:', 'wpengine-backup-scheduler'); ?>
            </p>
            <ol style="margin: 10px 0 10px 25px;">
                <li><?php _e('Add this line to your wp-config.php file:', 'wpengine-backup-scheduler'); ?> <code>define( 'DISABLE_WP_CRON', true );</code></li>
                <li><?php _e('Enable "Alternate Cron" in your WP Engine User Portal under Utilities', 'wpengine-backup-scheduler'); ?></li>
            </ol>
            <p><a href="https://wpengine.com/support/wp-cron-wordpress-scheduling/" target="_blank"><?php _e('View WP Engine Cron Setup Guide', 'wpengine-backup-scheduler'); ?></a></p>
        </div>
        <?php
    } elseif ($is_wpengine && $is_wp_cron_disabled) {
        ?>
        <div class="notice notice-success">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('✓ WP Engine hosting detected with proper cron configuration. Your hourly backups should run reliably!', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    }
    
    if (empty($settings['api_username']) || empty($settings['api_password'])) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('Please configure your API credentials below, then use "Auto-Detect & Configure Current Install" to automatically set up the plugin.', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    } elseif (empty($settings['install_id'])) {
        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('Click "Auto-Detect & Configure Current Install" to automatically configure this plugin for the current WP Engine install.', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    } elseif (empty($settings['email_notifications'])) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('Please add an email address in the backup schedule settings. Email notifications are required by the WP Engine API for backup creation.', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    }
});

// Clean up old backup logs (keep only last 100 entries)
add_action('wpengine_backup_cron_hook', function() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wpengine_backup_logs';
    
    // Delete old logs, keeping only the 100 most recent
    $wpdb->query("
        DELETE FROM $table_name 
        WHERE id NOT IN (
            SELECT id FROM (
                SELECT id FROM $table_name 
                ORDER BY created_at DESC 
                LIMIT 100
            ) AS recent_logs
        )
    ");
});

// Add dashboard widget for backup status
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'wpengine_backup_status',
        __('WP Engine Backup Status', 'wpengine-backup-scheduler'),
        function() {
            $settings = get_option('wpengine_backup_settings', array());
            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $last_backup = $wpdb->get_row("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1");
            
            echo '<div class="wpengine-backup-dashboard-widget">';
            
            if ($settings['enabled'] ?? false) {
                echo '<p><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ';
                echo __('Automatic backups enabled', 'wpengine-backup-scheduler') . '</p>';
                
                if ($next_backup) {
                    echo '<p><strong>' . __('Next backup:', 'wpengine-backup-scheduler') . '</strong> ';
                    echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_backup) . '</p>';
                }
            } else {
                echo '<p><span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ';
                echo __('Automatic backups disabled', 'wpengine-backup-scheduler') . '</p>';
            }
            
            if ($last_backup) {
                echo '<p><strong>' . __('Last backup:', 'wpengine-backup-scheduler') . '</strong> ';
                echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_backup->created_at));
                echo ' (' . ucfirst($last_backup->status) . ')</p>';
            }
            
            echo '<p><a href="' . admin_url('tools.php?page=wpengine-backup-scheduler') . '" class="button">';
            echo __('Manage Backups', 'wpengine-backup-scheduler') . '</a></p>';
            echo '</div>';
        }
    );
});

// Add WP-CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    /**
     * WP-CLI commands for WP Engine Backup Scheduler
     */
    class WPEngine_Backup_CLI_Command {
        
        /**
         * Create a manual backup
         *
         * ## OPTIONS
         *
         * [--description=<description>]
         * : Description for the backup
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup create --description="Pre-update backup"
         */
        public function create($args, $assoc_args) {
            $plugin = new WPEngineBackupScheduler();
            $description = $assoc_args['description'] ?? 'WP-CLI backup';
            
            WP_CLI::log('Creating backup...');
            
            $result = $plugin->create_backup('manual', $description);
            
            if ($result['success']) {
                WP_CLI::success($result['message']);
            } else {
                WP_CLI::error($result['message']);
            }
        }
        
        /**
         * Show backup status and recent activity
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup status
         */
        public function status($args, $assoc_args) {
            $settings = get_option('wpengine_backup_settings', array());
            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
            $plugin = new WPEngineBackupScheduler();
            
            WP_CLI::log('WP Engine Backup Scheduler Status:');
            WP_CLI::log('');
            
            // WP Engine hosting check
            $is_wpengine = $plugin->is_wpengine_hosting();
            $is_wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            
            if ($is_wpengine) {
                WP_CLI::log('✓ WP Engine hosting: DETECTED');
                WP_CLI::log('  WP_CRON disabled: ' . ($is_wp_cron_disabled ? '✓ YES (recommended)' : '✗ NO (should enable Alternate Cron)'));
                
                if (!$is_wp_cron_disabled) {
                    WP_CLI::log('  ⚠️  For reliable hourly backups, add DISABLE_WP_CRON to wp-config.php and enable Alternate Cron');
                }
            } else {
                WP_CLI::log('? WP Engine hosting: NOT DETECTED');
            }
            
            WP_CLI::log('');
            
            if ($settings['enabled'] ?? false) {
                WP_CLI::log('✓ Automatic backups: ENABLED');
                WP_CLI::log('  Frequency: Every ' . ($settings['backup_frequency'] ?? 24) . ' hours');
                
                if ($next_backup) {
                    WP_CLI::log('  Next backup: ' . date_i18n('Y-m-d H:i:s', $next_backup));
                }
            } else {
                WP_CLI::log('✗ Automatic backups: DISABLED');
            }
            
            WP_CLI::log('');
            
            // Show recent backup logs
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $recent_logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 5");
            
            if ($recent_logs) {
                WP_CLI::log('Recent backup activity:');
                foreach ($recent_logs as $log) {
                    $status_icon = $log->status === 'success' ? '✓' : ($log->status === 'error' ? '✗' : '⋯');
                    WP_CLI::log(sprintf(
                        '  %s %s %s (%s) - %s',
                        $status_icon,
                        $log->created_at,
                        ucfirst($log->backup_type),
                        ucfirst($log->status),
                        $log->message
                    ));
                }
            } else {
                WP_CLI::log('No backup activity recorded.');
            }
        }
        
        /**
         * Enable or disable automatic backups
         *
         * ## OPTIONS
         *
         * <action>
         * : Action to perform (enable|disable)
         *
         * [--frequency=<hours>]
         * : Backup frequency in hours (1-24)
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup toggle enable --frequency=12
         *     wp wpengine-backup toggle disable
         */
        public function toggle($args, $assoc_args) {
            $action = $args[0] ?? '';
            
            if (!in_array($action, ['enable', 'disable'])) {
                WP_CLI::error('Invalid action. Use "enable" or "disable".');
            }
            
            $settings = get_option('wpengine_backup_settings', array());
            $settings['enabled'] = ($action === 'enable');
            
            if ($action === 'enable' && isset($assoc_args['frequency'])) {
                $frequency = intval($assoc_args['frequency']);
                if ($frequency >= 1 && $frequency <= 24) {
                    $settings['backup_frequency'] = $frequency;
                } else {
                    WP_CLI::error('Frequency must be between 1 and 24 hours.');
                }
            }
            
            update_option('wpengine_backup_settings', $settings);
            
            // Update cron schedule
            wp_clear_scheduled_hook('wpengine_backup_cron_hook');
            
            if ($settings['enabled']) {
                $interval = $settings['backup_frequency'] == 24 ? 'daily' : "every_{$settings['backup_frequency']}_hours";
                wp_schedule_event(time(), $interval, 'wpengine_backup_cron_hook');
            }
            
            WP_CLI::success(sprintf('Automatic backups %s.', $action === 'enable' ? 'enabled' : 'disabled'));
        }
        
        /**
         * Auto-detect current WP Engine install
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup detect
         */
        public function detect($args, $assoc_args) {
            $plugin = new WPEngineBackupScheduler();
            
            WP_CLI::log('Auto-detecting current WP Engine install...');
            
            $result = $plugin->auto_configure_install();
            
            if ($result['success']) {
                WP_CLI::success($result['message']);
                WP_CLI::log('Install ID: ' . $result['install_id']);
                WP_CLI::log('Install Name: ' . $result['install_name']);
                WP_CLI::log('Environment: ' . $result['environment']);
            } else {
                WP_CLI::error($result['message']);
            }
        }
    }
    
    WP_CLI::add_command('wpengine-backup', 'WPEngine_Backup_CLI_Command');
}

/**
 * REST API endpoints for external integrations
 */
add_action('rest_api_init', function() {
    register_rest_route('wpengine-backup/v1', '/status', array(
        'methods' => 'GET',
        'callback' => function() {
            $settings = get_option('wpengine_backup_settings', array());
            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $last_backup = $wpdb->get_row("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1");
            
            return array(
                'enabled' => $settings['enabled'] ?? false,
                'frequency_hours' => $settings['backup_frequency'] ?? 24,
                'install_id' => $settings['install_id'] ?? null,
                'install_name' => $settings['install_name'] ?? null,
                'next_backup' => $next_backup ? date_i18n('c', $next_backup) : null,
                'last_backup' => $last_backup ? array(
                    'date' => $last_backup->created_at,
                    'type' => $last_backup->backup_type,
                    'status' => $last_backup->status,
                    'message' => $last_backup->message
                ) : null
            );
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    register_rest_route('wpengine-backup/v1', '/create', array(
        'methods' => 'POST',
        'callback' => function($request) {
            $plugin = new WPEngineBackupScheduler();
            $description = $request->get_param('description') ?? 'REST API backup';
            
            $result = $plugin->create_backup('manual', $description);
            
            if ($result['success']) {
                return new WP_REST_Response($result, 200);
            } else {
                return new WP_Error('backup_failed', $result['message'], array('status' => 500));
            }
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => array(
            'description' => array(
                'required' => false,
                'type' => 'string',
                'description' => 'Backup description'
            )
        )
    ));
});

?>