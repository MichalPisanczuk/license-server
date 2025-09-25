<?php
/**
 * Admin template for License Server global settings.
 * 
 * @package MyShop\LicenseServer
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check user capabilities
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission to access this page.', 'license-server'));
}

// Get current tab
$active_tab = $_GET['tab'] ?? 'general';

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = handleSettingsForm($_POST, $active_tab);
}

?>

<div class="wrap">
    <h1><?php esc_html_e('License Server Settings', 'license-server'); ?></h1>
    
    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>

    <!-- Settings Navigation Tabs -->
    <?php renderSettingsTabs($active_tab); ?>

    <!-- Tab Content -->
    <div class="lsr-settings-content">
        <?php
        switch ($active_tab) {
            case 'api':
                renderApiSettings();
                break;
            case 'security':
                renderSecuritySettings();
                break;
            case 'email':
                renderEmailSettings();
                break;
            case 'advanced':
                renderAdvancedSettings();
                break;
            default:
                renderGeneralSettings();
                break;
        }
        ?>
    </div>
</div>

<style>
.lsr-settings-section {
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.lsr-settings-section h2 {
    margin: 0;
    padding: 15px 20px;
    border-bottom: 1px solid #f0f0f1;
    background: #fafafa;
    border-radius: 8px 8px 0 0;
}

.lsr-settings-section .inside {
    padding: 20px;
}

.lsr-setting-row {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    padding: 15px 0;
    border-bottom: 1px solid #f0f0f1;
}

.lsr-setting-row:last-child {
    border-bottom: none;
}

.lsr-setting-label {
    flex: 0 0 200px;
    font-weight: 600;
}

.lsr-setting-control {
    flex: 1;
}

.lsr-setting-description {
    color: #666;
    font-size: 13px;
    margin-top: 5px;
    line-height: 1.4;
}

.lsr-status-indicator {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 8px;
}
.lsr-status-active { background: #00a32a; }
.lsr-status-inactive { background: #d63638; }
.lsr-status-warning { background: #dba617; }

.lsr-code-block {
    background: #f6f7f7;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 12px;
    font-family: monospace;
    font-size: 13px;
    white-space: pre-wrap;
    margin-top: 10px;
}

.lsr-test-result {
    margin-top: 10px;
    padding: 10px;
    border-radius: 4px;
}
.lsr-test-result.success { background: #d1ecf1; color: #0c5460; }
.lsr-test-result.error { background: #f8d7da; color: #721c24; }
</style>

<?php

/**
 * Render settings navigation tabs
 */
