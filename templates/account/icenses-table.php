<?php
/**
 * Frontend template for customer licenses in "My Account" page.
 * 
 * @package MyShop\LicenseServer
 */

if (!defined('ABSPATH')) {
    exit;
}

use MyShop\LicenseServer\Data\Repositories\LicenseRepository;
use MyShop\LicenseServer\Data\Repositories\ActivationRepository;
use function MyShop\LicenseServer\lsr;

// Check if user is logged in
if (!is_user_logged_in()) {
    echo '<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">';
    echo '<p>' . esc_html__('You must be logged in to view your licenses.', 'license-server') . '</p>';
    echo '</div>';
    return;
}

// Get current user
$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// Get repositories
$licenseRepo = lsr(LicenseRepository::class);
$activationRepo = lsr(ActivationRepository::class);

if (!$licenseRepo) {
    echo '<div class="woocommerce-message woocommerce-message--error woocommerce-Message woocommerce-Message--error woocommerce-error">';
    echo '<p>' . esc_html__('License system is temporarily unavailable.', 'license-server') . '</p>';
    echo '</div>';
    return;
}

// Get user's licenses
$licenses = $licenseRepo->findByUser($user_id);

// If no licenses found
if (empty($licenses)) {
    echo '<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">';
    echo '<p>' . esc_html__('You don\'t have any licenses yet.', 'license-server') . '</p>';
    echo '<p><a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="button">' . esc_html__('Shop Now', 'license-server') . '</a></p>';
    echo '</div>';
    return;
}

// Get activation counts for all licenses
$license_activations = [];
if ($activationRepo) {
    foreach ($licenses as $license) {
        $license_activations[$license['id']] = getDetailedActivations($license['id']);
    }
}

?>

