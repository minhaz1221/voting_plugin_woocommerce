<?php
/*
Plugin Name: Donation Vote 
Description: Sends one-time voting links after WooCommerce purchases. Tokens expire/consume on use. Vote buttons via shortcode, submissions tracking, admin CRUD, per-platform totals, and email templates.
Version: 1.0.0
Author: Nazmul Hassan Shuvo
*/

if (!defined('ABSPATH')) exit;

class DV_Donation_Vote_Plugin {
    const VERSION = '1.0.0';
    const OPT_KEY = 'dv_settings';
    const DB_TOKENS = 'dv_tokens';
    const DB_SUBMISSIONS = 'dv_submissions';
    const CPT_PLATFORM = 'dv_platform';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'register_cpt']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_platform_metabox']);

        add_shortcode('donation_vote_button', [$this, 'shortcode_vote_button']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);

        // AJAX vote
        add_action('wp_ajax_dv_cast_vote', [$this, 'ajax_cast_vote']);
        add_action('wp_ajax_nopriv_dv_cast_vote', [$this, 'ajax_cast_vote']);

        // Protect selected page: only via valid token
        add_action('template_redirect', [$this, 'protect_selected_page']);

        // Woo: generate & email token on order complete
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 10, 1);

        // Cleanup expired tokens on init (lightweight)
        add_action('init', [$this, 'cleanup_expired_tokens']);
    }

    /* ---------------- Activation / DB ---------------- */

    public function activate() {
        $this->create_tables();
        $this->maybe_seed_options();
        $this->register_cpt();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $tokens = $wpdb->prefix . self::DB_TOKENS;
        $subs   = $wpdb->prefix . self::DB_SUBMISSIONS;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql1 = "CREATE TABLE $tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY email (email),
            KEY order_id (order_id),
            KEY used (used),
            KEY expires_at (expires_at)
        ) $charset;";

        $sql2 = "CREATE TABLE $subs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            username VARCHAR(190) NULL,
            email VARCHAR(190) NOT NULL,
            platform_post_id BIGINT UNSIGNED NOT NULL,
            platform_name VARCHAR(190) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            token_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY email (email),
            KEY platform_post_id (platform_post_id),
            KEY order_id (order_id),
            KEY token_id (token_id)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
    }

    private function maybe_seed_options() {
        $opts = get_option(self::OPT_KEY);
        if (!$opts) {
            $opts = [
                'selected_page' => 0,
                'expiry_hours' => 24,
                'link_email_subject' => 'Your one-time voting link (Order #{order_id})',
                'link_email_body' => "Hi {customer_name},\n\nThanks for your purchase! Click the one-time link below to cast your vote:\n\n{link}\n\nThis link expires in {expiry_hours} hours and can only be used once.",
                'confirm_email_subject' => 'Vote received – thank you!',
                'confirm_email_body' => "Hi {customer_name},\n\nWe received your vote for \"{platform}\". Order #{order_id}.\n\nThanks!",
                'hide_selected_page' => 1,
                'notification_email_enabled' => 0,
                'notification_email_recipient' => '',
                'notification_email_subject' => 'New Vote Submission - Order #{order_id}',
                'notification_email_body' => "New vote submission received:\n\nCustomer: {customer_name}\nEmail: {customer_email}\nPlatform: {platform}\nOrder ID: {order_id}\nSubmission Date: {submission_date}\n\nThis is an automated notification.",
            ];
            add_option(self::OPT_KEY, $opts);
        }
    }

    /* ---------------- Post Type ---------------- */

    public function register_cpt() {
        $labels = [
            'name' => 'Donation Posts',
            'singular_name' => 'Donation Post',
            'menu_name' => 'Donation Posts',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Donation Post',
            'edit_item' => 'Edit Donation Post',
            'new_item' => 'New Donation Post',
            'view_item' => 'View Donation Post',
            'all_items' => 'All Donation Posts',
            'search_items' => 'Search Donation Posts',
        ];
        register_post_type(self::CPT_PLATFORM, [
            'labels' => $labels,
            'public' => true,
            'show_in_menu' => false, // We will link it under Donation menu
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
            'has_archive' => true,
            'rewrite' => ['slug' => 'donation-post'],
        ]);
    }

    public function add_platform_metabox() {
        add_meta_box('dv_shortcode', 'Vote Shortcode', function ($post) {
            $shortcode = '[donation_vote_button id="' . esc_attr($post->ID) . '" label="Vote"]';
            echo '<p>Use this shortcode to place a Vote button for this platform:</p>';
            echo '<code style="user-select:all;">' . esc_html($shortcode) . '</code>';
            echo '<p style="margin-top:8px;color:#666;">The button requires a valid one-time token in the page URL to render and submit.</p>';
        }, self::CPT_PLATFORM, 'side', 'high');
    }

    /* ---------------- Admin Menu ---------------- */

    public function admin_menu() {
        $cap = 'manage_options';
        add_menu_page('Donation', 'Donation', $cap, 'dv_donation_root', [$this, 'page_submissions'], 'dashicons-heart', 56);

        add_submenu_page('dv_donation_root', 'Submissions', 'Submissions', $cap, 'dv_donation_root', [$this, 'page_submissions']);
        add_submenu_page('dv_donation_root', 'All Submissions', 'All Submissions', $cap, 'dv_all_submissions', [$this, 'page_all_submissions']);
        add_submenu_page('dv_donation_root', 'Posts', 'Posts', $cap, 'edit.php?post_type=' . self::CPT_PLATFORM);
        add_submenu_page('dv_donation_root', 'Tokens', 'Tokens', $cap, 'dv_tokens', [$this, 'page_tokens']);
        add_submenu_page('dv_donation_root', 'Settings', 'Settings', $cap, 'dv_settings', [$this, 'page_settings']);
    }

    /* ---------------- Settings ---------------- */

    public function register_settings() {
        register_setting('dv_settings_group', self::OPT_KEY, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($in) {
        $out = get_option(self::OPT_KEY, []);
        $out['selected_page'] = isset($in['selected_page']) ? absint($in['selected_page']) : 0;
        $out['expiry_hours'] = isset($in['expiry_hours']) ? max(1, absint($in['expiry_hours'])) : 24;
        $out['link_email_subject'] = sanitize_text_field($in['link_email_subject'] ?? '');
        $out['link_email_body'] = wp_kses_post($in['link_email_body'] ?? '');
        $out['confirm_email_subject'] = sanitize_text_field($in['confirm_email_subject'] ?? '');
        $out['confirm_email_body'] = wp_kses_post($in['confirm_email_body'] ?? '');
        $out['hide_selected_page'] = !empty($in['hide_selected_page']) ? 1 : 0;
        $out['notification_email_enabled'] = !empty($in['notification_email_enabled']) ? 1 : 0;
        $out['notification_email_recipient'] = sanitize_email($in['notification_email_recipient'] ?? '');
        $out['notification_email_subject'] = sanitize_text_field($in['notification_email_subject'] ?? '');
        $out['notification_email_body'] = wp_kses_post($in['notification_email_body'] ?? '');
        return $out;
    }

    public function page_settings() {
        if (!current_user_can('manage_options')) return;
        $opts = get_option(self::OPT_KEY, []);
        ?>
        <div class="wrap">
            <h1>Donation Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('dv_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>Select Vote Page</label></th>
                        <td>
                            <?php wp_dropdown_pages([
                                'name' => self::OPT_KEY.'[selected_page]',
                                'selected' => $opts['selected_page'] ?? 0,
                                'show_option_none' => '— Select —',
                            ]); ?>
                            <p class="description">This page will only render when visited via a valid one-time token link.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Link Expiry (hours)</label></th>
                        <td><input type="number" min="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[expiry_hours]" value="<?php echo esc_attr($opts['expiry_hours'] ?? 24); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Hide Selected Page Publicly</label></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[hide_selected_page]" value="1" <?php checked(1, $opts['hide_selected_page']??0); ?> /> Hide content unless accessed with token</label>
                        </td>
                    </tr>
                    <tr><th><h2>Email: One-Time Link</h2></th><td></td></tr>
                    <tr>
                        <th scope="row"><label>Subject</label></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[link_email_subject]" value="<?php echo esc_attr($opts['link_email_subject'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Body</label></th>
                        <td>
<textarea name="<?php echo esc_attr(self::OPT_KEY); ?>[link_email_body]" rows="6" class="large-text"><?php echo esc_textarea($opts['link_email_body'] ?? ''); ?></textarea>
<p class="description">Placeholders: {customer_name}, {order_id}, {link}, {expiry_hours}</p>
                        </td>
                    </tr>
                    <tr><th><h2>Email: Confirmation (after Vote)</h2></th><td></td></tr>
                    <tr>
                        <th scope="row"><label>Subject</label></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[confirm_email_subject]" value="<?php echo esc_attr($opts['confirm_email_subject'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Body</label></th>
                        <td>
<textarea name="<?php echo esc_attr(self::OPT_KEY); ?>[confirm_email_body]" rows="6" class="large-text"><?php echo esc_textarea($opts['confirm_email_body'] ?? ''); ?></textarea>
<p class="description">Placeholders: {customer_name}, {platform}, {order_id}</p>
                        </td>
                    </tr>
                    <tr><th><h2>Email: Admin Notification (New Vote)</h2></th><td></td></tr>
                    <tr>
                        <th scope="row"><label>Enable Notifications</label></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPT_KEY); ?>[notification_email_enabled]" value="1" <?php checked(1, $opts['notification_email_enabled']??0); ?> /> Send email notification when a vote is submitted</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Recipient Email</label></th>
                        <td><input type="email" class="regular-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[notification_email_recipient]" value="<?php echo esc_attr($opts['notification_email_recipient'] ?? ''); ?>" />
                        <p class="description">Leave empty to use admin email (<?php echo esc_html(get_option('admin_email')); ?>)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Subject</label></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_KEY); ?>[notification_email_subject]" value="<?php echo esc_attr($opts['notification_email_subject'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Body</label></th>
                        <td>
<textarea name="<?php echo esc_attr(self::OPT_KEY); ?>[notification_email_body]" rows="6" class="large-text"><?php echo esc_textarea($opts['notification_email_body'] ?? ''); ?></textarea>
<p class="description">Placeholders: {customer_name}, {customer_email}, {platform}, {order_id}, {submission_date}</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ---------------- Protect Selected Page ---------------- */

    public function protect_selected_page() {
        $opts = get_option(self::OPT_KEY, []);
        $page_id = intval($opts['selected_page'] ?? 0);
        if (!$page_id) return;

        if (is_page($page_id)) {
            // If hide is enabled, only show content when token valid
            $token = isset($_GET['don_token']) ? sanitize_text_field($_GET['don_token']) : '';
            $valid = $this->is_token_valid($token);
            if (!empty($opts['hide_selected_page']) && !$valid) {
                // Replace content
                add_filter('the_content', function($content) {
                    return '<div class="dv-protected"><p>This page is available only through a valid one-time link.</p></div>';
                }, 999);
            }
            // Pass token to frontend for vote submissions
            if ($valid) {
                add_filter('the_content', function($content) use ($token) {
                    $hidden = '<input type="hidden" id="dv_token" value="'.esc_attr($token).'" />';
                    return $hidden . $content;
                }, 1);
            }
        }
    }

    private function is_token_valid($token) {
        if (!$token) return false;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}".self::DB_TOKENS." WHERE token=%s LIMIT 1",
            $token
        ));
        if (!$row) return false;
        if (intval($row->used) === 1) return false;
        $now = current_time('mysql');
        return (strtotime($row->expires_at) > strtotime($now));
    }

    private function mark_token_used($token) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::DB_TOKENS,
            ['used' => 1, 'used_at' => current_time('mysql')],
            ['token' => $token],
            ['%d', '%s'],
            ['%s']
        );
    }

    /* ---------------- Frontend Shortcode & Assets ---------------- */

    public function enqueue_front() {
        wp_register_script('dv_vote', plugins_url('dv-vote.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('dv_vote', 'DVVote', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dv_vote'),
        ]);
        wp_enqueue_script('dv_vote');
        wp_register_style('dv_vote_css', plugins_url('dv-vote.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('dv_vote_css');
    }

    public function shortcode_vote_button($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'label' => 'Vote',
        ], $atts, 'donation_vote_button');

        $platform_id = absint($atts['id']);
        if (!$platform_id) return '<em>Vote button not configured.</em>';

        // Only render if a valid token is present on the page
        $token = isset($_GET['don_token']) ? sanitize_text_field($_GET['don_token']) : '';
        if (!$this->is_token_valid($token)) {
            return '<div class="dv-token-required">This action requires a valid one-time link.</div>';
        }

        $btn = sprintf(
            '<form class="dv-vote-form" data-platform="%d">
                <input type="hidden" name="action" value="dv_cast_vote" />
                <input type="hidden" name="platform_id" value="%d" />
                <input type="hidden" name="token" value="%s" />
                <input type="hidden" name="nonce" value="%s" />
                <button type="submit" class="dv-vote-btn">%s</button>
                <div class="dv-vote-msg" style="margin-top:8px;"></div>
            </form>',
            $platform_id, $platform_id, esc_attr($token), esc_attr(wp_create_nonce('dv_vote')), esc_html($atts['label'])
        );

        return '<div class="dv-vote-wrap">'.$btn.'</div>';
    }

    /* ---------------- AJAX: Cast Vote ---------------- */

    public function ajax_cast_vote() {
        check_ajax_referer('dv_vote', 'nonce');
        $platform_id = isset($_POST['platform_id']) ? absint($_POST['platform_id']) : 0;
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        if (!$platform_id || !$token) {
            wp_send_json_error(['message' => 'Invalid request.']);
        }

        // Validate token
        global $wpdb;
        $tokens_table = $wpdb->prefix . self::DB_TOKENS;
        $token_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tokens_table WHERE token=%s LIMIT 1", $token));
        if (!$token_row) wp_send_json_error(['message' => 'Invalid token.']);
        if (intval($token_row->used) === 1) wp_send_json_error(['message' => 'This link has already been used.']);
        if (strtotime($token_row->expires_at) <= time()) wp_send_json_error(['message' => 'This link has expired.']);

        // Validate platform
        $platform = get_post($platform_id);
        if (!$platform || $platform->post_type !== self::CPT_PLATFORM || $platform->post_status !== 'publish') {
            wp_send_json_error(['message' => 'Invalid platform.']);
        }

        // Record submission
        $subs_table = $wpdb->prefix . self::DB_SUBMISSIONS;
        $current_user = wp_get_current_user();
        $user_id = ($current_user && $current_user->ID) ? $current_user->ID : null;

        $username = $current_user && $current_user->display_name ? $current_user->display_name : '';
        $email = sanitize_email($token_row->email);
        $order_id = intval($token_row->order_id);
        $platform_name = get_the_title($platform_id);

        $wpdb->insert($subs_table, [
            'user_id' => $user_id ? $user_id : null,
            'username' => $username,
            'email' => $email,
            'platform_post_id' => $platform_id,
            'platform_name' => $platform_name,
            'order_id' => $order_id,
            'token_id' => intval($token_row->id),
            'created_at' => current_time('mysql'),
        ], ['%d','%s','%s','%d','%s','%d','%d','%s']);

        // Mark token used
        $this->mark_token_used($token);

        // Send confirmation to customer
        $this->send_confirmation_email($email, [
            'customer_name' => $username ?: $email,
            'platform' => $platform_name,
            'order_id' => $order_id,
        ]);

        // Send notification to admin
        $this->send_notification_email([
            'customer_name' => $username ?: $email,
            'customer_email' => $email,
            'platform' => $platform_name,
            'order_id' => $order_id,
            'submission_date' => current_time('mysql'),
        ]);

        wp_send_json_success(['message' => 'Your vote has been recorded. Thank you! This link can\'t be used again.']);
    }

    private function send_confirmation_email($to, $data) {
        $opts = get_option(self::OPT_KEY, []);
        $subject = $opts['confirm_email_subject'] ?? 'Vote received';
        $body = $opts['confirm_email_body'] ?? 'Thanks for your vote.';
        $repl = [
            '{customer_name}' => $data['customer_name'] ?? '',
            '{platform}' => $data['platform'] ?? '',
            '{order_id}' => $data['order_id'] ?? '',
        ];
        $subject = strtr($subject, $repl);
        $body = strtr($body, $repl);
        wp_mail($to, $subject, $body);
    }

    private function send_notification_email($data) {
        $opts = get_option(self::OPT_KEY, []);
        
        // Check if notifications are enabled
        if (empty($opts['notification_email_enabled'])) {
            return;
        }

        $to = !empty($opts['notification_email_recipient']) ? 
              sanitize_email($opts['notification_email_recipient']) : 
              get_option('admin_email');
        
        $subject = $opts['notification_email_subject'] ?? 'New Vote Submission - Order #{order_id}';
        $body = $opts['notification_email_body'] ?? "New vote submission received:\n\nCustomer: {customer_name}\nEmail: {customer_email}\nPlatform: {platform}\nOrder ID: {order_id}\nSubmission Date: {submission_date}\n\nThis is an automated notification.";

        $repl = [
            '{customer_name}' => $data['customer_name'] ?? '',
            '{customer_email}' => $data['customer_email'] ?? '',
            '{platform}' => $data['platform'] ?? '',
            '{order_id}' => $data['order_id'] ?? '',
            '{submission_date}' => $data['submission_date'] ?? '',
        ];
        
        $subject = strtr($subject, $repl);
        $body = strtr($body, $repl);
        
        wp_mail($to, $subject, $body);
    }

    /* ---------------- Woo: Create & Email Token ---------------- */

    public function on_order_completed($order_id) {
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        $email = $order->get_billing_email();
        $name  = trim($order->get_formatted_billing_full_name());
        if (!$email) return;

        $token = $this->create_token($email, $order_id);
        if (!$token) return;

        $opts = get_option(self::OPT_KEY, []);
        $page_id = intval($opts['selected_page'] ?? 0);
        $expiry_hours = intval($opts['expiry_hours'] ?? 24);

        $link = $this->build_token_link($token, $page_id);

        $subject = $opts['link_email_subject'] ?? 'Your one-time voting link';
        $body = $opts['link_email_body'] ?? "Hi {customer_name},\n\n{link}";

        $repl = [
            '{customer_name}' => $name ?: $email,
            '{order_id}' => $order_id,
            '{link}' => $link,
            '{expiry_hours}' => $expiry_hours,
        ];
        $subject = strtr($subject, $repl);
        $body = strtr($body, $repl);

        wp_mail($email, $subject, $body);
    }

    private function create_token($email, $order_id) {
        global $wpdb;
        $tokens_table = $wpdb->prefix . self::DB_TOKENS;
        $opts = get_option(self::OPT_KEY, []);
        $expiry_hours = intval($opts['expiry_hours'] ?? 24);
        $expires_at = gmdate('Y-m-d H:i:s', time() + ($expiry_hours * 3600));
        $created_at = current_time('mysql');

        // use secure random
        $token = bin2hex(random_bytes(16));

        $res = $wpdb->insert($tokens_table, [
            'email' => sanitize_email($email),
            'order_id' => intval($order_id),
            'token' => $token,
            'used' => 0,
            'created_at' => $created_at,
            'expires_at' => get_date_from_gmt($expires_at), // store in WP local time
            'used_at' => null
        ], ['%s','%d','%s','%d','%s','%s','%s']);

        if ($res) return $token;
        return false;
    }

    private function build_token_link($token, $page_id) {
        if ($page_id && get_permalink($page_id)) {
            return add_query_arg('don_token', $token, get_permalink($page_id));
        }
        // fallback to home if no page selected
        return add_query_arg('don_token', $token, home_url('/'));
    }

    public function cleanup_expired_tokens() {
        global $wpdb;
        $tokens_table = $wpdb->prefix . self::DB_TOKENS;
        // nothing to delete: we just keep them but they won't validate. Optional: mark used=1 on expiration?
        // For now, no destructive cleanup to keep audit trail.
    }

    /* ---------------- Admin Pages ---------------- */

    public function enqueue_admin($hook) {
        // basic styles
        wp_register_style('dv_admin', plugins_url('dv-admin.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('dv_admin');
    }

    /* Submissions listing + CRUD */
    public function page_submissions() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = $wpdb->prefix . self::DB_SUBMISSIONS;

        // Handle actions
        if (isset($_POST['dv_action']) && check_admin_referer('dv_submissions')) {
            $act = sanitize_text_field($_POST['dv_action']);
            if ($act === 'add' || $act === 'edit') {
                $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
                $data = [
                    'username' => sanitize_text_field($_POST['username'] ?? ''),
                    'email' => sanitize_email($_POST['email'] ?? ''),
                    'platform_post_id' => absint($_POST['platform_post_id'] ?? 0),
                    'platform_name' => sanitize_text_field($_POST['platform_name'] ?? ''),
                    'order_id' => absint($_POST['order_id'] ?? 0),
                ];
                if ($act === 'add') {
                    $data['created_at'] = current_time('mysql');
                    $data['token_id'] = 0;
                    $data['user_id'] = 0;
                    $wpdb->insert($table, $data);
                    echo '<div class="updated notice"><p>Submission added.</p></div>';
                } else {
                    $wpdb->update($table, $data, ['id' => $id]);
                    echo '<div class="updated notice"><p>Submission updated.</p></div>';
                }
            } elseif ($act === 'delete') {
                $id = absint($_POST['id'] ?? 0);
                $wpdb->delete($table, ['id' => $id]);
                echo '<div class="updated notice"><p>Submission deleted.</p></div>';
            }
        }

        // Edit view?
        $edit_id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $edit_row = null;
        if ($edit_id) {
            $edit_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $edit_id));
        }

        // List
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 500");

        ?>
        <div class="wrap">
            <h1>Submissions</h1>

            <h2><?php echo $edit_row ? 'Edit Submission' : 'Add Submission'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('dv_submissions'); ?>
                <input type="hidden" name="dv_action" value="<?php echo $edit_row ? 'edit' : 'add'; ?>">
                <?php if ($edit_row): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($edit_row->id); ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr><th>Username</th><td><input type="text" name="username" value="<?php echo esc_attr($edit_row->username ?? ''); ?>"></td></tr>
                    <tr><th>Gmail (Email)</th><td><input type="email" name="email" value="<?php echo esc_attr($edit_row->email ?? ''); ?>"></td></tr>
                    <tr><th>Submission Platform Name</th><td><input type="text" name="platform_name" value="<?php echo esc_attr($edit_row->platform_name ?? ''); ?>"></td></tr>
                    <tr><th>Platform Post</th><td>
                        <?php
                        wp_dropdown_pages([
                            'post_type' => self::CPT_PLATFORM,
                            'name' => 'platform_post_id',
                            'selected' => intval($edit_row->platform_post_id ?? 0),
                            'show_option_none' => '— Select —',
                        ]);
                        ?>
                    </td></tr>
                    <tr><th>Order ID</th><td><input type="number" name="order_id" value="<?php echo esc_attr($edit_row->order_id ?? ''); ?>"></td></tr>
                </table>
                <?php submit_button($edit_row ? 'Update Submission' : 'Add Submission'); ?>
            </form>

            <hr />

            <h2>All Submissions (latest 500)</h2>
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th>Username</th><th>Email</th><th>Platform</th><th>Order ID</th><th>Date</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->id); ?></td>
                        <td><?php echo esc_html($r->username); ?></td>
                        <td><?php echo esc_html($r->email); ?></td>
                        <td><?php echo esc_html($r->platform_name).' (ID '.$r->platform_post_id.')'; ?></td>
                        <td><?php echo esc_html($r->order_id); ?></td>
                        <td><?php echo esc_html($r->created_at); ?></td>
                        <td>
                            <a class="button" href="<?php echo esc_url(add_query_arg(['edit'=>$r->id])); ?>">Edit</a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete submission #<?php echo $r->id; ?>?');">
                                <?php wp_nonce_field('dv_submissions'); ?>
                                <input type="hidden" name="dv_action" value="delete">
                                <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                                <button class="button button-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7">No submissions yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* Totals per platform */
    public function page_all_submissions() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = $wpdb->prefix . self::DB_SUBMISSIONS;
        $rows = $wpdb->get_results("SELECT platform_post_id, platform_name, COUNT(*) as votes FROM $table GROUP BY platform_post_id, platform_name ORDER BY votes DESC");
        ?>
        <div class="wrap">
            <h1>All Submission Totals</h1>
            <table class="widefat striped">
                <thead><tr><th>Platform Name</th><th>Platform ID</th><th>Votes</th></tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->platform_name); ?></td>
                        <td><?php echo esc_html($r->platform_post_id); ?></td>
                        <td><strong><?php echo intval($r->votes); ?></strong></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="3">No submissions yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* Tokens listing */
    public function page_tokens() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = $wpdb->prefix . self::DB_TOKENS;
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 500");
        ?>
        <div class="wrap">
            <h1>Tokens</h1>
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th>Email</th><th>Order ID</th><th>Token</th><th>Used</th><th>Created</th><th>Expires</th><th>Used At</th>
                </tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->id); ?></td>
                        <td><?php echo esc_html($r->email); ?></td>
                        <td><?php echo esc_html($r->order_id); ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html($r->token); ?></code></td>
                        <td><?php echo intval($r->used) ? 'Yes' : 'No'; ?></td>
                        <td><?php echo esc_html($r->created_at); ?></td>
                        <td><?php echo esc_html($r->expires_at); ?></td>
                        <td><?php echo esc_html($r->used_at ?: '-'); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8">No tokens yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