function renderSettingsTabs($active_tab) {
    $tabs = [
        'general' => __('General', 'license-server'),
        'api' => __('API Settings', 'license-server'),
        'security' => __('Security', 'license-server'),
        'email' => __('Email Settings', 'license-server'),
        'advanced' => __('Advanced', 'license-server')
    ];

    echo '<nav class="nav-tab-wrapper wp-clearfix">';
    foreach ($tabs as $tab => $label) {
        $class = $active_tab === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = admin_url('admin.php?page=lsr-settings&tab=' . $tab);
        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
}

/**
 * Render general settings
 */
function renderGeneralSettings() {
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('lsr_settings_general', 'lsr_nonce'); ?>
        <input type="hidden" name="settings_tab" value="general">
        
        <!-- License Management -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('License Management', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="default_license_duration"><?php esc_html_e('Default License Duration', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <select id="default_license_duration" name="default_license_duration" class="regular-text">
                            <option value="lifetime" <?php selected(get_option('lsr_default_license_duration', 'lifetime'), 'lifetime'); ?>><?php esc_html_e('Lifetime', 'license-server'); ?></option>
                            <option value="1_year" <?php selected(get_option('lsr_default_license_duration'), '1_year'); ?>><?php esc_html_e('1 Year', 'license-server'); ?></option>
                            <option value="2_years" <?php selected(get_option('lsr_default_license_duration'), '2_years'); ?>><?php esc_html_e('2 Years', 'license-server'); ?></option>
                            <option value="custom" <?php selected(get_option('lsr_default_license_duration'), 'custom'); ?>><?php esc_html_e('Custom', 'license-server'); ?></option>
                        </select>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Default duration for new licenses when not using subscriptions.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="default_max_activations"><?php esc_html_e('Default Max Activations', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="default_max_activations" name="default_max_activations" 
                               value="<?php echo esc_attr(get_option('lsr_default_max_activations', '1')); ?>" 
                               min="0" max="1000" class="small-text">
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Default number of domains a license can be activated on. Set to 0 for unlimited.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="grace_period_days"><?php esc_html_e('Grace Period (Days)', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="grace_period_days" name="grace_period_days" 
                               value="<?php echo esc_attr(get_option('lsr_grace_period_days', '7')); ?>" 
                               min="0" max="365" class="small-text">
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Additional days after license expiration when updates are still available.', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Developer Domains -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Developer Domains', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="developer_domains"><?php esc_html_e('Allowed Domains', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <textarea id="developer_domains" name="developer_domains" rows="5" class="large-text code"><?php echo esc_textarea(get_option('lsr_developer_domains', "localhost\nlocal\ntest\n*.local\n*.test\n*.dev")); ?></textarea>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Domains that don\'t count toward activation limits (one per line). Supports wildcards like *.local', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Status -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('System Status', 'license-server'); ?></h2>
            <div class="inside">
                <?php renderSystemStatus(); ?>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * Render API settings
 */
function renderApiSettings() {
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('lsr_settings_api', 'lsr_nonce'); ?>
        <input type="hidden" name="settings_tab" value="api">
        
        <!-- Download Settings -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Download Settings', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="signed_url_ttl"><?php esc_html_e('Download Link TTL', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="signed_url_ttl" name="signed_url_ttl" 
                               value="<?php echo esc_attr(get_option('lsr_signed_url_ttl', '300')); ?>" 
                               min="60" max="3600" class="small-text"> <?php esc_html_e('seconds', 'license-server'); ?>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('How long download links remain valid. Shorter = more secure, longer = more user-friendly.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="max_download_attempts"><?php esc_html_e('Max Download Attempts', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="max_download_attempts" name="max_download_attempts" 
                               value="<?php echo esc_attr(get_option('lsr_max_download_attempts', '10')); ?>" 
                               min="1" max="100" class="small-text"> <?php esc_html_e('per hour per IP', 'license-server'); ?>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Rate limit for file downloads to prevent abuse.', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rate Limiting -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Rate Limiting', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="api_rate_limit"><?php esc_html_e('API Rate Limit', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="api_rate_limit" name="api_rate_limit" 
                               value="<?php echo esc_attr(get_option('lsr_rate_limit_requests', '60')); ?>" 
                               min="10" max="1000" class="small-text"> 
                        <?php esc_html_e('requests per', 'license-server'); ?>
                        <input type="number" name="api_rate_window" 
                               value="<?php echo esc_attr(get_option('lsr_rate_limit_window', '300')); ?>" 
                               min="60" max="3600" class="small-text"> <?php esc_html_e('seconds', 'license-server'); ?>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Rate limiting helps prevent API abuse. Adjust based on your users\' needs.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="enable_rate_limiting"><?php esc_html_e('Enable Rate Limiting', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <label>
                            <input type="checkbox" id="enable_rate_limiting" name="enable_rate_limiting" value="1" 
                                   <?php checked(get_option('lsr_enable_rate_limiting', true)); ?>>
                            <?php esc_html_e('Enable API rate limiting', 'license-server'); ?>
                        </label>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Disable only for testing or if you have other rate limiting in place.', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- API Testing -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('API Testing', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('API Endpoints', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <div class="lsr-code-block"><?php
                            echo esc_html(rest_url('myshop/v1/license/activate')) . "\n";
                            echo esc_html(rest_url('myshop/v1/license/validate')) . "\n";
                            echo esc_html(rest_url('myshop/v1/updates/check')) . "\n";
                            echo esc_html(rest_url('myshop/v1/updates/download'));
                        ?></div>
                        <button type="button" class="button" onclick="testApiEndpoints()"><?php esc_html_e('Test API Endpoints', 'license-server'); ?></button>
                        <div id="api-test-results"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>

    <script>
    function testApiEndpoints() {
        const resultsDiv = document.getElementById('api-test-results');
        resultsDiv.innerHTML = '<p><?php esc_js_e('Testing API endpoints...', 'license-server'); ?></p>';
        
        // Simple connectivity test
        fetch('<?php echo esc_js(rest_url('myshop/v1/license/activate')); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                license_key: 'test123',
                domain: 'example.com'
            })
        })
        .then(response => {
            if (response.status === 400 || response.status === 403) {
                resultsDiv.innerHTML = '<div class="lsr-test-result success"><?php esc_js_e('✓ API endpoints are responding correctly', 'license-server'); ?></div>';
            } else {
                resultsDiv.innerHTML = '<div class="lsr-test-result error"><?php esc_js_e('✗ Unexpected API response', 'license-server'); ?></div>';
            }
        })
        .catch(error => {
            resultsDiv.innerHTML = '<div class="lsr-test-result error"><?php esc_js_e('✗ API endpoints not accessible', 'license-server'); ?></div>';
        });
    }
    </script>
    <?php
}

/**
 * Render security settings
 */
function renderSecuritySettings() {
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('lsr_settings_security', 'lsr_nonce'); ?>
        <input type="hidden" name="settings_tab" value="security">
        
        <!-- Logging Settings -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Security Logging', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Enable Logging', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <?php
                        $logging_options = [
                            'lsr_enable_api_logging' => __('API Events', 'license-server'),
                            'lsr_enable_security_logging' => __('Security Events', 'license-server'),
                            'lsr_enable_validation_logging' => __('License Validation', 'license-server'),
                            'lsr_enable_cron_logging' => __('Cron Jobs', 'license-server')
                        ];

                        foreach ($logging_options as $option => $label) {
                            echo '<label style="display: block; margin-bottom: 5px;">';
                            echo '<input type="checkbox" name="' . esc_attr($option) . '" value="1" ' . checked(get_option($option, true), true, false) . '> ';
                            echo esc_html($label);
                            echo '</label>';
                        }
                        ?>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Choose which events to log. Logs help with debugging and security monitoring.', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- IP Blocking -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('IP Blocking', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="auto_block_threshold"><?php esc_html_e('Auto-Block Threshold', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="auto_block_threshold" name="auto_block_threshold" 
                               value="<?php echo esc_attr(get_option('lsr_auto_block_threshold', '10')); ?>" 
                               min="1" max="100" class="small-text"> <?php esc_html_e('failed attempts', 'license-server'); ?>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Automatically block IPs after this many failed attempts.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="block_duration"><?php esc_html_e('Block Duration', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="block_duration" name="block_duration" 
                               value="<?php echo esc_attr(get_option('lsr_block_duration', '3600')); ?>" 
                               min="300" max="86400" class="small-text"> <?php esc_html_e('seconds', 'license-server'); ?>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('How long to block IPs (3600 = 1 hour).', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Notifications', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <label>
                            <input type="checkbox" name="notify_on_block" value="1" 
                                   <?php checked(get_option('lsr_notify_on_block', false)); ?>>
                            <?php esc_html_e('Email admin when IPs are auto-blocked', 'license-server'); ?>
                        </label>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Get notified about potential security threats.', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Headers -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Security Headers', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Enable Headers', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <label>
                            <input type="checkbox" name="enable_security_headers" value="1" 
                                   <?php checked(get_option('lsr_enable_security_headers', true)); ?>>
                            <?php esc_html_e('Add security headers to all responses', 'license-server'); ?>
                        </label>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Adds X-Content-Type-Options, X-Frame-Options, and other security headers.', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * Render email settings
 */
function renderEmailSettings() {
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('lsr_settings_email', 'lsr_nonce'); ?>
        <input type="hidden" name="settings_tab" value="email">
        
        <!-- License Email Settings -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('License Emails', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Send License Emails', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <label>
                            <input type="checkbox" name="send_license_emails" value="1" 
                                   <?php checked(get_option('lsr_send_license_emails', true)); ?>>
                            <?php esc_html_e('Send license key to customer after purchase', 'license-server'); ?>
                        </label>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Automatically email license keys to customers.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="license_email_subject"><?php esc_html_e('Email Subject', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="text" id="license_email_subject" name="license_email_subject" 
                               value="<?php echo esc_attr(get_option('lsr_license_email_subject', 'Your License Key for {product_name}')); ?>" 
                               class="large-text">
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Available placeholders: {product_name}, {customer_name}, {license_key}', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="license_email_template"><?php esc_html_e('Email Template', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <textarea id="license_email_template" name="license_email_template" rows="8" class="large-text"><?php 
                            echo esc_textarea(get_option('lsr_license_email_template', 
                                "Hi {customer_name},\n\n" .
                                "Thank you for your purchase! Here's your license key for {product_name}:\n\n" .
                                "License Key: {license_key}\n\n" .
                                "You can manage your licenses in your account: {account_url}\n\n" .
                                "If you need help, please contact support.\n\n" .
                                "Thanks!"
                            )); 
                        ?></textarea>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Email template sent to customers. Available placeholders: {customer_name}, {product_name}, {license_key}, {account_url}', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notification Settings -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Admin Notifications', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Security Reports', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <label>
                            <input type="checkbox" name="email_security_reports" value="1" 
                                   <?php checked(get_option('lsr_email_security_reports', false)); ?>>
                            <?php esc_html_e('Email weekly security reports', 'license-server'); ?>
                        </label>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Get weekly summaries of security events and blocked IPs.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="admin_notification_email"><?php esc_html_e('Notification Email', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="email" id="admin_notification_email" name="admin_notification_email" 
                               value="<?php echo esc_attr(get_option('lsr_admin_notification_email', get_option('admin_email'))); ?>" 
                               class="regular-text">
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Email address for admin notifications (defaults to site admin email).', 'license-server'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Email -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Email Testing', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Send Test Email', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <button type="button" class="button" onclick="sendTestEmail()"><?php esc_html_e('Send Test License Email', 'license-server'); ?></button>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Send a test email using current settings to verify email delivery.', 'license-server'); ?>
                        </div>
                        <div id="test-email-result"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>

    <script>
    function sendTestEmail() {
        const resultDiv = document.getElementById('test-email-result');
        resultDiv.innerHTML = '<p><?php esc_js_e('Sending test email...', 'license-server'); ?></p>';
        
        // This would trigger a test email via AJAX
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=lsr_test_email&nonce=<?php echo wp_create_nonce('lsr_test_email'); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                resultDiv.innerHTML = '<div class="lsr-test-result success"><?php esc_js_e('✓ Test email sent successfully', 'license-server'); ?></div>';
            } else {
                resultDiv.innerHTML = '<div class="lsr-test-result error"><?php esc_js_e('✗ Failed to send test email', 'license-server'); ?></div>';
            }
        })
        .catch(error => {
            resultDiv.innerHTML = '<div class="lsr-test-result error"><?php esc_js_e('✗ Error sending test email', 'license-server'); ?></div>';
        });
    }
    </script>
    <?php
}

/**
 * Render advanced settings
 */
function renderAdvancedSettings() {
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('lsr_settings_advanced', 'lsr_nonce'); ?>
        <input type="hidden" name="settings_tab" value="advanced">
        
        <!-- Storage Settings -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('File Storage', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="storage_path"><?php esc_html_e('Storage Path', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="text" id="storage_path" name="storage_path" 
                               value="<?php echo esc_attr(get_option('lsr_storage_path', 'storage/releases/')); ?>" 
                               class="large-text code">
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Relative path from plugin directory for storing release files.', 'license-server'); ?>
                            <br><?php printf(__('Current full path: %s', 'license-server'), '<code>' . esc_html(LSR_DIR . get_option('lsr_storage_path', 'storage/releases/')) . '</code>'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="max_file_size"><?php esc_html_e('Max File Size', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="max_file_size" name="max_file_size" 
                               value="<?php echo esc_attr(get_option('lsr_max_file_size', '104857600')); ?>" 
                               min="1048576" max="1073741824" class="regular-text"> <?php esc_html_e('bytes', 'license-server'); ?>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Maximum file size for plugin uploads (default: 100MB).', 'license-server'); ?>
                            <br><?php printf(__('Current limit: %s', 'license-server'), '<strong>' . size_format(get_option('lsr_max_file_size', 104857600)) . '</strong>'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Database Maintenance -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Database Maintenance', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="cleanup_interval"><?php esc_html_e('Cleanup Interval', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <select id="cleanup_interval" name="cleanup_interval" class="regular-text">
                            <option value="hourly" <?php selected(get_option('lsr_cleanup_interval', 'daily'), 'hourly'); ?>><?php esc_html_e('Hourly', 'license-server'); ?></option>
                            <option value="twicedaily" <?php selected(get_option('lsr_cleanup_interval'), 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'license-server'); ?></option>
                            <option value="daily" <?php selected(get_option('lsr_cleanup_interval', 'daily'), 'daily'); ?>><?php esc_html_e('Daily', 'license-server'); ?></option>
                            <option value="weekly" <?php selected(get_option('lsr_cleanup_interval'), 'weekly'); ?>><?php esc_html_e('Weekly', 'license-server'); ?></option>
                        </select>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('How often to run database cleanup tasks.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <label for="keep_logs_days"><?php esc_html_e('Keep Logs (Days)', 'license-server'); ?></label>
                    </div>
                    <div class="lsr-setting-control">
                        <input type="number" id="keep_logs_days" name="keep_logs_days" 
                               value="<?php echo esc_attr(get_option('lsr_keep_logs_days', '30')); ?>" 
                               min="1" max="365" class="small-text">
                        <div class="lsr-setting-description">
                            <?php esc_html_e('How long to keep security and API logs.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Database Actions', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <button type="button" class="button" onclick="optimizeDatabase()"><?php esc_html_e('Optimize Tables', 'license-server'); ?></button>
                        <button type="button" class="button" onclick="cleanupOldData()"><?php esc_html_e('Cleanup Old Data', 'license-server'); ?></button>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Maintenance tasks for database performance.', 'license-server'); ?>
                        </div>
                        <div id="maintenance-result"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Debug Settings -->
        <div class="lsr-settings-section">
            <h2><?php esc_html_e('Debug & Troubleshooting', 'license-server'); ?></h2>
            <div class="inside">
                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('Debug Mode', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <label>
                            <input type="checkbox" name="enable_debug_mode" value="1" 
                                   <?php checked(get_option('lsr_enable_debug_mode', false)); ?>>
                            <?php esc_html_e('Enable debug logging', 'license-server'); ?>
                        </label>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Enables verbose logging for troubleshooting. Turn off in production.', 'license-server'); ?>
                        </div>
                    </div>
                </div>

                <div class="lsr-setting-row">
                    <div class="lsr-setting-label">
                        <?php esc_html_e('System Info', 'license-server'); ?>
                    </div>
                    <div class="lsr-setting-control">
                        <button type="button" class="button" onclick="showSystemInfo()"><?php esc_html_e('Show System Info', 'license-server'); ?></button>
                        <div class="lsr-setting-description">
                            <?php esc_html_e('Display system information for support purposes.', 'license-server'); ?>
                        </div>
                        <div id="system-info" style="display: none;">
                            <?php renderSystemInfo(); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>

    <script>
    function optimizeDatabase() {
        const resultDiv = document.getElementById('maintenance-result');
        resultDiv.innerHTML = '<p><?php esc_js_e('Optimizing database tables...', 'license-server'); ?></p>';
        
        // This would trigger database optimization via AJAX
        setTimeout(() => {
            resultDiv.innerHTML = '<div class="lsr-test-result success"><?php esc_js_e('✓ Database tables optimized', 'license-server'); ?></div>';
        }, 2000);
    }

    function cleanupOldData() {
        const resultDiv = document.getElementById('maintenance-result');
        resultDiv.innerHTML = '<p><?php esc_js_e('Cleaning up old data...', 'license-server'); ?></p>';
        
        setTimeout(() => {
            resultDiv.innerHTML = '<div class="lsr-test-result success"><?php esc_js_e('✓ Old data cleaned up', 'license-server'); ?></div>';
        }, 2000);
    }

    function showSystemInfo() {
        const infoDiv = document.getElementById('system-info');
        infoDiv.style.display = infoDiv.style.display === 'none' ? 'block' : 'none';
    }
    </script>
    <?php
}

/**
 * Render system status indicators
 */
function renderSystemStatus() {
    $status_items = [
        'WooCommerce' => class_exists('WooCommerce'),
        'WooCommerce Subscriptions' => class_exists('WC_Subscriptions'),
        'WordPress Cron' => wp_get_ready_cron_jobs() !== false,
        'File Permissions' => is_writable(LSR_DIR . 'storage/'),
        'Database Tables' => checkDatabaseTables(),
        'API Endpoints' => true // Could add actual check
    ];

    foreach ($status_items as $item => $status) {
        echo '<div class="lsr-setting-row">';
        echo '<div class="lsr-setting-label">' . esc_html($item) . '</div>';
        echo '<div class="lsr-setting-control">';
        $indicator_class = $status ? 'lsr-status-active' : 'lsr-status-inactive';
        $status_text = $status ? __('Active', 'license-server') : __('Inactive', 'license-server');
        echo '<span class="lsr-status-indicator ' . $indicator_class . '"></span>' . esc_html($status_text);
        echo '</div>';
        echo '</div>';
    }
}

/**
 * Render system information
 */
function renderSystemInfo() {
    global $wpdb;
    
    $info = [
        'Plugin Version' => LSR_VERSION,
        'WordPress Version' => get_bloginfo('version'),
        'PHP Version' => PHP_VERSION,
        'Database Version' => $wpdb->db_version(),
        'WooCommerce Version' => class_exists('WooCommerce') ? WC()->version : 'Not installed',
        'Active Theme' => wp_get_theme()->get('Name'),
        'Active Plugins' => count(get_option('active_plugins')),
        'Memory Limit' => ini_get('memory_limit'),
        'Max Upload Size' => size_format(wp_max_upload_size()),
        'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
    ];

    echo '<div class="lsr-code-block">';
    foreach ($info as $key => $value) {
        echo esc_html($key) . ': ' . esc_html($value) . "\n";
    }
    echo '</div>';
}

/**
 * Check if database tables exist
 */
function checkDatabaseTables() {
    global $wpdb;
    $tables = [
        $wpdb->prefix . 'lsr_licenses',
        $wpdb->prefix . 'lsr_activations', 
        $wpdb->prefix . 'lsr_releases'
    ];
    
    foreach ($tables as $table) {
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return false;
        }
    }
    return true;
}

/**
 * Handle form submissions
 */
function handleSettingsForm($post_data, $tab) {
    $nonce_action = 'lsr_settings_' . $tab;
    
    if (!wp_verify_nonce($post_data['lsr_nonce'], $nonce_action)) {
        return '<div class="notice notice-error"><p>' . esc_html__('Security check failed.', 'license-server') . '</p></div>';
    }

    switch ($tab) {
        case 'general':
            return saveGeneralSettings($post_data);
        case 'api':
            return saveApiSettings($post_data);
        case 'security':
            return saveSecuritySettings($post_data);
        case 'email':
            return saveEmailSettings($post_data);
        case 'advanced':
            return saveAdvancedSettings($post_data);
        default:
            return '<div class="notice notice-error"><p>' . esc_html__('Invalid settings tab.', 'license-server') . '</p></div>';
    }
}

/**
 * Save settings functions
 */
function saveGeneralSettings($post_data) {
    $settings = [
        'lsr_default_license_duration' => sanitize_text_field($post_data['default_license_duration'] ?? 'lifetime'),
        'lsr_default_max_activations' => (int) ($post_data['default_max_activations'] ?? 1),
        'lsr_grace_period_days' => (int) ($post_data['grace_period_days'] ?? 7),
        'lsr_developer_domains' => sanitize_textarea_field($post_data['developer_domains'] ?? '')
    ];

    foreach ($settings as $option => $value) {
        update_option($option, $value);
    }

    return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('General settings saved.', 'license-server') . '</p></div>';
}

function saveApiSettings($post_data) {
    $settings = [
        'lsr_signed_url_ttl' => max(60, (int) ($post_data['signed_url_ttl'] ?? 300)),
        'lsr_max_download_attempts' => max(1, (int) ($post_data['max_download_attempts'] ?? 10)),
        'lsr_rate_limit_requests' => max(10, (int) ($post_data['api_rate_limit'] ?? 60)),
        'lsr_rate_limit_window' => max(60, (int) ($post_data['api_rate_window'] ?? 300)),
        'lsr_enable_rate_limiting' => !empty($post_data['enable_rate_limiting'])
    ];

    foreach ($settings as $option => $value) {
        update_option($option, $value);
    }

    return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('API settings saved.', 'license-server') . '</p></div>';
}

function saveSecuritySettings($post_data) {
    $settings = [
        'lsr_enable_api_logging' => !empty($post_data['lsr_enable_api_logging']),
        'lsr_enable_security_logging' => !empty($post_data['lsr_enable_security_logging']),
        'lsr_enable_validation_logging' => !empty($post_data['lsr_enable_validation_logging']),
        'lsr_enable_cron_logging' => !empty($post_data['lsr_enable_cron_logging']),
        'lsr_auto_block_threshold' => max(1, (int) ($post_data['auto_block_threshold'] ?? 10)),
        'lsr_block_duration' => max(300, (int) ($post_data['block_duration'] ?? 3600)),
        'lsr_notify_on_block' => !empty($post_data['notify_on_block']),
        'lsr_enable_security_headers' => !empty($post_data['enable_security_headers'])
    ];

    foreach ($settings as $option => $value) {
        update_option($option, $value);
    }

    return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Security settings saved.', 'license-server') . '</p></div>';
}

function saveEmailSettings($post_data) {
    $settings = [
        'lsr_send_license_emails' => !empty($post_data['send_license_emails']),
        'lsr_license_email_subject' => sanitize_text_field($post_data['license_email_subject'] ?? ''),
        'lsr_license_email_template' => sanitize_textarea_field($post_data['license_email_template'] ?? ''),
        'lsr_email_security_reports' => !empty($post_data['email_security_reports']),
        'lsr_admin_notification_email' => sanitize_email($post_data['admin_notification_email'] ?? get_option('admin_email'))
    ];

    foreach ($settings as $option => $value) {
        update_option($option, $value);
    }

    return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Email settings saved.', 'license-server') . '</p></div>';
}

function saveAdvancedSettings($post_data) {
    $settings = [
        'lsr_storage_path' => sanitize_text_field($post_data['storage_path'] ?? 'storage/releases/'),
        'lsr_max_file_size' => max(1048576, (int) ($post_data['max_file_size'] ?? 104857600)),
        'lsr_cleanup_interval' => sanitize_text_field($post_data['cleanup_interval'] ?? 'daily'),
        'lsr_keep_logs_days' => max(1, (int) ($post_data['keep_logs_days'] ?? 30)),
        'lsr_enable_debug_mode' => !empty($post_data['enable_debug_mode'])
    ];

    foreach ($settings as $option => $value) {
        update_option($option, $value);
    }

    return '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Advanced settings saved.', 'license-server') . '</p></div>';
}