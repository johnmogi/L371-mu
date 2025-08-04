<?php
/**
 * Plugin Name: WooCommerce LearnDash Access Manager
 * Description: Adds custom access duration fields to WooCommerce products and automatically manages LearnDash course access
 * Version: 1.0.0
 * Author: LILAC Development
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_LearnDash_Access_Manager {
    
    private $access_options = [
        'paused_2weeks' => 'Paused Subscription (2 weeks access once activated)',
        'trial_2weeks' => '2 Weeks Trial',
        'access_1month' => '1 Month Access',
        'access_1year' => '1 Year Access'
    ];
    
    public function __construct() {
        add_action('init', [$this, 'init']);
    }
    
    public function init() {
        // Add product fields
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_custom_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_fields']);
        
        // Handle order completion
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_completion']);
        add_action('woocommerce_payment_complete', [$this, 'handle_payment_completion']);
        
        // Add admin scripts
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        
        // Add custom columns to product list
        add_filter('manage_edit-product_columns', [$this, 'add_product_columns']);
        add_action('manage_product_posts_custom_column', [$this, 'show_product_columns'], 10, 2);
        
        // Add order meta display
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'display_order_access_info']);
        
        // Add user profile fields
        add_action('show_user_profile', [$this, 'show_user_access_fields']);
        add_action('edit_user_profile', [$this, 'show_user_access_fields']);
    }
    
    /**
     * Add custom fields to product edit page
     */
    public function add_custom_fields() {
        global $post;
        
        echo '<div class="options_group">';
        
        // Access Duration Type
        woocommerce_wp_select([
            'id' => '_learndash_access_duration',
            'label' => __('LearnDash Access Duration', 'wc-learndash'),
            'description' => __('Select the access duration for this product', 'wc-learndash'),
            'desc_tip' => true,
            'options' => ['' => __('No LearnDash Access', 'wc-learndash')] + $this->access_options
        ]);
        
        // Custom End Date (optional override)
        woocommerce_wp_text_input([
            'id' => '_learndash_custom_end_date',
            'label' => __('Custom End Date', 'wc-learndash'),
            'description' => __('Optional: Set a specific end date (YYYY-MM-DD). Overrides duration setting.', 'wc-learndash'),
            'desc_tip' => true,
            'type' => 'date'
        ]);
        
        // Associated LearnDash Courses
        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ]);
        
        $course_options = ['' => __('Select Courses', 'wc-learndash')];
        foreach ($courses as $course) {
            $course_options[$course->ID] = $course->post_title;
        }
        
        woocommerce_wp_select([
            'id' => '_learndash_courses',
            'label' => __('Associated LearnDash Courses', 'wc-learndash'),
            'description' => __('Select which courses this product grants access to', 'wc-learndash'),
            'desc_tip' => true,
            'options' => $course_options,
            'custom_attributes' => ['multiple' => 'multiple']
        ]);
        
        // Display current access info if available
        $current_duration = get_post_meta($post->ID, '_learndash_access_duration', true);
        $current_courses = get_post_meta($post->ID, '_learndash_courses', true);
        
        if ($current_duration || $current_courses) {
            echo '<div class="form-field">';
            echo '<label><strong>' . __('Current Settings:', 'wc-learndash') . '</strong></label>';
            echo '<p>';
            if ($current_duration) {
                echo '<strong>Duration:</strong> ' . ($this->access_options[$current_duration] ?? $current_duration) . '<br>';
            }
            if ($current_courses) {
                $course_titles = [];
                foreach ((array)$current_courses as $course_id) {
                    $course_titles[] = get_the_title($course_id);
                }
                echo '<strong>Courses:</strong> ' . implode(', ', $course_titles);
            }
            echo '</p>';
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Save custom fields
     */
    public function save_custom_fields($post_id) {
        $duration = sanitize_text_field($_POST['_learndash_access_duration'] ?? '');
        $custom_date = sanitize_text_field($_POST['_learndash_custom_end_date'] ?? '');
        $courses = array_map('intval', $_POST['_learndash_courses'] ?? []);
        
        update_post_meta($post_id, '_learndash_access_duration', $duration);
        update_post_meta($post_id, '_learndash_custom_end_date', $custom_date);
        update_post_meta($post_id, '_learndash_courses', $courses);
        
        // Log the save action
        error_log("WC LearnDash: Saved product {$post_id} - Duration: {$duration}, Courses: " . implode(',', $courses));
    }
    
    /**
     * Handle order completion
     */
    public function handle_order_completion($order_id) {
        $this->process_learndash_access($order_id);
    }
    
    /**
     * Handle payment completion
     */
    public function handle_payment_completion($order_id) {
        $this->process_learndash_access($order_id);
    }
    
    /**
     * Process LearnDash access for completed orders
     */
    private function process_learndash_access($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $user_id = $order->get_user_id();
        if (!$user_id) return;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $duration = get_post_meta($product_id, '_learndash_access_duration', true);
            $custom_date = get_post_meta($product_id, '_learndash_custom_end_date', true);
            $courses = get_post_meta($product_id, '_learndash_courses', true);
            
            if (!$duration && !$custom_date) continue;
            
            // Calculate end date
            $end_date = $this->calculate_end_date($duration, $custom_date);
            
            // Enroll user in courses
            if ($courses && is_array($courses)) {
                foreach ($courses as $course_id) {
                    $this->enroll_user_in_course($user_id, $course_id, $end_date, $order_id);
                }
            }
        }
    }
    
    /**
     * Calculate end date based on duration or custom date
     */
    private function calculate_end_date($duration, $custom_date = '') {
        if ($custom_date) {
            return strtotime($custom_date . ' 23:59:59');
        }
        
        $current_time = current_time('timestamp');
        
        switch ($duration) {
            case 'paused_2weeks':
            case 'trial_2weeks':
                return strtotime('+2 weeks', $current_time);
            case 'access_1month':
                return strtotime('+1 month', $current_time);
            case 'access_1year':
                return strtotime('+1 year', $current_time);
            default:
                return 0; // No expiration
        }
    }
    
    /**
     * Enroll user in LearnDash course with access control
     */
    private function enroll_user_in_course($user_id, $course_id, $end_date, $order_id) {
        // Use LearnDash function to enroll user
        if (function_exists('ld_update_course_access')) {
            ld_update_course_access($user_id, $course_id, false);
        }
        
        // Set custom access metadata
        $access_key = "course_{$course_id}_access_from";
        $expire_key = "course_{$course_id}_access_expires";
        
        update_user_meta($user_id, $access_key, current_time('timestamp'));
        
        if ($end_date > 0) {
            update_user_meta($user_id, $expire_key, $end_date);
        }
        
        // Store order reference
        update_user_meta($user_id, "course_{$course_id}_order_id", $order_id);
        
        // Log enrollment
        error_log("WC LearnDash: Enrolled user {$user_id} in course {$course_id}, expires: " . date('Y-m-d H:i:s', $end_date));
        
        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $course_title = get_the_title($course_id);
            $expire_text = $end_date > 0 ? ' (expires: ' . date('Y-m-d', $end_date) . ')' : ' (no expiration)';
            $order->add_order_note("LearnDash: Enrolled in course '{$course_title}'{$expire_text}");
        }
    }
    
    /**
     * Add admin scripts for enhanced UI
     */
    public function admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            global $post;
            if ($post && $post->post_type === 'product') {
                wp_enqueue_script('wc-learndash-admin', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], '1.0.0', true);
                wp_enqueue_style('wc-learndash-admin', plugin_dir_url(__FILE__) . 'admin.css', [], '1.0.0');
            }
        }
    }
    
    /**
     * Add custom columns to product list
     */
    public function add_product_columns($columns) {
        $columns['learndash_access'] = __('LearnDash Access', 'wc-learndash');
        return $columns;
    }
    
    /**
     * Show custom columns content
     */
    public function show_product_columns($column, $post_id) {
        if ($column === 'learndash_access') {
            $duration = get_post_meta($post_id, '_learndash_access_duration', true);
            $courses = get_post_meta($post_id, '_learndash_courses', true);
            
            if ($duration) {
                echo '<strong>' . ($this->access_options[$duration] ?? $duration) . '</strong><br>';
            }
            
            if ($courses && is_array($courses)) {
                echo '<small>' . count($courses) . ' course(s)</small>';
            } else {
                echo '<small>No access configured</small>';
            }
        }
    }
    
    /**
     * Display access info in order admin
     */
    public function display_order_access_info($order) {
        $user_id = $order->get_user_id();
        if (!$user_id) return;
        
        echo '<div class="address">';
        echo '<p><strong>' . __('LearnDash Course Access:', 'wc-learndash') . '</strong></p>';
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $courses = get_post_meta($product_id, '_learndash_courses', true);
            
            if ($courses && is_array($courses)) {
                foreach ($courses as $course_id) {
                    $expire_key = "course_{$course_id}_access_expires";
                    $expires = get_user_meta($user_id, $expire_key, true);
                    $course_title = get_the_title($course_id);
                    
                    echo '<p>';
                    echo '<strong>' . $course_title . '</strong><br>';
                    if ($expires) {
                        echo 'Expires: ' . date('Y-m-d H:i:s', $expires);
                    } else {
                        echo 'No expiration';
                    }
                    echo '</p>';
                }
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Show user access fields in profile
     */
    public function show_user_access_fields($user) {
        echo '<h3>' . __('LearnDash Course Access', 'wc-learndash') . '</h3>';
        echo '<table class="form-table">';
        
        // Get all user's course access
        $user_meta = get_user_meta($user->ID);
        $course_access = [];
        
        foreach ($user_meta as $key => $value) {
            if (preg_match('/^course_(\d+)_access_expires$/', $key, $matches)) {
                $course_id = $matches[1];
                $course_access[$course_id] = [
                    'expires' => $value[0],
                    'title' => get_the_title($course_id)
                ];
            }
        }
        
        if (!empty($course_access)) {
            foreach ($course_access as $course_id => $data) {
                echo '<tr>';
                echo '<th><label>' . $data['title'] . '</label></th>';
                echo '<td>';
                if ($data['expires']) {
                    $expires_date = date('Y-m-d H:i:s', $data['expires']);
                    $is_expired = $data['expires'] < current_time('timestamp');
                    $status = $is_expired ? '<span style="color: red;">Expired</span>' : '<span style="color: green;">Active</span>';
                    echo "Expires: {$expires_date} - {$status}";
                } else {
                    echo '<span style="color: green;">No expiration</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="2">No course access found</td></tr>';
        }
        
        echo '</table>';
    }
}

// Initialize the plugin
new WC_LearnDash_Access_Manager();

/**
 * Helper function to check if user has access to course
 */
function wc_learndash_user_has_course_access($user_id, $course_id) {
    $expire_key = "course_{$course_id}_access_expires";
    $expires = get_user_meta($user_id, $expire_key, true);
    
    if (!$expires) {
        return true; // No expiration set
    }
    
    return $expires > current_time('timestamp');
}

/**
 * Helper function to get user's course access end date
 */
function wc_learndash_get_course_access_end_date($user_id, $course_id) {
    $expire_key = "course_{$course_id}_access_expires";
    return get_user_meta($user_id, $expire_key, true);
}
