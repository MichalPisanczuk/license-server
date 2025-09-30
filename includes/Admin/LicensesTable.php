<?php
namespace MyShop\LicenseServer\Admin;

/**
 * Simplified WP_List_Table for displaying licenses
 * Works directly with database - no Repository dependencies
 */
class LicensesTable extends \WP_List_Table
{
    /**
     * MUST be public for WP_List_Table compatibility
     */
    public $items;
    
    private $table_name;
    private $activations_table;
    
    public function __construct()
    {
        global $wpdb;
        
        parent::__construct([
            'singular' => __('License', 'license-server'),
            'plural'   => __('Licenses', 'license-server'),
            'ajax'     => false
        ]);
        
        $this->table_name = $wpdb->prefix . 'lsr_licenses';
        $this->activations_table = $wpdb->prefix . 'lsr_activations';
    }
    
    /**
     * Get table columns
     */
    public function get_columns()
    {
        return [
            'cb'            => '<input type="checkbox" />',
            'license_key'   => __('License Key', 'license-server'),
            'user'          => __('User', 'license-server'),
            'product'       => __('Product', 'license-server'),
            'status'        => __('Status', 'license-server'),
            'activations'   => __('Activations', 'license-server'),
            'expires_at'    => __('Expires', 'license-server'),
            'created_at'    => __('Created', 'license-server')
        ];
    }
    
    /**
     * Get sortable columns
     */
    public function get_sortable_columns()
    {
        return [
            'license_key' => ['license_key', false],
            'user'        => ['user_id', false],
            'product'     => ['product_id', false],
            'status'      => ['status', false],
            'expires_at'  => ['expires_at', false],
            'created_at'  => ['created_at', true], // Default sort
        ];
    }
    
