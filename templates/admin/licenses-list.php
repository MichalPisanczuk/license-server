<?php
/**
 * Admin template for licenses list page.
 * 
 * @package MyShop\LicenseServer
 */

if (!defined('ABSPATH')) {
    exit;
}

use MyShop\LicenseServer\Data\Repositories\LicenseRepository;
use MyShop\LicenseServer\Data\Repositories\ActivationRepository;
use function MyShop\LicenseServer\lsr;

// Get repositories
$licenseRepo = lsr(LicenseRepository::class);
$activationRepo = lsr(ActivationRepository::class);

// Handle bulk actions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $message = handleBulkActions($_POST, $licenseRepo);
}

// Get filters
$status_filter = $_GET['status'] ?? '';
$user_filter = $_GET['user_id'] ?? '';
$product_filter = $_GET['product_id'] ?? '';
$search = $_GET['s'] ?? '';

// Pagination
$per_page = 20;
$current_page = max(1, (int)($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// Get licenses with filters
$licenses = getLicensesWithFilters($licenseRepo, $status_filter, $user_filter, $product_filter, $search, $per_page, $offset);
$total_licenses = getTotalLicensesCount($licenseRepo, $status_filter, $user_filter, $product_filter, $search);
$total_pages = ceil($total_licenses / $per_page);

?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Licenses', 'license-server'); ?></h1>
    
    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>

    <!-- Filters -->
    <div class="lsr-filters" style="background: white; padding: 15px; margin: 20px 0; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <form method="GET" action="">
            <input type="hidden" name="page" value="lsr-licenses">
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
                <!-- Search -->
                <div>
                    <label for="search"><?php esc_html_e('Search', 'license-server'); ?></label>
                    <input type="text" id="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('License key or email...', 'license-server'); ?>">
                </div>

                <!-- Status Filter -->
                <div>
                    <label for="status"><?php esc_html_e('Status', 'license-server'); ?></label>
                    <select id="status" name="status">
                        <option value=""><?php esc_html_e('All Statuses', 'license-server'); ?></option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>><?php esc_html_e('Active', 'license-server'); ?></option>
                        <option value="inactive" <?php selected($status_filter, 'inactive'); ?>><?php esc_html_e('Inactive', 'license-server'); ?></option>
                        <option value="expired" <?php selected($status_filter, 'expired'); ?>><?php esc_html_e('Expired', 'license-server'); ?></option>
                        <option value="revoked" <?php selected($status_filter, 'revoked'); ?>><?php esc_html_e('Revoked', 'license-server'); ?></option>
                    </select>
                </div>

                <!-- Product Filter -->
                <div>
                    <label for="product"><?php esc_html_e('Product', 'license-server'); ?></label>
                    <select id="product" name="product_id">
                        <option value=""><?php esc_html_e('All Products', 'license-server'); ?></option>
                        <?php
                        $licensed_products = getLicensedProducts();
                        foreach ($licensed_products as $product) {
                            echo '<option value="' . esc_attr($product->ID) . '" ' . selected($product_filter, $product->ID, false) . '>' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- Submit -->
                <div>
                    <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'license-server'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=lsr-licenses'); ?>" class="button"><?php esc_html_e('Reset', 'license-server'); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Statistics Cards -->
    <div class="lsr-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
        <?php
        $stats = getLicenseStats($licenseRepo);
        renderStatCard(__('Total Licenses', 'license-server'), $stats['total'], 'dashicons-admin-network', '#0073aa');
        renderStatCard(__('Active Licenses', 'license-server'), $stats['active'], 'dashicons-yes-alt', '#00a32a');
        renderStatCard(__('Expired Licenses', 'license-server'), $stats['expired'], 'dashicons-clock', '#dba617');
        renderStatCard(__('Revoked Licenses', 'license-server'), $stats['revoked'], 'dashicons-dismiss', '#d63638');
        ?>
    </div>

    <!-- Bulk Actions Form -->
    <form method="post" id="licenses-form">
        <?php wp_nonce_field('lsr_bulk_actions', 'lsr_nonce'); ?>
        
        <!-- Bulk Actions -->
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'license-server'); ?></label>
                <select name="action" id="bulk-action-selector-top">
                    <option value="-1"><?php esc_html_e('Bulk actions', 'license-server'); ?></option>
                    <option value="activate"><?php esc_html_e('Activate', 'license-server'); ?></option>
                    <option value="deactivate"><?php esc_html_e('Deactivate', 'license-server'); ?></option>
                    <option value="revoke"><?php esc_html_e('Revoke', 'license-server'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'license-server'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'license-server'); ?>">
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(_n('%s item', '%s items', $total_licenses, 'license-server'), number_format_i18n($total_licenses)); ?></span>
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;', 'license-server'),
                        'next_text' => __('&raquo;', 'license-server'),
                        'total' => $total_pages,
                        'current' => $current_page,
                        'add_args' => [
                            'status' => $status_filter,
                            'user_id' => $user_filter,
                            'product_id' => $product_filter,
                            's' => $search
                        ]
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Licenses Table -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e('Select All', 'license-server'); ?></label>
                        <input id="cb-select-all-1" type="checkbox">
                    </td>
                    <th scope="col" class="manage-column column-license-key column-primary">
                        <?php esc_html_e('License Key', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-product">
                        <?php esc_html_e('Product', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-customer">
                        <?php esc_html_e('Customer', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-status">
                        <?php esc_html_e('Status', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-activations">
                        <?php esc_html_e('Activations', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-expires">
                        <?php esc_html_e('Expires', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-created">
                        <?php esc_html_e('Created', 'license-server'); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($licenses)): ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="8">
                            <?php esc_html_e('No licenses found.', 'license-server'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($licenses as $license): ?>
                        <?php
                        $product = wc_get_product($license['product_id']);
                        $user = get_user_by('id', $license['user_id']);
                        $activations = $activationRepo ? $activationRepo->countActivations($license['id']) : 0;
                        $max_activations = $license['max_activations'] ? $license['max_activations'] : '∞';
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="license_ids[]" value="<?php echo esc_attr($license['id']); ?>">
                            </th>
                            <td class="column-license-key column-primary">
                                <strong>
                                    <a href="<?php echo admin_url('admin.php?page=lsr-license-edit&id=' . $license['id']); ?>">
                                        <code><?php echo esc_html(substr($license['license_key'], 0, 16) . '...'); ?></code>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=lsr-license-edit&id=' . $license['id']); ?>"><?php esc_html_e('Edit', 'license-server'); ?></a> |
                                    </span>
                                    <span class="view">
                                        <a href="#" onclick="navigator.clipboard.writeText('<?php echo esc_js($license['license_key']); ?>'); alert('<?php esc_js_e('License key copied to clipboard!', 'license-server'); ?>');">
                                            <?php esc_html_e('Copy Key', 'license-server'); ?>
                                        </a> |
                                    </span>
                                    <span class="delete">
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lsr-licenses&action=delete&license_id=' . $license['id']), 'delete_license_' . $license['id']); ?>" 
                                           onclick="return confirm('<?php esc_js_e('Are you sure you want to delete this license?', 'license-server'); ?>');">
                                            <?php esc_html_e('Delete', 'license-server'); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td class="column-product">
                                <?php if ($product): ?>
                                    <a href="<?php echo get_edit_post_link($license['product_id']); ?>">
                                        <?php echo esc_html($product->get_name()); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="description"><?php printf(__('Product #%d (deleted)', 'license-server'), $license['product_id']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-customer">
                                <?php if ($user): ?>
                                    <a href="<?php echo get_edit_user_link($user->ID); ?>">
                                        <?php echo esc_html($user->display_name); ?>
                                    </a>
                                    <div class="description">
                                        <?php echo esc_html($user->user_email); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="description"><?php printf(__('User #%d (deleted)', 'license-server'), $license['user_id']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-status">
                                <span class="lsr-status lsr-status-<?php echo esc_attr($license['status']); ?>">
                                    <?php echo esc_html(ucfirst($license['status'])); ?>
                                </span>
                                <?php if ($license['subscription_id']): ?>
                                    <div class="description">
                                        <a href="<?php echo admin_url('admin.php?page=wcs_user_subscription&subscription=' . $license['subscription_id']); ?>">
                                            <?php printf(__('Subscription #%d', 'license-server'), $license['subscription_id']); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="column-activations">
                                <span class="lsr-activations">
                                    <?php echo $activations; ?> / <?php echo $max_activations; ?>
                                </span>
                                <?php if ($activations > 0): ?>
                                    <div class="description">
                                        <a href="<?php echo admin_url('admin.php?page=lsr-license-edit&id=' . $license['id'] . '#activations'); ?>">
                                            <?php esc_html_e('View domains', 'license-server'); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="column-expires">
                                <?php if ($license['expires_at']): ?>
                                    <?php
                                    $expires = strtotime($license['expires_at']);
                                    $now = time();
                                    $is_expired = $expires < $now;
                                    $is_expiring_soon = $expires < ($now + (7 * 24 * 60 * 60)); // 7 days
                                    ?>
                                    <span class="<?php echo $is_expired ? 'lsr-expired' : ($is_expiring_soon ? 'lsr-expiring-soon' : ''); ?>">
                                        <?php echo date_i18n(get_option('date_format'), $expires); ?>
                                    </span>
                                    <div class="description">
                                        <?php echo human_time_diff($expires, $now); ?>
                                        <?php echo $is_expired ? __('ago', 'license-server') : __('remaining', 'license-server'); ?>
                                    </div>
                                    <?php if ($license['grace_until']): ?>
                                        <div class="description lsr-grace">
                                            <?php printf(__('Grace until: %s', 'license-server'), date_i18n(get_option('date_format'), strtotime($license['grace_until']))); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="description"><?php esc_html_e('Never', 'license-server'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-created">
                                <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($license['created_at'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Bottom pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;', 'license-server'),
                        'next_text' => __('&raquo;', 'license-server'),
                        'total' => $total_pages,
                        'current' => $current_page,
                        'add_args' => [
                            'status' => $status_filter,
                            'user_id' => $user_filter,
                            'product_id' => $product_filter,
                            's' => $search
                        ]
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </form>
</div>

<style>
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

.lsr-expired { color: #d63638; font-weight: bold; }
.lsr-expiring-soon { color: #dba617; font-weight: bold; }
.lsr-grace { color: #0073aa; font-style: italic; }

.lsr-stats .stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-align: center;
}
.lsr-stats .stat-value {
    font-size: 32px;
    font-weight: bold;
    line-height: 1;
}
.lsr-stats .stat-label {
    font-size: 14px;
    color: #666;
    margin-top: 8px;
}
</style>

<?php

/**
 * Helper functions for the template
 */

function handleBulkActions($post_data, $licenseRepo) {
    if (!wp_verify_nonce($post_data['lsr_nonce'], 'lsr_bulk_actions')) {
        return '<div class="notice notice-error"><p>' . esc_html__('Security check failed.', 'license-server') . '</p></div>';
    }

    $action = $post_data['action'];
    $license_ids = $post_data['license_ids'] ?? [];

    if (empty($license_ids) || $action === '-1') {
        return '<div class="notice notice-warning"><p>' . esc_html__('Please select licenses and action.', 'license-server') . '</p></div>';
    }

    $updated = 0;
    foreach ($license_ids as $license_id) {
        $license_id = (int) $license_id;
        switch ($action) {
            case 'activate':
                $licenseRepo->update($license_id, ['status' => 'active']);
                $updated++;
                break;
            case 'deactivate':
                $licenseRepo->update($license_id, ['status' => 'inactive']);
                $updated++;
                break;
            case 'revoke':
                $licenseRepo->update($license_id, ['status' => 'revoked']);
                $updated++;
                break;
            case 'delete':
                // This would need a delete method in repository
                // $licenseRepo->delete($license_id);
                // $updated++;
                break;
        }
    }

    return '<div class="notice notice-success"><p>' . sprintf(_n('%d license updated.', '%d licenses updated.', $updated, 'license-server'), $updated) . '</p></div>';
}

function getLicensesWithFilters($licenseRepo, $status, $user_id, $product_id, $search, $limit, $offset) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_licenses';
    
    $where_conditions = ['1=1'];
    $params = [];

    if ($status) {
        $where_conditions[] = 'status = %s';
        $params[] = $status;
    }

    if ($user_id) {
        $where_conditions[] = 'user_id = %d';
        $params[] = $user_id;
    }

    if ($product_id) {
        $where_conditions[] = 'product_id = %d';
        $params[] = $product_id;
    }

    if ($search) {
        $where_conditions[] = '(license_key LIKE %s OR user_id IN (SELECT ID FROM ' . $wpdb->users . ' WHERE user_email LIKE %s OR display_name LIKE %s))';
        $search_param = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $params[] = $limit;
    $params[] = $offset;

    return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A) ?: [];
}

function getTotalLicensesCount($licenseRepo, $status, $user_id, $product_id, $search) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_licenses';
    
    $where_conditions = ['1=1'];
    $params = [];

    if ($status) {
        $where_conditions[] = 'status = %s';
        $params[] = $status;
    }

    if ($user_id) {
        $where_conditions[] = 'user_id = %d';
        $params[] = $user_id;
    }

    if ($product_id) {
        $where_conditions[] = 'product_id = %d';
        $params[] = $product_id;
    }

    if ($search) {
        $where_conditions[] = '(license_key LIKE %s OR user_id IN (SELECT ID FROM ' . $wpdb->users . ' WHERE user_email LIKE %s OR display_name LIKE %s))';
        $search_param = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $where_clause = implode(' AND ', $where_conditions);
    $query = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";

    return (int) $wpdb->get_var(empty($params) ? $query : $wpdb->prepare($query, $params));
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

function getLicenseStats($licenseRepo) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_licenses';
    
    $stats = [];
    $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $stats['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
    $stats['expired'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'expired' OR (expires_at IS NOT NULL AND expires_at < NOW())");
    $stats['revoked'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'revoked'");

    return $stats;
}

function renderStatCard($label, $value, $icon, $color) {
    echo '<div class="stat-card">';
    echo '<div class="dashicons ' . esc_attr($icon) . '" style="font-size: 32px; color: ' . esc_attr($color) . ';"></div>';
    echo '<div class="stat-value" style="color: ' . esc_attr($color) . ';">' . esc_html($value) . '</div>';
    echo '<div class="stat-label">' . esc_html($label) . '</div>';
    echo '</div>';
}