new DV_Donation_Vote_Plugin();

// CSS & JS as embedded files (would be separate in real plugin)
function dv_output_css_js() {
    // dv-vote.css
    echo '<style id="dv-vote-css">
.dv-vote-wrap { margin: 1em 0; }
.dv-vote-btn { background: #4CAF50; color: white; border: none; padding: 10px 20px; cursor: pointer; border-radius: 4px; }
.dv-vote-btn:hover { background: #45a049; }
.dv-vote-msg { font-weight: bold; }
.dv-vote-msg.success { color: green; }
.dv-vote-msg.error { color: red; }
.dv-token-required { color: #666; font-style: italic; }
.dv-protected { text-align: center; padding: 2em; color: #666; }
</style>';

    // dv-vote.js
    echo '<script id="dv-vote-js">
jQuery(function($) {
    $(".dv-vote-form").on("submit", function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find(".dv-vote-btn");
        var $msg = $form.find(".dv-vote-msg");
        $btn.prop("disabled", true).text("Submitting...");
        $msg.removeClass("success error").text("");
        $.post(DVVote.ajaxurl, $form.serialize(), function(resp) {
            if (resp.success) {
                $msg.addClass("success").text(resp.data.message);
                $btn.hide();
            } else {
                $msg.addClass("error").text(resp.data.message || "Error");
                $btn.prop("disabled", false).text($btn.data("orig-text") || "Vote");
            }
        }).fail(function() {
            $msg.addClass("error").text("Network error");
            $btn.prop("disabled", false).text($btn.data("orig-text") || "Vote");
        });
    });
});
</script>';

    // dv-admin.css
    echo '<style id="dv-admin-css">
.wrap h1 { color: #23282d; }
.wrap table.widefat { margin-top: 1em; }
.button-danger { color: #a00; border-color: #a00; }
.button-danger:hover { color: #fff; background: #a00; border-color: #a00; }
</style>';
}
add_action('wp_head', 'dv_output_css_js');
add_action('admin_head', 'dv_output_css_js');
?>