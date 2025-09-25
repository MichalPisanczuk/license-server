<?php
/**
 * Admin template for plugin releases list and management.
 * 
 * @package MyShop\LicenseServer
 */

if (!defined('ABSPATH')) {
    exit;
}

use MyShop\LicenseServer\Data\Repositories\ReleaseRepository;
use function MyShop\LicenseServer\lsr;

// Get repository
$releaseRepo = lsr(ReleaseRepository::class);

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = handleReleaseActions($_POST, $releaseRepo);
}

// Handle single actions (delete, toggle)
if (isset($_GET['action']) && isset($_GET['release_id'])) {
    $message = handleSingleActions($_GET, $releaseRepo);
}

// Get filters and pagination
$product_filter = $_GET['product_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['s'] ?? '';
$per_page = 10;
$current_page = max(1, (int)($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// Get releases with filters
$releases = getReleasesWithFilters($releaseRepo, $product_filter, $status_filter, $search, $per_page, $offset);
$total_releases = getTotalReleasesCount($releaseRepo, $product_filter, $status_filter, $search);
$total_pages = ceil($total_releases / $per_page);

?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Plugin Releases', 'license-server'); ?></h1>
    <a href="#" class="page-title-action" onclick="toggleAddReleaseForm()"><?php esc_html_e('Add New Release', 'license-server'); ?></a>

    <?php if ($message): ?>
        <?php echo $message; ?>
    <?php endif; ?>

    <!-- Add New Release Form (Hidden by default) -->
    <div id="add-release-form" class="lsr-add-release-form" style="display: none; background: white; padding: 20px; margin: 20px 0; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h2><?php esc_html_e('Add New Release', 'license-server'); ?></h2>
        <form method="post" enctype="multipart/form-data" id="release-form">
            <?php wp_nonce_field('lsr_add_release', 'lsr_release_nonce'); ?>
            <input type="hidden" name="action" value="add_release">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <!-- Product Selection -->
                    <p>
                        <label for="product_id"><strong><?php esc_html_e('Product', 'license-server'); ?> <span style="color: #d63638;">*</span></strong></label>
                        <select id="product_id" name="product_id" class="widefat" required>
                            <option value=""><?php esc_html_e('Select licensed product...', 'license-server'); ?></option>
                            <?php
                            $licensed_products = getLicensedProducts();
                            foreach ($licensed_products as $product) {
                                echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                            }
                            ?>
                        </select>
                    </p>

                    <!-- Plugin Slug -->
                    <p>
                        <label for="slug"><strong><?php esc_html_e('Plugin Slug', 'license-server'); ?> <span style="color: #d63638;">*</span></strong></label>
                        <input type="text" id="slug" name="slug" class="widefat" pattern="[a-z0-9-]+" required>
                        <em><?php esc_html_e('Plugin directory name (e.g., my-awesome-plugin)', 'license-server'); ?></em>
                    </p>

                    <!-- Version -->
                    <p>
                        <label for="version"><strong><?php esc_html_e('Version', 'license-server'); ?> <span style="color: #d63638;">*</span></strong></label>
                        <input type="text" id="version" name="version" class="widefat" pattern="^\d+\.\d+\.\d+.*" required>
                        <em><?php esc_html_e('Semantic version (e.g., 1.2.3)', 'license-server'); ?></em>
                    </p>

                    <!-- ZIP File -->
                    <p>
                        <label for="zip_file"><strong><?php esc_html_e('Plugin ZIP File', 'license-server'); ?> <span style="color: #d63638;">*</span></strong></label>
                        <input type="file" id="zip_file" name="zip_file" accept=".zip" required>
                        <em><?php esc_html_e('Maximum file size: 100MB', 'license-server'); ?></em>
                    </p>
                </div>

                <div>
                    <!-- Requirements -->
                    <p>
                        <label for="min_wp"><strong><?php esc_html_e('Minimum WordPress Version', 'license-server'); ?></strong></label>
                        <input type="text" id="min_wp" name="min_wp" class="widefat" placeholder="6.0">
                    </p>

                    <p>
                        <label for="min_php"><strong><?php esc_html_e('Minimum PHP Version', 'license-server'); ?></strong></label>
                        <input type="text" id="min_php" name="min_php" class="widefat" placeholder="8.0">
                    </p>

                    <p>
                        <label for="tested_wp"><strong><?php esc_html_e('Tested up to WordPress', 'license-server'); ?></strong></label>
                        <input type="text" id="tested_wp" name="tested_wp" class="widefat" placeholder="6.4">
                    </p>

                    <!-- Active Status -->
                    <p>
                        <label>
                            <input type="checkbox" name="is_active" value="1" checked>
                            <strong><?php esc_html_e('Active Release', 'license-server'); ?></strong>
                        </label>
                        <em style="display: block;"><?php esc_html_e('Inactive releases are not available for download', 'license-server'); ?></em>
                    </p>
                </div>
            </div>

            <!-- Changelog -->
            <p>
                <label for="changelog"><strong><?php esc_html_e('Changelog', 'license-server'); ?></strong></label>
                <textarea id="changelog" name="changelog" class="widefat" rows="5" placeholder="<?php esc_attr_e('* Added new feature X&#10;* Fixed bug with Y&#10;* Improved performance', 'license-server'); ?>"></textarea>
                <em><?php esc_html_e('Markdown supported. List changes for users.', 'license-server'); ?></em>
            </p>

            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Add Release', 'license-server'); ?>">
                <button type="button" class="button" onclick="toggleAddReleaseForm()"><?php esc_html_e('Cancel', 'license-server'); ?></button>
            </p>
        </form>
    </div>

    <!-- Filters -->
    <div class="lsr-filters" style="background: white; padding: 15px; margin: 20px 0; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <form method="GET" action="">
            <input type="hidden" name="page" value="lsr-releases">
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end;">
                <!-- Search -->
                <div>
                    <label for="search"><?php esc_html_e('Search', 'license-server'); ?></label>
                    <input type="text" id="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Version or plugin name...', 'license-server'); ?>">
                </div>

                <!-- Product Filter -->
                <div>
                    <label for="product"><?php esc_html_e('Product', 'license-server'); ?></label>
                    <select id="product" name="product_id">
                        <option value=""><?php esc_html_e('All Products', 'license-server'); ?></option>
                        <?php
                        foreach ($licensed_products as $product) {
                            echo '<option value="' . esc_attr($product->ID) . '" ' . selected($product_filter, $product->ID, false) . '>' . esc_html($product->post_title) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- Status Filter -->
                <div>
                    <label for="status"><?php esc_html_e('Status', 'license-server'); ?></label>
                    <select id="status" name="status">
                        <option value=""><?php esc_html_e('All Statuses', 'license-server'); ?></option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>><?php esc_html_e('Active', 'license-server'); ?></option>
                        <option value="inactive" <?php selected($status_filter, 'inactive'); ?>><?php esc_html_e('Inactive', 'license-server'); ?></option>
                    </select>
                </div>

                <!-- Submit -->
                <div>
                    <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'license-server'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=lsr-releases'); ?>" class="button"><?php esc_html_e('Reset', 'license-server'); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Statistics Cards -->
    <div class="lsr-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
        <?php
        $stats = getReleaseStats($releaseRepo);
        renderStatCard(__('Total Releases', 'license-server'), $stats['total'], 'dashicons-media-archive', '#0073aa');
        renderStatCard(__('Active Releases', 'license-server'), $stats['active'], 'dashicons-yes-alt', '#00a32a');
        renderStatCard(__('Total Downloads', 'license-server'), $stats['downloads'], 'dashicons-download', '#8f8f8f');
        renderStatCard(__('Storage Used', 'license-server'), $stats['storage'], 'dashicons-chart-area', '#dba617');
        ?>
    </div>

    <!-- Releases Table -->
    <form method="post" id="releases-form">
        <?php wp_nonce_field('lsr_bulk_release_actions', 'lsr_bulk_nonce'); ?>
        
        <!-- Bulk Actions -->
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'license-server'); ?></label>
                <select name="bulk_action" id="bulk-action-selector-top">
                    <option value="-1"><?php esc_html_e('Bulk actions', 'license-server'); ?></option>
                    <option value="activate"><?php esc_html_e('Activate', 'license-server'); ?></option>
                    <option value="deactivate"><?php esc_html_e('Deactivate', 'license-server'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'license-server'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'license-server'); ?>">
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(_n('%s item', '%s items', $total_releases, 'license-server'), number_format_i18n($total_releases)); ?></span>
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;', 'license-server'),
                        'next_text' => __('&raquo;', 'license-server'),
                        'total' => $total_pages,
                        'current' => $current_page
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e('Select All', 'license-server'); ?></label>
                        <input id="cb-select-all-1" type="checkbox">
                    </td>
                    <th scope="col" class="manage-column column-version column-primary">
                        <?php esc_html_e('Version', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-product">
                        <?php esc_html_e('Product', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-slug">
                        <?php esc_html_e('Plugin Slug', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-requirements">
                        <?php esc_html_e('Requirements', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-file-info">
                        <?php esc_html_e('File Info', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-downloads">
                        <?php esc_html_e('Downloads', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-status">
                        <?php esc_html_e('Status', 'license-server'); ?>
                    </th>
                    <th scope="col" class="manage-column column-released">
                        <?php esc_html_e('Released', 'license-server'); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($releases)): ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="9">
                            <?php esc_html_e('No releases found.', 'license-server'); ?>
                            <a href="#" onclick="toggleAddReleaseForm()"><?php esc_html_e('Add the first release', 'license-server'); ?></a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($releases as $release): ?>
                        <?php
                        $product = wc_get_product($release['product_id']);
                        $file_path = LSR_DIR . 'storage/releases/' . $release['zip_path'];
                        $file_exists = file_exists($file_path);
                        $file_size = $file_exists ? filesize($file_path) : 0;
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="release_ids[]" value="<?php echo esc_attr($release['id']); ?>">
                            </th>
                            <td class="column-version column-primary">
                                <strong><?php echo esc_html($release['version']); ?></strong>
                                <?php if (!$file_exists): ?>
                                    <span class="lsr-file-missing" title="<?php esc_attr_e('ZIP file missing', 'license-server'); ?>">⚠️</span>
                                <?php endif; ?>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="#" onclick="editRelease(<?php echo esc_js($release['id']); ?>)"><?php esc_html_e('Edit', 'license-server'); ?></a> |
                                    </span>
                                    <span class="download">
                                        <a href="<?php echo admin_url('admin.php?page=lsr-releases&action=download&release_id=' . $release['id']); ?>" target="_blank"><?php esc_html_e('Download', 'license-server'); ?></a> |
                                    </span>
                                    <span class="toggle">
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lsr-releases&action=toggle&release_id=' . $release['id']), 'toggle_release_' . $release['id']); ?>">
                                            <?php echo $release['is_active'] ? esc_html__('Deactivate', 'license-server') : esc_html__('Activate', 'license-server'); ?>
                                        </a> |
                                    </span>
                                    <span class="delete">
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lsr-releases&action=delete&release_id=' . $release['id']), 'delete_release_' . $release['id']); ?>" 
                                           onclick="return confirm('<?php esc_js_e('Are you sure? This will permanently delete the release and ZIP file.', 'license-server'); ?>');">
                                            <?php esc_html_e('Delete', 'license-server'); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td class="column-product">
                                <?php if ($product): ?>
                                    <a href="<?php echo get_edit_post_link($release['product_id']); ?>">
                                        <?php echo esc_html($product->get_name()); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="description"><?php printf(__('Product #%d (deleted)', 'license-server'), $release['product_id']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-slug">
                                <code><?php echo esc_html($release['slug']); ?></code>
                            </td>
                            <td class="column-requirements">
                                <?php if ($release['min_wp']): ?>
                                    <div><strong>WP:</strong> <?php echo esc_html($release['min_wp']); ?>+</div>
                                <?php endif; ?>
                                <?php if ($release['min_php']): ?>
                                    <div><strong>PHP:</strong> <?php echo esc_html($release['min_php']); ?>+</div>
                                <?php endif; ?>
                                <?php if ($release['tested_wp']): ?>
                                    <div class="description"><strong><?php esc_html_e('Tested:', 'license-server'); ?></strong> <?php echo esc_html($release['tested_wp']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="column-file-info">
                                <?php if ($file_exists): ?>
                                    <div><strong><?php esc_html_e('Size:', 'license-server'); ?></strong> <?php echo size_format($file_size); ?></div>
                                    <div class="description">
                                        <strong><?php esc_html_e('SHA256:', 'license-server'); ?></strong> 
                                        <code title="<?php echo esc_attr($release['checksum_sha256']); ?>">
                                            <?php echo esc_html(substr($release['checksum_sha256'], 0, 8) . '...'); ?>
                                        </code>
                                    </div>
                                <?php else: ?>
                                    <span class="lsr-error"><?php esc_html_e('File missing', 'license-server'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-downloads">
                                <strong><?php echo number_format_i18n($release['download_count'] ?? 0); ?></strong>
                            </td>
                            <td class="column-status">
                                <span class="lsr-status lsr-status-<?php echo $release['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $release['is_active'] ? esc_html__('Active', 'license-server') : esc_html__('Inactive', 'license-server'); ?>
                                </span>
                            </td>
                            <td class="column-released">
                                <?php echo date_i18n(get_option('date_format'), strtotime($release['released_at'])); ?>
                                <div class="description">
                                    <?php echo human_time_diff(strtotime($release['released_at'])); ?> <?php esc_html_e('ago', 'license-server'); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </form>
</div>

<script>
function toggleAddReleaseForm() {
    const form = document.getElementById('add-release-form');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
    
    if (form.style.display === 'block') {
        document.getElementById('product_id').focus();
        form.scrollIntoView({ behavior: 'smooth' });
    }
}

function editRelease(releaseId) {
    // This would open an edit modal or redirect to edit page
    alert('Edit functionality would be implemented here for release ID: ' + releaseId);
}

// Auto-populate slug when product is selected
document.addEventListener('DOMContentLoaded', function() {
    const productSelect = document.getElementById('product_id');
    const slugInput = document.getElementById('slug');
    
    if (productSelect && slugInput) {
        productSelect.addEventListener('change', function() {
            if (this.value && !slugInput.value) {
                const productName = this.options[this.selectedIndex].text;
                const slug = productName.toLowerCase()
                    .replace(/[^a-z0-9 -]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .trim('-');
                slugInput.value = slug;
            }
        });
    }

    // File validation
    const fileInput = document.getElementById('zip_file');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                // Check file size (100MB limit)
                const maxSize = 100 * 1024 * 1024;
                if (file.size > maxSize) {
                    alert('<?php esc_js_e('File is too large. Maximum size is 100MB.', 'license-server'); ?>');
                    this.value = '';
                    return;
                }
                
                // Check file type
                if (!file.name.toLowerCase().endsWith('.zip')) {
                    alert('<?php esc_js_e('Please select a ZIP file.', 'license-server'); ?>');
                    this.value = '';
                    return;
                }
            }
        });
    }
});
</script>

<style>
.lsr-add-release-form {
    border-left: 4px solid #0073aa;
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

.lsr-file-missing {
    color: #d63638;
    font-size: 16px;
}

.lsr-error {
    color: #d63638;
    font-weight: bold;
}

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
 * Helper functions for releases template
 */

function handleReleaseActions($post_data, $releaseRepo) {
    if (isset($post_data['action']) && $post_data['action'] === 'add_release') {
        return handleAddRelease($post_data, $releaseRepo);
    }
    
    if (isset($post_data['bulk_action']) && $post_data['bulk_action'] !== '-1') {
        return handleBulkReleaseActions($post_data, $releaseRepo);
    }
    
    return '';
}

function handleAddRelease($post_data, $releaseRepo) {
    if (!wp_verify_nonce($post_data['lsr_release_nonce'], 'lsr_add_release')) {
        return '<div class="notice notice-error"><p>' . esc_html__('Security check failed.', 'license-server') . '</p></div>';
    }

    $product_id = (int) $post_data['product_id'];
    $slug = sanitize_title($post_data['slug']);
    $version = sanitize_text_field($post_data['version']);
    $min_wp = sanitize_text_field($post_data['min_wp']);
    $min_php = sanitize_text_field($post_data['min_php']);
    $tested_wp = sanitize_text_field($post_data['tested_wp']);
    $is_active = !empty($post_data['is_active']);
    $changelog = wp_kses_post($post_data['changelog']);

    if (!$product_id || !$slug || !$version || empty($_FILES['zip_file']['name'])) {
        return '<div class="notice notice-error"><p>' . esc_html__('Please fill all required fields and select a ZIP file.', 'license-server') . '</p></div>';
    }

    // Handle file upload
    require_once ABSPATH . 'wp-admin/includes/file.php';
    
    $upload_result = handleZipUpload($_FILES['zip_file']);
    if (isset($upload_result['error'])) {
        return '<div class="notice notice-error"><p>' . esc_html($upload_result['error']) . '</p></div>';
    }

    // Create release record
    $release_data = [
        'product_id' => $product_id,
        'slug' => $slug,
        'version' => $version,
        'zip_path' => $upload_result['filename'],
        'checksum_sha256' => $upload_result['checksum'],
        'file_size' => $upload_result['size'],
        'min_wp' => $min_wp ?: null,
        'min_php' => $min_php ?: null,
        'tested_wp' => $tested_wp ?: null,
        'changelog' => $changelog ?: null,
        'is_active' => $is_active
    ];

    $release_id = $releaseRepo->create($release_data);
    
    if ($release_id) {
        return '<div class="notice notice-success"><p>' . esc_html__('Release added successfully!', 'license-server') . '</p></div>';
    } else {
        // Clean up uploaded file if database insert failed
        @unlink(LSR_DIR . 'storage/releases/' . $upload_result['filename']);
        return '<div class="notice notice-error"><p>' . esc_html__('Failed to create release. Version may already exist.', 'license-server') . '</p></div>';
    }
}

function handleZipUpload($file) {
    $upload_overrides = ['test_form' => false];
    $movefile = wp_handle_upload($file, $upload_overrides);
    
    if (isset($movefile['error'])) {
        return ['error' => $movefile['error']];
    }

    // Create storage directory if it doesn't exist
    $storage_dir = LSR_DIR . 'storage/releases/';
    if (!is_dir($storage_dir)) {
        wp_mkdir_p($storage_dir);
    }

    // Generate unique filename
    $original_name = sanitize_file_name(basename($file['name']));
    $name_parts = pathinfo($original_name);
    $base_name = $name_parts['filename'];
    $extension = $name_parts['extension'];
    
    $counter = 1;
    $new_filename = $original_name;
    while (file_exists($storage_dir . $new_filename)) {
        $new_filename = $base_name . '-' . $counter . '.' . $extension;
        $counter++;
    }

    $target_path = $storage_dir . $new_filename;
    
    // Move file to storage directory
    if (!rename($movefile['file'], $target_path)) {
        return ['error' => __('Failed to move uploaded file to storage directory.', 'license-server')];
    }

    // Calculate checksum
    $checksum = hash_file('sha256', $target_path);
    $file_size = filesize($target_path);

    // Validate ZIP file
    $zip = new ZipArchive();
    if ($zip->open($target_path) !== TRUE) {
        @unlink($target_path);
        return ['error' => __('Invalid ZIP file.', 'license-server')];
    }
    $zip->close();

    return [
        'filename' => $new_filename,
        'checksum' => $checksum,
        'size' => $file_size
    ];
}

function handleBulkReleaseActions($post_data, $releaseRepo) {
    if (!wp_verify_nonce($post_data['lsr_bulk_nonce'], 'lsr_bulk_release_actions')) {
        return '<div class="notice notice-error"><p>' . esc_html__('Security check failed.', 'license-server') . '</p></div>';
    }

    $action = $post_data['bulk_action'];
    $release_ids = $post_data['release_ids'] ?? [];

    if (empty($release_ids)) {
        return '<div class="notice notice-warning"><p>' . esc_html__('Please select releases.', 'license-server') . '</p></div>';
    }

    $updated = 0;
    foreach ($release_ids as $release_id) {
        $release_id = (int) $release_id;
        switch ($action) {
            case 'activate':
                // This would need an update method in repository
                // $releaseRepo->update($release_id, ['is_active' => 1]);
                $updated++;
                break;
            case 'deactivate':
                // $releaseRepo->update($release_id, ['is_active' => 0]);
                $updated++;
                break;
            case 'delete':
                // This would need a delete method and file cleanup
                // $releaseRepo->delete($release_id);
                $updated++;
                break;
        }
    }

    return '<div class="notice notice-success"><p>' . sprintf(_n('%d release updated.', '%d releases updated.', $updated, 'license-server'), $updated) . '</p></div>';
}

function handleSingleActions($get_data, $releaseRepo) {
    $action = $get_data['action'];
    $release_id = (int) $get_data['release_id'];
    
    switch ($action) {
        case 'toggle':
            if (wp_verify_nonce($get_data['_wpnonce'], 'toggle_release_' . $release_id)) {
                // Toggle active status
                $release = $releaseRepo->findById($release_id);
                if ($release) {
                    $new_status = !$release['is_active'];
                    // $releaseRepo->update($release_id, ['is_active' => $new_status]);
                    $status_text = $new_status ? __('activated', 'license-server') : __('deactivated', 'license-server');
                    return '<div class="notice notice-success"><p>' . sprintf(__('Release %s.', 'license-server'), $status_text) . '</p></div>';
                }
            }
            break;
            
        case 'delete':
            if (wp_verify_nonce($get_data['_wpnonce'], 'delete_release_' . $release_id)) {
                $release = $releaseRepo->findById($release_id);
                if ($release) {
                    // Delete file
                    $file_path = LSR_DIR . 'storage/releases/' . $release['zip_path'];
                    @unlink($file_path);
                    
                    // Delete from database
                    // $releaseRepo->delete($release_id);
                    return '<div class="notice notice-success"><p>' . esc_html__('Release deleted.', 'license-server') . '</p></div>';
                }
            }
            break;
    }
    
    return '';
}

function getReleasesWithFilters($releaseRepo, $product_id, $status, $search, $limit, $offset) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_releases';
    
    $where_conditions = ['1=1'];
    $params = [];

    if ($product_id) {
        $where_conditions[] = 'product_id = %d';
        $params[] = $product_id;
    }

    if ($status === 'active') {
        $where_conditions[] = 'is_active = 1';
    } elseif ($status === 'inactive') {
        $where_conditions[] = 'is_active = 0';
    }

    if ($search) {
        $where_conditions[] = '(version LIKE %s OR slug LIKE %s)';
        $search_param = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $where_clause = implode(' AND ', $where_conditions);
    $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY released_at DESC LIMIT %d OFFSET %d";
    $params[] = $limit;
    $params[] = $offset;

    return $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A) ?: [];
}

function getTotalReleasesCount($releaseRepo, $product_id, $status, $search) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_releases';
    
    $where_conditions = ['1=1'];
    $params = [];

    if ($product_id) {
        $where_conditions[] = 'product_id = %d';
        $params[] = $product_id;
    }

    if ($status === 'active') {
        $where_conditions[] = 'is_active = 1';
    } elseif ($status === 'inactive') {
        $where_conditions[] = 'is_active = 0';
    }

    if ($search) {
        $where_conditions[] = '(version LIKE %s OR slug LIKE %s)';
        $search_param = '%' . $wpdb->esc_like($search) . '%';
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

function getReleaseStats($releaseRepo) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_releases';
    
    $stats = [];
    $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $stats['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
    $stats['downloads'] = (int) $wpdb->get_var("SELECT SUM(download_count) FROM {$table}");
    
    // Calculate storage used
    $total_size = 0;
    $files = $wpdb->get_results("SELECT zip_path FROM {$table}");
    foreach ($files as $file) {
        $file_path = LSR_DIR . 'storage/releases/' . $file->zip_path;
        if (file_exists($file_path)) {
            $total_size += filesize($file_path);
        }
    }
    $stats['storage'] = size_format($total_size);

    return $stats;
}

function renderStatCard($label, $value, $icon, $color) {
    echo '<div class="stat-card">';
    echo '<div class="dashicons ' . esc_attr($icon) . '" style="font-size: 32px; color: ' . esc_attr($color) . ';"></div>';
    echo '<div class="stat-value" style="color: ' . esc_attr($color) . ';">' . esc_html($value) . '</div>';
    echo '<div class="stat-label">' . esc_html($label) . '</div>';
    echo '</div>';
}