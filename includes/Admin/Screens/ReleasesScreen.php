<?php
namespace MyShop\LicenseServer\Admin\Screens;

/**
 * Simplified Releases Screen without DI container dependency
 */
class ReleasesScreen
{
    /**
     * Render the releases management page
     */
    public static function render(): void
    {
        global $wpdb;
        
        $releases_table = $wpdb->prefix . 'lsr_releases';
        
        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handleFormSubmission();
        }
        
        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            self::handleDelete();
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Releases Management', 'license-server'); ?></h1>
            
            <?php self::showMessages(); ?>
            
            <!-- Add New Release Form -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php _e('Add New Release', 'license-server'); ?></h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('lsr_add_release', 'lsr_nonce'); ?>
                    <input type="hidden" name="action" value="add_release">
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="product_id"><?php _e('Product', 'license-server'); ?></label></th>
                            <td>
                                <select name="product_id" id="product_id" required class="regular-text">
                                    <option value=""><?php _e('Select Product', 'license-server'); ?></option>
                                    <?php
                                    // Get licensed products
                                    $products = self::getLicensedProducts();
                                    foreach ($products as $product_id => $product_name) {
                                        echo '<option value="' . esc_attr($product_id) . '">' . esc_html($product_name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="version"><?php _e('Version', 'license-server'); ?></label></th>
                            <td>
                                <input type="text" name="version" id="version" class="regular-text" 
                                       placeholder="1.0.0" required pattern="[0-9]+\.[0-9]+\.[0-9]+"
                                       title="Format: X.Y.Z (np. 1.0.0)">
                                <p class="description"><?php _e('Use semantic versioning (e.g., 1.2.3)', 'license-server'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="slug"><?php _e('Plugin Slug', 'license-server'); ?></label></th>
                            <td>
                                <input type="text" name="slug" id="slug" class="regular-text" 
                                       placeholder="my-plugin" required>
                                <p class="description"><?php _e('Plugin folder name', 'license-server'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="changelog"><?php _e('Changelog', 'license-server'); ?></label></th>
                            <td>
                                <textarea name="changelog" id="changelog" class="large-text" rows="5" 
                                          placeholder="* Fixed bug X&#10;* Added feature Y&#10;* Improved performance"></textarea>
                                <p class="description"><?php _e('List changes in this version', 'license-server'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="release_file"><?php _e('Plugin ZIP File', 'license-server'); ?></label></th>
                            <td>
                                <input type="file" name="release_file" id="release_file" accept=".zip" required>
                                <p class="description"><?php _e('Upload the plugin ZIP file', 'license-server'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_php"><?php _e('Minimum PHP', 'license-server'); ?></label></th>
                            <td>
                                <input type="text" name="min_php" id="min_php" class="regular-text" 
                                       placeholder="7.4" pattern="[0-9]+\.[0-9]+">
                                <p class="description"><?php _e('Optional: Minimum PHP version required', 'license-server'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="min_wp"><?php _e('Minimum WordPress', 'license-server'); ?></label></th>
                            <td>
                                <input type="text" name="min_wp" id="min_wp" class="regular-text" 
                                       placeholder="5.8" pattern="[0-9]+\.[0-9]+">
                                <p class="description"><?php _e('Optional: Minimum WordPress version required', 'license-server'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php _e('Add Release', 'license-server'); ?>
                        </button>
                    </p>
                </form>
            </div>
            
            <!-- Existing Releases List -->
            <div style="margin-top: 40px;">
                <h2><?php _e('Existing Releases', 'license-server'); ?></h2>
                <?php self::renderReleasesTable(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get licensed products from WooCommerce
     */
    private static function getLicensedProducts(): array
    {
        $products = [];
        
        if (!function_exists('wc_get_products')) {
            return $products;
        }
        
        // Get all products with license meta
        $args = [
            'meta_key' => '_lsr_is_licensed',
            'meta_value' => 'yes',
            'post_type' => 'product',
            'posts_per_page' => -1
        ];
        
        $query = new \WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $products[get_the_ID()] = get_the_title();
            }
            wp_reset_postdata();
        }
        
        return $products;
    }
    
    /**
     * Handle form submission
     */
    private static function handleFormSubmission(): void
    {
        if (!isset($_POST['lsr_nonce']) || !wp_verify_nonce($_POST['lsr_nonce'], 'lsr_add_release')) {
            wp_die('Security check failed');
        }
        
        if ($_POST['action'] !== 'add_release') {
            return;
        }
        
        global $wpdb;
        $releases_table = $wpdb->prefix . 'lsr_releases';
        
        // Handle file upload
        $upload_dir = wp_upload_dir();
        $releases_dir = $upload_dir['basedir'] . '/lsr-releases';
        
        if (!file_exists($releases_dir)) {
            wp_mkdir_p($releases_dir);
        }
        
        $file_url = '';
        if (!empty($_FILES['release_file']['tmp_name'])) {
            $filename = sanitize_file_name($_FILES['release_file']['name']);
            $filename = time() . '-' . $filename;
            $filepath = $releases_dir . '/' . $filename;
            
            if (move_uploaded_file($_FILES['release_file']['tmp_name'], $filepath)) {
                $file_url = $upload_dir['baseurl'] . '/lsr-releases/' . $filename;
            } else {
                $_SESSION['lsr_error'] = __('Failed to upload file', 'license-server');
                return;
            }
        }
        
        // Insert release
        $result = $wpdb->insert(
            $releases_table,
            [
                'product_id' => intval($_POST['product_id']),
                'slug' => sanitize_text_field($_POST['slug']),
                'version' => sanitize_text_field($_POST['version']),
                'changelog' => sanitize_textarea_field($_POST['changelog']),
                'download_url' => $file_url,
                'min_php' => sanitize_text_field($_POST['min_php'] ?? ''),
                'min_wp' => sanitize_text_field($_POST['min_wp'] ?? ''),
                'released_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result) {
            $_SESSION['lsr_message'] = __('Release added successfully!', 'license-server');
        } else {
            $_SESSION['lsr_error'] = __('Failed to add release: ', 'license-server') . $wpdb->last_error;
        }
        
        // Redirect to prevent form resubmission
        wp_redirect(admin_url('admin.php?page=lsr-releases'));
        exit;
    }
    
    /**
     * Handle delete action
     */
    private static function handleDelete(): void
    {
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_release_' . $_GET['id'])) {
            wp_die('Security check failed');
        }
        
        global $wpdb;
        $releases_table = $wpdb->prefix . 'lsr_releases';
        
        // Get release info for file deletion
        $release = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $releases_table WHERE id = %d",
            intval($_GET['id'])
        ));
        
        if ($release && !empty($release->download_url)) {
            // Delete file
            $upload_dir = wp_upload_dir();
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $release->download_url);
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }
        
        // Delete from database
        $result = $wpdb->delete($releases_table, ['id' => intval($_GET['id'])]);
        
        if ($result) {
            $_SESSION['lsr_message'] = __('Release deleted successfully!', 'license-server');
        } else {
            $_SESSION['lsr_error'] = __('Failed to delete release', 'license-server');
        }
        
        wp_redirect(admin_url('admin.php?page=lsr-releases'));
        exit;
    }
    
    /**
     * Show messages
     */
    private static function showMessages(): void
    {
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_SESSION['lsr_message'])) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html($_SESSION['lsr_message']) . '</p>';
            echo '</div>';
            unset($_SESSION['lsr_message']);
        }
        
        if (isset($_SESSION['lsr_error'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . esc_html($_SESSION['lsr_error']) . '</p>';
            echo '</div>';
            unset($_SESSION['lsr_error']);
        }
    }
    
    /**
     * Render releases table
     */
    private static function renderReleasesTable(): void
    {
        global $wpdb;
        $releases_table = $wpdb->prefix . 'lsr_releases';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$releases_table'") !== $releases_table) {
            echo '<div class="notice notice-error">';
            echo '<p>' . __('Releases table does not exist. Please reactivate the plugin.', 'license-server') . '</p>';
            echo '</div>';
            return;
        }
        
        $releases = $wpdb->get_results("
            SELECT r.*, p.post_title as product_name 
            FROM $releases_table r
            LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
            ORDER BY r.released_at DESC
        ");
        
        if (empty($releases)) {
            echo '<p>' . __('No releases found. Add your first release above.', 'license-server') . '</p>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('ID', 'license-server'); ?></th>
                    <th><?php _e('Product', 'license-server'); ?></th>
                    <th><?php _e('Slug', 'license-server'); ?></th>
                    <th><?php _e('Version', 'license-server'); ?></th>
                    <th><?php _e('Changelog', 'license-server'); ?></th>
                    <th><?php _e('File', 'license-server'); ?></th>
                    <th><?php _e('Requirements', 'license-server'); ?></th>
                    <th><?php _e('Released', 'license-server'); ?></th>
                    <th><?php _e('Actions', 'license-server'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($releases as $release): ?>
                <tr>
                    <td><?php echo esc_html($release->id); ?></td>
                    <td>
                        <?php 
                        if ($release->product_name) {
                            echo esc_html($release->product_name);
                        } else {
                            echo '<span style="color: #999;">Product #' . $release->product_id . '</span>';
                        }
                        ?>
                    </td>
                    <td><code><?php echo esc_html($release->slug); ?></code></td>
                    <td>
                        <span style="background: #0073aa; color: white; padding: 2px 6px; border-radius: 3px;">
                            <?php echo esc_html($release->version); ?>
                        </span>
                    </td>
                    <td>
                        <?php 
                        if ($release->changelog) {
                            echo '<div style="max-width: 300px; max-height: 60px; overflow-y: auto; font-size: 12px;">';
                            echo nl2br(esc_html(substr($release->changelog, 0, 200)));
                            if (strlen($release->changelog) > 200) {
                                echo '...';
                            }
                            echo '</div>';
                        } else {
                            echo '<span style="color: #999;">—</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ($release->download_url): ?>
                            <a href="<?php echo esc_url($release->download_url); ?>" 
                               class="button button-small" 
                               target="_blank">
                                <?php _e('Download', 'license-server'); ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #999;">No file</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 12px;">
                        <?php
                        $requirements = [];
                        if (!empty($release->min_php)) {
                            $requirements[] = 'PHP ' . $release->min_php . '+';
                        }
                        if (!empty($release->min_wp)) {
                            $requirements[] = 'WP ' . $release->min_wp . '+';
                        }
                        echo !empty($requirements) ? implode('<br>', $requirements) : '—';
                        ?>
                    </td>
                    <td>
                        <?php 
                        $date = strtotime($release->released_at);
                        echo date_i18n(get_option('date_format'), $date);
                        ?>
                    </td>
                    <td>
                        <a href="<?php echo wp_nonce_url(
                            admin_url('admin.php?page=lsr-releases&action=delete&id=' . $release->id),
                            'delete_release_' . $release->id
                        ); ?>" 
                        class="button button-small button-link-delete"
                        onclick="return confirm('<?php _e('Are you sure you want to delete this release?', 'license-server'); ?>')">
                            <?php _e('Delete', 'license-server'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}