<div class="lsr-account-licenses">
    
    <!-- Header -->
    <div class="lsr-licenses-header">
        <h3><?php esc_html_e('Your Licenses', 'license-server'); ?></h3>
        <p class="lsr-licenses-intro"><?php esc_html_e('Manage your plugin licenses, view activation status, and access download links.', 'license-server'); ?></p>
    </div>

    <!-- Licenses Grid/List -->
    <div class="lsr-licenses-grid">
        <?php foreach ($licenses as $license): ?>
            <?php
            $product = wc_get_product($license['product_id']);
            $activations = $license_activations[$license['id']] ?? [];
            $activation_count = count($activations);
            $max_activations = $license['max_activations'] ? (int)$license['max_activations'] : 0;
            
            // Determine license status
            $status = $license['status'];
            $is_expired = false;
            $is_expiring_soon = false;
            $days_remaining = null;
            
            if ($license['expires_at']) {
                $expires_timestamp = strtotime($license['expires_at']);
                $now = time();
                $days_remaining = ceil(($expires_timestamp - $now) / (24 * 60 * 60));
                $is_expired = $expires_timestamp < $now;
                $is_expiring_soon = $days_remaining <= 30 && $days_remaining > 0;
                
                if ($is_expired && $license['grace_until']) {
                    $grace_timestamp = strtotime($license['grace_until']);
                    if ($grace_timestamp >= $now) {
                        $status = 'grace';
                        $is_expired = false;
                        $days_remaining = ceil(($grace_timestamp - $now) / (24 * 60 * 60));
                    }
                }
            }
            ?>
            
            <div class="lsr-license-card lsr-license-status-<?php echo esc_attr($status); ?> <?php echo $is_expired ? 'lsr-expired' : ''; ?> <?php echo $is_expiring_soon ? 'lsr-expiring-soon' : ''; ?>">
                
                <!-- License Header -->
                <div class="lsr-license-header">
                    <div class="lsr-license-product">
                        <h4><?php echo $product ? esc_html($product->get_name()) : esc_html__('Unknown Product', 'license-server'); ?></h4>
                        <span class="lsr-license-status lsr-status-<?php echo esc_attr($status); ?>">
                            <?php
                            switch ($status) {
                                case 'active':
                                    esc_html_e('Active', 'license-server');
                                    break;
                                case 'inactive':
                                    esc_html_e('Inactive', 'license-server');
                                    break;
                                case 'expired':
                                    esc_html_e('Expired', 'license-server');
                                    break;
                                case 'revoked':
                                    esc_html_e('Revoked', 'license-server');
                                    break;
                                case 'grace':
                                    esc_html_e('Grace Period', 'license-server');
                                    break;
                            }
                            ?>
                        </span>
                    </div>
                    
                    <?php if ($product && $product->get_image_id()): ?>
                        <div class="lsr-license-image">
                            <?php echo wp_get_attachment_image($product->get_image_id(), 'thumbnail'); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- License Key -->
                <div class="lsr-license-key">
                    <label><?php esc_html_e('License Key:', 'license-server'); ?></label>
                    <div class="lsr-key-display">
                        <input type="text" value="<?php echo esc_attr($license['license_key']); ?>" readonly class="lsr-key-field">
                        <button type="button" class="lsr-copy-btn" onclick="copyToClipboard('<?php echo esc_js($license['license_key']); ?>', this)" title="<?php esc_attr_e('Copy to clipboard', 'license-server'); ?>">
                            <span class="dashicons dashicons-clipboard"></span>
                        </button>
                    </div>
                </div>

                <!-- License Details -->
                <div class="lsr-license-details">
                    
                    <!-- Expiration -->
                    <?php if ($license['expires_at']): ?>
                        <div class="lsr-license-detail lsr-expiration <?php echo $is_expired ? 'lsr-expired' : ($is_expiring_soon ? 'lsr-expiring-soon' : ''); ?>">
                            <span class="lsr-detail-label">
                                <?php echo $status === 'grace' ? esc_html__('Grace period ends:', 'license-server') : esc_html__('Expires:', 'license-server'); ?>
                            </span>
                            <span class="lsr-detail-value">
                                <?php 
                                $display_date = $status === 'grace' && $license['grace_until'] ? $license['grace_until'] : $license['expires_at'];
                                echo esc_html(date_i18n(get_option('date_format'), strtotime($display_date)));
                                
                                if ($days_remaining !== null) {
                                    if ($days_remaining > 0) {
                                        echo '<small class="lsr-days-remaining">(' . sprintf(_n('%d day remaining', '%d days remaining', $days_remaining, 'license-server'), $days_remaining) . ')</small>';
                                    } elseif ($days_remaining === 0) {
                                        echo '<small class="lsr-days-remaining">' . esc_html__('(expires today)', 'license-server') . '</small>';
                                    } else {
                                        echo '<small class="lsr-days-remaining lsr-expired">' . sprintf(_n('%d day ago', '%d days ago', abs($days_remaining), 'license-server'), abs($days_remaining)) . '</small>';
                                    }
                                }
                                ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="lsr-license-detail lsr-expiration">
                            <span class="lsr-detail-label"><?php esc_html_e('Expires:', 'license-server'); ?></span>
                            <span class="lsr-detail-value lsr-lifetime"><?php esc_html_e('Never (Lifetime)', 'license-server'); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Activations -->
                    <div class="lsr-license-detail lsr-activations">
                        <span class="lsr-detail-label"><?php esc_html_e('Activations:', 'license-server'); ?></span>
                        <span class="lsr-detail-value">
                            <?php echo esc_html($activation_count); ?> / <?php echo $max_activations ? esc_html($max_activations) : esc_html__('Unlimited', 'license-server'); ?>
                            <?php if ($max_activations && $activation_count >= $max_activations): ?>
                                <small class="lsr-limit-reached"><?php esc_html_e('(limit reached)', 'license-server'); ?></small>
                            <?php endif; ?>
                        </span>
                    </div>

                    <!-- Purchase Date -->
                    <div class="lsr-license-detail lsr-purchase-date">
                        <span class="lsr-detail-label"><?php esc_html_e('Purchased:', 'license-server'); ?></span>
                        <span class="lsr-detail-value">
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($license['created_at']))); ?>
                        </span>
                    </div>
                </div>

                <!-- Active Domains -->
                <?php if (!empty($activations)): ?>
                    <div class="lsr-active-domains">
                        <h5><?php esc_html_e('Active Domains:', 'license-server'); ?></h5>
                        <ul class="lsr-domains-list">
                            <?php foreach ($activations as $activation): ?>
                                <li class="lsr-domain-item">
                                    <span class="lsr-domain-name"><?php echo esc_html($activation['domain']); ?></span>
                                    <span class="lsr-domain-date" title="<?php esc_attr_e('Last seen', 'license-server'); ?>">
                                        <?php echo esc_html(human_time_diff(strtotime($activation['last_seen_at']))); ?> <?php esc_html_e('ago', 'license-server'); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="lsr-license-actions">
                    
                    <!-- Renewal/Subscription Link -->
                    <?php if ($license['subscription_id'] && class_exists('WC_Subscriptions')): ?>
                        <?php $subscription = wcs_get_subscription($license['subscription_id']); ?>
                        <?php if ($subscription): ?>
                            <a href="<?php echo esc_url($subscription->get_view_order_url()); ?>" class="lsr-action-btn lsr-btn-subscription">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e('Manage Subscription', 'license-server'); ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Support/Documentation -->
                    <?php if ($product): ?>
                        <a href="<?php echo esc_url($product->get_permalink()); ?>" class="lsr-action-btn lsr-btn-product">
                            <span class="dashicons dashicons-book-alt"></span>
                            <?php esc_html_e('Product Page', 'license-server'); ?>
                        </a>
                    <?php endif; ?>

                    <!-- Download (if available) -->
                    <?php if ($status === 'active' || $status === 'grace'): ?>
                        <a href="#" class="lsr-action-btn lsr-btn-download" onclick="checkForUpdates('<?php echo esc_js($license['license_key']); ?>', this)">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Check Updates', 'license-server'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Status Messages -->
                <?php if ($status !== 'active'): ?>
                    <div class="lsr-license-message lsr-message-<?php echo esc_attr($status); ?>">
                        <?php
                        switch ($status) {
                            case 'expired':
                                if ($license['subscription_id']) {
                                    esc_html_e('Your subscription has expired. Renew to continue receiving updates.', 'license-server');
                                } else {
                                    esc_html_e('Your license has expired. Contact support for renewal options.', 'license-server');
                                }
                                break;
                            case 'inactive':
                                esc_html_e('Your license is currently inactive. Please contact support if you believe this is an error.', 'license-server');
                                break;
                            case 'revoked':
                                esc_html_e('This license has been revoked. Please contact support for more information.', 'license-server');
                                break;
                            case 'grace':
                                esc_html_e('Your license is in grace period. Updates are still available, but please renew soon.', 'license-server');
                                break;
                        }
                        ?>
                    </div>
                <?php elseif ($is_expiring_soon && $days_remaining): ?>
                    <div class="lsr-license-message lsr-message-expiring">
                        <?php printf(esc_html__('Your license expires in %d days. Consider renewing to avoid service interruption.', 'license-server'), $days_remaining); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Help Section -->
    <div class="lsr-licenses-help">
        <h4><?php esc_html_e('Need Help?', 'license-server'); ?></h4>
        <p><?php esc_html_e('If you\'re having trouble with your licenses or need assistance with installation, please don\'t hesitate to contact our support team.', 'license-server'); ?></p>
        
        <div class="lsr-help-links">
            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="button">
                <?php esc_html_e('My Account', 'license-server'); ?>
            </a>
            <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="button">
                <?php esc_html_e('Shop More Products', 'license-server'); ?>
            </a>
            <?php if (get_option('woocommerce_terms_page_id')): ?>
                <a href="<?php echo esc_url(get_permalink(get_option('woocommerce_terms_page_id'))); ?>" class="button button-secondary">
                    <?php esc_html_e('Terms & Conditions', 'license-server'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.lsr-account-licenses {
    margin: 20px 0;
}

.lsr-licenses-header {
    margin-bottom: 30px;
}

.lsr-licenses-header h3 {
    margin: 0 0 10px 0;
    font-size: 24px;
}

.lsr-licenses-intro {
    color: #666;
    margin: 0;
}

.lsr-licenses-grid {
    display: grid;
    gap: 20px;
    margin-bottom: 40px;
}

@media (min-width: 768px) {
    .lsr-licenses-grid {
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    }
}

.lsr-license-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: box-shadow 0.3s ease;
}