    /**
     * Prepare items for display
     */
    public function prepare_items()
    {
        global $wpdb;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            $this->items = [];
            $this->set_pagination_args(['total_items' => 0]);
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error">';
                echo '<p>' . __('License table does not exist. Please reactivate the plugin.', 'license-server') . '</p>';
                echo '</div>';
            });
            return;
        }
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        
        // Get sorting parameters
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';
        
        // Build WHERE clause
        $where = "WHERE 1=1";
        $where_args = [];
        
        // Status filter
        if (!empty($_GET['status'])) {
            $where .= " AND status = %s";
            $where_args[] = sanitize_text_field($_GET['status']);
        }
        
        // User filter
        if (!empty($_GET['user_id'])) {
            $where .= " AND user_id = %d";
            $where_args[] = intval($_GET['user_id']);
        }
        
        // Product filter
        if (!empty($_GET['product_id'])) {
            $where .= " AND product_id = %d";
            $where_args[] = intval($_GET['product_id']);
        }
        
        // Search
        if (!empty($_GET['s'])) {
            $search = '%' . $wpdb->esc_like($_GET['s']) . '%';
            $where .= " AND license_key LIKE %s";
            $where_args[] = $search;
        }
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$this->table_name} {$where}";
        if (!empty($where_args)) {
            $count_query = $wpdb->prepare($count_query, $where_args);
        }
        $total_items = $wpdb->get_var($count_query);
        
        // Get licenses
        $offset = ($current_page - 1) * $per_page;
        
        // Validate orderby column
        $valid_columns = ['license_key', 'user_id', 'product_id', 'status', 'expires_at', 'created_at'];
        if (!in_array($orderby, $valid_columns)) {
            $orderby = 'created_at';
        }
        
        $query = "SELECT * FROM {$this->table_name} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query_args = array_merge($where_args, [$per_page, $offset]);
        
        if (!empty($query_args)) {
            $query = $wpdb->prepare($query, $query_args);
        }
        
        $results = $wpdb->get_results($query, ARRAY_A);
        $this->items = $results ? $results : [];
        
        // Set pagination
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
        
        // Set column headers
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];
    }
    
    /**
     * Default column renderer
     */
    public function column_default($item, $column_name)
    {
        return isset($item[$column_name]) ? esc_html($item[$column_name]) : '';
    }
    
    /**
     * Checkbox column
     */
    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="license[]" value="%s" />',
            $item['id']
        );
    }
    
    /**
     * License key column
     */
    public function column_license_key($item)
    {
        $actions = [
            'edit' => sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=lsr-licenses&action=edit&id=' . $item['id']),
                __('Edit', 'license-server')
            ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'Are you sure?\')">%s</a>',
                wp_nonce_url(
                    admin_url('admin.php?page=lsr-licenses&action=delete&id=' . $item['id']),
                    'delete-license-' . $item['id']
                ),
                __('Delete', 'license-server')
            )
        ];
        
        return sprintf(
            '<code style="font-size: 13px;">%s</code> %s',
            esc_html($item['license_key']),
            $this->row_actions($actions)
        );
    }
    
    /**
     * User column
     */
    public function column_user($item)
    {
        if (empty($item['user_id'])) {
            return '<span style="color: #999;">—</span>';
        }
        
        $user = get_userdata($item['user_id']);
        if (!$user) {
            return '<span style="color: #999;">User #' . $item['user_id'] . ' (deleted)</span>';
        }
        
        return sprintf(
            '<a href="%s">%s</a><br><small>%s</small>',
            admin_url('user-edit.php?user_id=' . $item['user_id']),
            esc_html($user->display_name),
            esc_html($user->user_email)
        );
    }
    
    /**
     * Product column
     */
    public function column_product($item)
    {
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($item['product_id']);
            if (!$product) {
                return '<span style="color: #999;">Product #' . $item['product_id'] . ' (deleted)</span>';
            }
            
            return sprintf(
                '<a href="%s">%s</a>',
                admin_url('post.php?post=' . $item['product_id'] . '&action=edit'),
                esc_html($product->get_name())
            );
        }
        
        return 'Product #' . $item['product_id'];
    }
    
    /**
     * Status column
     */
    public function column_status($item)
    {
        $status_labels = [
            'active' => __('Active', 'license-server'),
            'inactive' => __('Inactive', 'license-server'),
            'expired' => __('Expired', 'license-server'),
            'suspended' => __('Suspended', 'license-server'),
            'revoked' => __('Revoked', 'license-server')
        ];
        
        $status_colors = [
            'active' => '#46b450',
            'inactive' => '#999',
            'expired' => '#dc3232',
            'suspended' => '#ffb900',
            'revoked' => '#000'
        ];
        
        $status = $item['status'] ?? 'unknown';
        $label = $status_labels[$status] ?? ucfirst($status);
        $color = $status_colors[$status] ?? '#666';
        
        return sprintf(
            '<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; background: %s; color: white; font-size: 12px;">%s</span>',
            $color . '20',
            '<strong style="color: ' . $color . ';">' . $label . '</strong>'
        );
    }
    
    /**
     * Activations column
     */
    public function column_activations($item)
    {
        global $wpdb;
        
        // Check if activations table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->activations_table}'") !== $this->activations_table) {
            return '<span style="color: #999;">—</span>';
        }
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->activations_table} WHERE license_id = %d",
            $item['id']
        ));
        
        $max = $item['max_activations'] ?? null;
        if ($max === null || $max == 0) {
            $max = '∞';
        }
        
        $color = '#46b450';
        if ($max !== '∞' && $count >= $max) {
            $color = '#dc3232';
        } elseif ($max !== '∞' && $count >= ($max * 0.8)) {
            $color = '#ffb900';
        }
        
        return sprintf(
            '<span style="color: %s; font-weight: bold;">%d / %s</span>',
            $color,
            $count,
            $max
        );
    }
    
    /**
     * Expires column
     */
    public function column_expires_at($item)
    {
        if (empty($item['expires_at']) || $item['expires_at'] === '0000-00-00 00:00:00') {
            return '<span style="color: #46b450;">Never</span>';
        }
        
        $expires = strtotime($item['expires_at']);
        if (!$expires) {
            return '<span style="color: #999;">Invalid date</span>';
        }
        
        $now = current_time('timestamp');
        $days_left = ceil(($expires - $now) / DAY_IN_SECONDS);
        
        if ($days_left < 0) {
            $color = '#dc3232';
            $text = sprintf(__('Expired %d days ago', 'license-server'), abs($days_left));
        } elseif ($days_left <= 7) {
            $color = '#dc3232';
            $text = sprintf(__('%d days left', 'license-server'), $days_left);
        } elseif ($days_left <= 30) {
            $color = '#ffb900';
            $text = sprintf(__('%d days left', 'license-server'), $days_left);
        } else {
            $color = '#46b450';
            $text = date_i18n(get_option('date_format'), $expires);
        }
        
        return sprintf('<span style="color: %s;">%s</span>', $color, $text);
    }
    
    /**
     * Created column
     */
    public function column_created_at($item)
    {
        if (empty($item['created_at']) || $item['created_at'] === '0000-00-00 00:00:00') {
            return '<span style="color: #999;">Unknown</span>';
        }
        
        $created = strtotime($item['created_at']);
        if (!$created) {
            return '<span style="color: #999;">Invalid date</span>';
        }
        
        return sprintf(
            '<span title="%s">%s</span>',
            esc_attr($item['created_at']),
            date_i18n(get_option('date_format'), $created)
        );
    }
    
    /**
     * Get bulk actions
     */
    public function get_bulk_actions()
    {
        return [
            'bulk-activate'   => __('Activate', 'license-server'),
            'bulk-deactivate' => __('Deactivate', 'license-server'),
            'bulk-delete'     => __('Delete', 'license-server')
        ];
    }
    
    /**
     * Process bulk actions
     */
    public function process_bulk_action()
    {
        global $wpdb;
        
        // Security check
        if (empty($_REQUEST['_wpnonce'])) {
            return;
        }
        
        $action = $this->current_action();
        if (!$action) {
            return;
        }
        
        // Get selected licenses
        $license_ids = isset($_REQUEST['license']) ? $_REQUEST['license'] : [];
        if (empty($license_ids)) {
            return;
        }
        
        $count = 0;
        
        switch ($action) {
            case 'bulk-activate':
                foreach ($license_ids as $id) {
                    $wpdb->update(
                        $this->table_name,
                        ['status' => 'active', 'updated_at' => current_time('mysql')],
                        ['id' => intval($id)]
                    );
                    $count++;
                }
                $message = sprintf(__('%d licenses activated.', 'license-server'), $count);
                break;
                
            case 'bulk-deactivate':
                foreach ($license_ids as $id) {
                    $wpdb->update(
                        $this->table_name,
                        ['status' => 'inactive', 'updated_at' => current_time('mysql')],
                        ['id' => intval($id)]
                    );
                    $count++;
                }
                $message = sprintf(__('%d licenses deactivated.', 'license-server'), $count);
                break;
                
            case 'bulk-delete':
                foreach ($license_ids as $id) {
                    // Delete activations first
                    $wpdb->delete($this->activations_table, ['license_id' => intval($id)]);
                    // Delete license
                    $wpdb->delete($this->table_name, ['id' => intval($id)]);
                    $count++;
                }
                $message = sprintf(__('%d licenses deleted.', 'license-server'), $count);
                break;
                
            default:
                return;
        }
        
        // Redirect with message
        wp_redirect(add_query_arg([
            'page' => 'lsr-licenses',
            'message' => urlencode($message)
        ], admin_url('admin.php')));
        exit;
    }
}