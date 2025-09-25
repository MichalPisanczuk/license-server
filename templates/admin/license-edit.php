<?php
/**
 * Admin template for license edit/create page.
 * 
 * @package MyShop\LicenseServer
 */

if (!defined('ABSPATH')) {
    exit;
}

use MyShop\LicenseServer\Data\Repositories\EnhancedLicenseRepository;
use MyShop\LicenseServer\Data\Repositories\EnhancedActivationRepository;
use function MyShop\LicenseServer\lsr;

// Get repositories
$licenseRepo = lsr(EnhancedLicenseRepository::class);
$activationRepo = lsr(EnhancedActivationRepository::class);

// Get license ID from URL
$license_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$license = null;
$activations = [];
$is_new_license = true;

if ($license_id > 0) {
    $license = getLicenseById($licenseRepo, $license_id);
    if ($license) {
        $is_new_license = false;
        $activations = getLicenseActivations($license_id);
    } else {
        wp_die(__('License not found.', 'license-server'));
    }
}

// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = handleLicenseForm($_POST, $licenseRepo, $license_id);
    
    // Refresh license data after update
    if ($license_id > 0 && strpos($message, 'error') === false) {
        $license = getLicenseById($licenseRepo, $license_id);
    }
}

// Get related data
$product = $license ? wc_get_product($license['product_id']) : null;
$user = $license ? get_user_by('id', $license['user_id']) : null;
$subscription = ($license && $license['subscription_id']) ? wcs_get_subscription($license['subscription_id']) : null;

?>