.lsr-license-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.lsr-license-card.lsr-expired {
    border-left: 4px solid #d63638;
}

.lsr-license-card.lsr-expiring-soon {
    border-left: 4px solid #dba617;
}

.lsr-license-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.lsr-license-product h4 {
    margin: 0 0 5px 0;
    font-size: 18px;
}

.lsr-license-status {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.lsr-status-active { background: #d1ecf1; color: #0c5460; }
.lsr-status-inactive { background: #f8d7da; color: #721c24; }
.lsr-status-expired { background: #fff3cd; color: #856404; }
.lsr-status-revoked { background: #721c24; color: white; }
.lsr-status-grace { background: #d4edda; color: #155724; }

.lsr-license-image img {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
}

.lsr-license-key {
    margin-bottom: 15px;
}

.lsr-license-key label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    color: #333;
}

.lsr-key-display {
    display: flex;
    align-items: center;
    gap: 5px;
}

.lsr-key-field {
    flex: 1;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #f9f9f9;
}

.lsr-copy-btn {
    background: #0073aa;
    color: white;
    border: none;
    padding: 8px;
    border-radius: 4px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.lsr-copy-btn:hover {
    background: #005a87;
}

.lsr-copy-btn .dashicons {
    font-size: 16px;
    line-height: 1;
}

.lsr-license-details {
    margin-bottom: 15px;
}

.lsr-license-detail {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    border-bottom: 1px solid #f0f0f0;
}

.lsr-license-detail:last-child {
    border-bottom: none;
}

.lsr-detail-label {
    font-weight: 600;
    color: #555;
}

.lsr-detail-value {
    text-align: right;
}

.lsr-days-remaining {
    display: block;
    font-size: 11px;
    color: #666;
    margin-top: 2px;
}

.lsr-days-remaining.lsr-expired {
    color: #d63638;
}

.lsr-expiration.lsr-expired .lsr-detail-value,
.lsr-expiration.lsr-expiring-soon .lsr-detail-value {
    color: #d63638;
    font-weight: bold;
}

.lsr-lifetime {
    color: #00a32a;
    font-weight: bold;
}

.lsr-limit-reached {
    color: #d63638;
    font-weight: bold;
}

.lsr-active-domains {
    margin-bottom: 15px;
    padding-top: 15px;
    border-top: 1px solid #f0f0f0;
}

.lsr-active-domains h5 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.lsr-domains-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.lsr-domain-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 10px;
    margin-bottom: 3px;
    background: #f8f9fa;
    border-radius: 4px;
    font-size: 13px;
}

.lsr-domain-name {
    font-weight: 500;
    font-family: monospace;
}

.lsr-domain-date {
    color: #666;
    font-size: 11px;
}

.lsr-license-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 15px;
}

.lsr-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    text-decoration: none;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.lsr-action-btn .dashicons {
    font-size: 14px;
}

.lsr-btn-subscription {
    background: #0073aa;
    color: white;
}

.lsr-btn-subscription:hover {
    background: #005a87;
    color: white;
}

.lsr-btn-product {
    background: #f0f0f0;
    color: #333;
    border-color: #ddd;
}

.lsr-btn-product:hover {
    background: #e0e0e0;
    color: #333;
}

.lsr-btn-download {
    background: #00a32a;
    color: white;
}

.lsr-btn-download:hover {
    background: #007f23;
    color: white;
}

.lsr-license-message {
    padding: 10px 15px;
    border-radius: 4px;
    font-size: 13px;
    line-height: 1.4;
}

.lsr-message-expired {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.lsr-message-inactive,
.lsr-message-revoked {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.lsr-message-grace {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.lsr-message-expiring {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.lsr-licenses-help {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
}

.lsr-licenses-help h4 {
    margin: 0 0 10px 0;
}

.lsr-licenses-help p {
    color: #666;
    margin: 0 0 15px 0;
}

.lsr-help-links {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
}

/* Copy success animation */
.lsr-copy-success {
    background: #00a32a !important;
}

.lsr-copy-success::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 16px;
}

/* Responsive */
@media (max-width: 767px) {
    .lsr-license-header {
        flex-direction: column;
        gap: 10px;
    }
    
    .lsr-license-detail {
        flex-direction: column;
        align-items: flex-start;
        gap: 2px;
    }
    
    .lsr-detail-value {
        text-align: left;
    }
    
    .lsr-help-links {
        flex-direction: column;
    }
}
</style>

<script>
function copyToClipboard(text, button) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            showCopySuccess(button);
        }).catch(function() {
            fallbackCopyToClipboard(text, button);
        });
    } else {
        fallbackCopyToClipboard(text, button);
    }
}

function fallbackCopyToClipboard(text, button) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showCopySuccess(button);
    } catch (err) {
        console.error('Failed to copy text: ', err);
        alert('<?php esc_js_e('Unable to copy to clipboard. Please copy manually.', 'license-server'); ?>');
    }
    
    document.body.removeChild(textArea);
}

function showCopySuccess(button) {
    const originalHTML = button.innerHTML;
    button.innerHTML = '<span class="dashicons dashicons-yes"></span>';
    button.classList.add('lsr-copy-success');
    
    setTimeout(function() {
        button.innerHTML = originalHTML;
        button.classList.remove('lsr-copy-success');
    }, 2000);
}

function checkForUpdates(licenseKey, button) {
    const originalHTML = button.innerHTML;
    button.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> <?php esc_js_e('Checking...', 'license-server'); ?>';
    button.style.pointerEvents = 'none';
    
    // This would make an AJAX call to check for updates
    // For now, just show a message
    setTimeout(function() {
        button.innerHTML = originalHTML;
        button.style.pointerEvents = 'auto';
        
        // Show result message
        const card = button.closest('.lsr-license-card');
        let resultDiv = card.querySelector('.lsr-update-result');
        if (!resultDiv) {
            resultDiv = document.createElement('div');
            resultDiv.className = 'lsr-update-result';
            card.appendChild(resultDiv);
        }
        
        resultDiv.innerHTML = '<div class="lsr-license-message" style="background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; margin-top: 10px;"><?php esc_js_e('Your plugin is up to date. Updates will be available through your WordPress admin when released.', 'license-server'); ?></div>';
        
        // Remove message after 5 seconds
        setTimeout(function() {
            if (resultDiv) {
                resultDiv.remove();
            }
        }, 5000);
        
    }, 2000);
}

// Add spin animation for loading states
const style = document.createElement('style');
style.textContent = `
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);
</script>

<?php

/**
 * Helper function to get detailed activation information
 */
function getDetailedActivations($license_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'lsr_activations';
    
    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT domain, activated_at, last_seen_at, validation_count 
             FROM {$table} 
             WHERE license_id = %d 
             ORDER BY last_seen_at DESC",
            $license_id
        ),
        ARRAY_A
    ) ?: [];
}