<div class="wrap">
    <h1>
        <?php if ($is_new_license): ?>
            <?php esc_html_e('Create License', 'license-server'); ?>
        <?php else: ?>
            <?php esc_html_e('Edit License', 'license-server'); ?>
            <span class="license-key-display">
                <code><?php echo esc_html($license['license_key']); ?></code>
                <button type="button" class="button button-small" onclick="copyLicenseKey('<?php echo esc_js($license['license_key']); ?>')">
                    <?php esc_html_e('Copy', 'license-server'); ?>
                </button>
            </span>
        <?php endif; ?>
    </h1>

    <a href="<?php echo admin_url('admin.php?page=lsr-licenses'); ?>" class="page-title-action">
        <?php esc_html_e('← Back to Licenses', 'license-server'); ?>
    </a>

    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>

    <div class="lsr-license-edit" style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
        
        <!-- Main License Form -->
        <div class="lsr-main-form">
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e('License Details', 'license-server'); ?></h2>
                </div>
                <div class="inside">
                    <form method="post" id="license-form">
                        <?php wp_nonce_field('lsr_edit_license', 'lsr_nonce'); ?>
                        <input type="hidden" name="action" value="<?php echo $is_new_license ? 'create' : 'update'; ?>">
                        
                        <table class="form-table">
                            <!-- License Key (read-only for existing) -->
                            <tr>
                                <th scope="row">
                                    <label for="license_key"><?php esc_html_e('License Key', 'license-server'); ?></label>
                                </th>
                                <td>
                                    <?php if ($is_new_license): ?>
                                        <input type="text" id="license_key" name="license_key" class="regular-text code" 
                                               value="<?php echo esc_attr($_POST['license_key'] ?? ''); ?>" 
                                               placeholder="<?php esc_attr_e('Leave empty to auto-generate', 'license-server'); ?>">
                                        <p class="description"><?php esc_html_e('32-character hexadecimal key. Leave empty to generate automatically.', 'license-server'); ?></p>
                                    <?php else: ?>
                                        <code class="license-key-display"><?php echo esc_html($license['license_key']); ?></code>
                                        <p class="description"><?php esc_html_e('License key cannot be changed after creation.', 'license-server'); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- User Selection -->
                            <tr>
                                <th scope="row">
                                    <label for="user_id"><?php esc_html_e('Customer', 'license-server'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <select id="user_id" name="user_id" class="regular-text" required>
                                        <option value=""><?php esc_html_e('Select customer...', 'license-server'); ?></option>
                                        <?php
                                        $customers = getCustomers();
                                        foreach ($customers as $customer) {
                                            $selected = ($license && $license['user_id'] == $customer->ID) ? 'selected' : '';
                                            echo '<option value="' . esc_attr($customer->ID) . '" ' . $selected . '>';
                                            echo esc_html($customer->display_name) . ' (' . esc_html($customer->user_email) . ')';
                                            echo '</option>';
                                        }
                                        ?>
                                    </select>
                                    <?php if ($user): ?>
                                        <p class="description">
                                            <a href="<?php echo get_edit_user_link($user->ID); ?>" target="_blank">
                                                <?php esc_html_e('View customer profile', 'license-server'); ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Product Selection -->
                            <tr>
                                <th scope="row">
                                    <label for="product_id"><?php esc_html_e('Product', 'license-server'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <select id="product_id" name="product_id" class="regular-text" required>
                                        <option value=""><?php esc_html_e('Select product...', 'license-server'); ?></option>
                                        <?php
                                        $licensed_products = getLicensedProducts();
                                        foreach ($licensed_products as $licensed_product) {
                                            $selected = ($license && $license['product_id'] == $licensed_product->ID) ? 'selected' : '';
                                            echo '<option value="' . esc_attr($licensed_product->ID) . '" ' . $selected . '>';
                                            echo esc_html($licensed_product->post_title);
                                            echo '</option>';
                                        }
                                        ?>
                                    </select>
                                    <?php if ($product): ?>
                                        <p class="description">
                                            <a href="<?php echo get_edit_post_link($product->get_id()); ?>" target="_blank">
                                                <?php esc_html_e('View product', 'license-server'); ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Status -->
                            <tr>
                                <th scope="row">
                                    <label for="status"><?php esc_html_e('Status', 'license-server'); ?></label>
                                </th>
                                <td>
                                    <select id="status" name="status" class="regular-text">
                                        <option value="active" <?php selected($license['status'] ?? 'active', 'active'); ?>><?php esc_html_e('Active', 'license-server'); ?></option>
                                        <option value="inactive" <?php selected($license['status'] ?? '', 'inactive'); ?>><?php esc_html_e('Inactive', 'license-server'); ?></option>
                                        <option value="expired" <?php selected($license['status'] ?? '', 'expired'); ?>><?php esc_html_e('Expired', 'license-server'); ?></option>
                                        <option value="revoked" <?php selected($license['status'] ?? '', 'revoked'); ?>><?php esc_html_e('Revoked', 'license-server'); ?></option>
                                    </select>
                                </td>
                            </tr>

                            <!-- Expiration Date -->
                            <tr>
                                <th scope="row">
                                    <label for="expires_at"><?php esc_html_e('Expires At', 'license-server'); ?></label>
                                </th>
                                <td>
                                    <input type="datetime-local" id="expires_at" name="expires_at" class="regular-text"
                                           value="<?php echo $license && $license['expires_at'] ? esc_attr(date('Y-m-d\TH:i', strtotime($license['expires_at']))) : ''; ?>">
                                    <p class="description"><?php esc_html_e('Leave empty for lifetime license.', 'license-server'); ?></p>
                                </td>
                            </tr>

                            <!-- Grace Period -->
                            <tr>
                                <th scope="row">
                                    <label for="grace_until"><?php esc_html_e('Grace Period Until', 'license-server'); ?></label>
                                </th>
                                <td>
                                    <input type="datetime-local" id="grace_until" name="grace_until" class="regular-text"
                                           value="<?php echo $license && $license['grace_until'] ? esc_attr(date('Y-m-d\TH:i', strtotime($license['grace_until']))) : ''; ?>">
                                    <p class="description"><?php esc_html_e('Additional time after expiration when license still works.', 'license-server'); ?></p>
                                </td>
                            </tr>

                            <!-- Max Activations -->
                            <tr>
                                <th scope="row">
                                    <label for="max_activations"><?php esc_html_e('Max Activations', 'license-server'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="max_activations" name="max_activations" class="small-text" min="0" max="1000"
                                           value="<?php echo esc_attr($license['max_activations'] ?? '1'); ?>">
                                    <p class="description"><?php esc_html_e('Maximum number of domains this license can be activated on. Set to 0 for unlimited.', 'license-server'); ?></p>
                                </td>
                            </tr>

                            <!-- Notes -->
                            <tr>
                                <th scope="row">
                                    <label for="notes"><?php esc_html_e('Notes', 'license-server'); ?></label>
                                </th>
                                <td>
                                    <textarea id="notes" name="notes" class="large-text" rows="3"><?php echo esc_textarea($license['notes'] ?? ''); ?></textarea>
                                    <p class="description"><?php esc_html_e('Internal notes (not visible to customer).', 'license-server'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="submit" class="button-primary" 
                                   value="<?php echo $is_new_license ? esc_attr__('Create License', 'license-server') : esc_attr__('Update License', 'license-server'); ?>">
                            <?php if (!$is_new_license): ?>
                                <a href="<?php echo admin_url('admin.php?page=lsr-licenses'); ?>" class="button">
                                    <?php esc_html_e('Cancel', 'license-server'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
            </div>

            <?php if (!$is_new_license && !empty($activations)): ?>
                <!-- License Activations -->
                <div class="postbox" id="activations">
                    <div class="postbox-header">
                        <h2><?php esc_html_e('License Activations', 'license-server'); ?></h2>
                    </div>
                    <div class="inside">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Domain', 'license-server'); ?></th>
                                    <th><?php esc_html_e('IP Hash', 'license-server'); ?></th>
                                    <th><?php esc_html_e('Activated', 'license-server'); ?></th>
                                    <th><?php esc_html_e('Last Seen', 'license-server'); ?></th>
                                    <th><?php esc_html_e('Validations', 'license-server'); ?></th>
                                    <th><?php esc_html_e('Actions', 'license-server'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activations as $activation): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($activation['domain']); ?></strong>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html(substr($activation['ip_hash'] ?? '', 0, 16) . '...'); ?></code>
                                        </td>
                                        <td>
                                            <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($activation['activated_at'])); ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $last_seen = strtotime($activation['last_seen_at']);
                                            echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_seen);
                                            echo '<div class="description">' . human_time_diff($last_seen) . ' ago</div>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo (int) ($activation['validation_count'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lsr-license-edit&id=' . $license_id . '&action=deactivate_domain&activation_id=' . $activation['id']), 'deactivate_domain'); ?>" 
                                               class="button button-small button-link-delete"
                                               onclick="return confirm('<?php esc_js_e('Are you sure you want to deactivate this domain?', 'license-server'); ?>');">
                                                <?php esc_html_e('Deactivate', 'license-server'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="lsr-sidebar">
            <?php if (!$is_new_license): ?>
                <!-- License Summary -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2><?php esc_html_e('License Summary', 'license-server'); ?></h2>
                    </div>
                    <div class="inside">
                        <div class="lsr-summary-stats">
                            <div class="stat">
                                <span class="label"><?php esc_html_e('Status:', 'license-server'); ?></span>
                                <span class="value lsr-status lsr-status-<?php echo esc_attr($license['status']); ?>">
                                    <?php echo esc_html(ucfirst($license['status'])); ?>
                                </span>
                            </div>
                            
                            <div class="stat">
                                <span class="label"><?php esc_html_e('Activations:', 'license-server'); ?></span>
                                <span class="value">
                                    <?php echo count($activations); ?> / 
                                    <?php echo $license['max_activations'] ? $license['max_activations'] : '∞'; ?>
                                </span>
                            </div>
                            
                            <?php if ($license['expires_at']): ?>
                                <div class="stat">
                                    <span class="label"><?php esc_html_e('Expires:', 'license-server'); ?></span>
                                    <span class="value">
                                        <?php 
                                        $expires = strtotime($license['expires_at']);
                                        echo date_i18n(get_option('date_format'), $expires);
                                        $is_expired = $expires < time();
                                        echo '<div class="description ' . ($is_expired ? 'lsr-expired' : '') . '">';
                                        echo human_time_diff($expires, time()) . ($is_expired ? ' ago' : ' remaining');
                                        echo '</div>';
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="stat">
                                <span class="label"><?php esc_html_e('Created:', 'license-server'); ?></span>
                                <span class="value">
                                    <?php echo date_i18n(get_option('date_format'), strtotime($license['created_at'])); ?>
                                    <div class="description"><?php echo human_time_diff(strtotime($license['created_at'])); ?> ago</div>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($subscription): ?>
                    <!-- Subscription Info -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e('Subscription', 'license-server'); ?></h2>
                        </div>
                        <div class="inside">
                            <p>
                                <strong><?php esc_html_e('Status:', 'license-server'); ?></strong> 
                                <?php echo esc_html(ucfirst($subscription->get_status())); ?>
                            </p>
                            <p>
                                <strong><?php esc_html_e('Next Payment:', 'license-server'); ?></strong>
                                <?php 
                                $next_payment = $subscription->get_time('next_payment');
                                echo $next_payment ? date_i18n(get_option('date_format'), $next_payment) : esc_html__('N/A', 'license-server');
                                ?>
                            </p>
                            <p>
                                <a href="<?php echo admin_url('admin.php?page=wcs_user_subscription&subscription=' . $subscription->get_id()); ?>" class="button">
                                    <?php esc_html_e('View Subscription', 'license-server'); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2><?php esc_html_e('Quick Actions', 'license-server'); ?></h2>
                    </div>
                    <div class="inside">
                        <p>
                            <a href="#" class="button" onclick="copyLicenseKey('<?php echo esc_js($license['license_key']); ?>')">
                                <?php esc_html_e('Copy License Key', 'license-server'); ?>
                            </a>
                        </p>
                        <p>
                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $license['user_id']); ?>" class="button">
                                <?php esc_html_e('View Customer', 'license-server'); ?>
                            </a>
                        </p>
                        <?php if ($license['subscription_id']): ?>
                            <p>
                                <a href="<?php echo admin_url('admin.php?page=wcs_user_subscription&subscription=' . $license['subscription_id']); ?>" class="button">
                                    <?php esc_html_e('View Subscription', 'license-server'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                        <p>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lsr-licenses&action=delete&license_id=' . $license['id']), 'delete_license_' . $license['id']); ?>" 
                               class="button button-link-delete"
                               onclick="return confirm('<?php esc_js_e('Are you sure you want to delete this license?', 'license-server'); ?>');">
                                <?php esc_html_e('Delete License', 'license-server'); ?>
                            </a>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Help & Documentation -->
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e('Help', 'license-server'); ?></h2>
                </div>
                <div class="inside">
                    <p><?php esc_html_e('Need help with license management?', 'license-server'); ?></p>
                    <ul>
                        <li><a href="#" target="_blank"><?php esc_html_e('License Documentation', 'license-server'); ?></a></li>
                        <li><a href="#" target="_blank"><?php esc_html_e('API Reference', 'license-server'); ?></a></li>
                        <li><a href="#" target="_blank"><?php esc_html_e('Support Forum', 'license-server'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyLicenseKey(key) {
    navigator.clipboard.writeText(key).then(function() {
        alert('<?php esc_js_e('License key copied to clipboard!', 'license-server'); ?>');
    });
}

// Auto-generate license key for new licenses
document.addEventListener('DOMContentLoaded', function() {
    const licenseKeyField = document.getElementById('license_key');
    if (licenseKeyField && !licenseKeyField.value) {
        const generateBtn = document.createElement('button');
        generateBtn.type = 'button';
        generateBtn.className = 'button';
        generateBtn.textContent = '<?php esc_js_e('Generate Key', 'license-server'); ?>';
        generateBtn.onclick = function() {
            const key = generateRandomKey();
            licenseKeyField.value = key;
        };
        licenseKeyField.parentNode.appendChild(generateBtn);
    }
});

function generateRandomKey() {
    const chars = '0123456789abcdef';
    let key = '';
    for (let i = 0; i < 32; i++) {
        key += chars[Math.floor(Math.random() * chars.length)];
    }
    return key;
}
</script>

<style>
.license-key-display {
    background: #f0f0f1;
    padding: 8px 12px;
    border-radius: 4px;
    font-family: monospace;
    display: inline-block;
    margin-left: 10px;
}

.lsr-summary-stats .stat {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f0f1;
}

.lsr-summary-stats .stat:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.lsr-summary-stats .label {
    font-weight: 600;
}

.lsr-status {
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.lsr-status-active { background: #d1ecf1; color: #0c5460; }
.lsr-status-inactive { background: #f8d7da; color: #721c24; }
.lsr-status-expired { background: #fff3cd; color: #856404; }
.lsr-status-revoked { background: #721c24; color: white; }

.lsr-expired {
    color: #d63638;
    font-weight: bold;
}

.required {
    color: #d63638;
}
</style>

<?php

/**
 * Helper functions for license edit template
 */

function getLicenseById($licenseRepo, $license_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_licenses';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $license_id), ARRAY_A);
}

function getLicenseActivations($license_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_activations';
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE license_id = %d ORDER BY activated_at DESC", $license_id), ARRAY_A);
}

function getCustomers() {
    return get_users([
        'role__in' => ['customer', 'subscriber', 'contributor', 'author', 'editor', 'administrator'],
        'orderby' => 'display_name'
    ]);
}

function getLicensedProducts() {
    return get_posts([
        'post_type' => 'product',
        'post_status' => 'publish',
        'meta_key' => '_lsr_is_licensed',
        'meta_value' => 'yes',
        'numberposts' => -1
    ]);
}

function handleLicenseForm($post_data, $licenseRepo, $license_id) {
    if (!wp_verify_nonce($post_data['lsr_nonce'], 'lsr_edit_license')) {
        return '<div class="notice notice-error"><p>' . esc_html__('Security check failed.', 'license-server') . '</p></div>';
    }

    $action = $post_data['action'];
    $user_id = (int) $post_data['user_id'];
    $product_id = (int) $post_data['product_id'];
    $status = sanitize_text_field($post_data['status']);
    $expires_at = !empty($post_data['expires_at']) ? date('Y-m-d H:i:s', strtotime($post_data['expires_at'])) : null;
    $grace_until = !empty($post_data['grace_until']) ? date('Y-m-d H:i:s', strtotime($post_data['grace_until'])) : null;
    $max_activations = !empty($post_data['max_activations']) ? (int) $post_data['max_activations'] : null;
    $notes = sanitize_textarea_field($post_data['notes'] ?? '');

    if (!$user_id || !$product_id) {
        return '<div class="notice notice-error"><p>' . esc_html__('Please select a customer and product.', 'license-server') . '</p></div>';
    }

    $license_data = [
        'user_id' => $user_id,
        'product_id' => $product_id,
        'status' => $status,
        'expires_at' => $expires_at,
        'grace_until' => $grace_until,
        'max_activations' => $max_activations,
        'notes' => $notes
    ];

    if ($action === 'create') {
        // Generate license key if not provided
        $license_key = !empty($post_data['license_key']) ? sanitize_text_field($post_data['license_key']) : bin2hex(random_bytes(16));
        
        // Validate license key format
        if (!preg_match('/^[a-f0-9]{32}$/i', $license_key)) {
            return '<div class="notice notice-error"><p>' . esc_html__('Invalid license key format. Must be 32 hexadecimal characters.', 'license-server') . '</p></div>';
        }

        $license_data['license_key'] = strtolower($license_key);
        
        $new_license_id = $licenseRepo->create($license_data);
        if ($new_license_id) {
            $redirect_url = admin_url('admin.php?page=lsr-license-edit&id=' . $new_license_id . '&created=1');
            wp_redirect($redirect_url);
            exit;
        } else {
            return '<div class="notice notice-error"><p>' . esc_html__('Failed to create license. Key may already exist.', 'license-server') . '</p></div>';
        }
    } else {
        $updated = $licenseRepo->update($license_id, $license_data);
        if ($updated) {
            return '<div class="notice notice-success"><p>' . esc_html__('License updated successfully.', 'license-server') . '</p></div>';
        } else {
            return '<div class="notice notice-error"><p>' . esc_html__('Failed to update license.', 'license-server') . '</p></div>';
        }
    }
}

// Handle license creation success message
if (isset($_GET['created']) && $_GET['created'] == '1') {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('License created successfully!', 'license-server') . '</p></div>';
}

// Handle domain deactivation
if (isset($_GET['action']) && $_GET['action'] === 'deactivate_domain' && isset($_GET['activation_id'])) {
    if (wp_verify_nonce($_GET['_wpnonce'], 'deactivate_domain')) {
        global $wpdb;
        $activation_id = (int) $_GET['activation_id'];
        $table = $wpdb->prefix . 'lsr_activations';
        $deleted = $wpdb->delete($table, ['id' => $activation_id], ['%d']);
        
        if ($deleted) {
            $redirect_url = admin_url('admin.php?page=lsr-license-edit&id=' . $license_id . '&deactivated=1#activations');
        } else {
            $redirect_url = admin_url('admin.php?page=lsr-license-edit&id=' . $license_id . '&error=deactivate_failed#activations');
        }
        wp_redirect($redirect_url);
        exit;
    }
}

// Handle deactivation messages
if (isset($_GET['deactivated']) && $_GET['deactivated'] == '1') {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Domain deactivated successfully.', 'license-server') . '</p></div>';
}

if (isset($_GET['error']) && $_GET['error'] === 'deactivate_failed') {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to deactivate domain.', 'license-server') . '</p></div>';
}