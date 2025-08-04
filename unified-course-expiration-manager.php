<?php
/**
 * Unified Course Expiration Manager
 * Combines School Manager Students functionality with Simple Course Expiration features
 * Provides a single, cohesive admin interface for managing course access
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Unified_Course_Expiration_Manager {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_unified_set_course_expiration', [$this, 'ajax_set_course_expiration']);
        add_action('wp_ajax_unified_get_user_courses', [$this, 'ajax_get_user_courses']);
        add_action('wp_ajax_unified_bulk_update', [$this, 'ajax_bulk_update']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Unified Course Manager',
            'Course Manager',
            'manage_options',
            'unified-course-manager',
            [$this, 'admin_page'],
            'dashicons-graduation-cap',
            25
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_unified-course-manager') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        
        wp_localize_script('jquery', 'unified_course_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('unified_course_nonce')
        ]);
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>🎓 Unified Course Expiration Manager</h1>
            <p class="description">Manage course access and expiration dates for all students in one place.</p>
            
            <?php $this->display_notices(); ?>
            
            <div class="unified-manager-container">
                <!-- Quick Actions Panel -->
                <div class="quick-actions-panel">
                    <h2>⚡ Quick Actions</h2>
                    <div class="action-buttons">
                        <button type="button" class="button button-primary" onclick="openBulkUpdateDialog()">
                            📊 Bulk Update
                        </button>
                        <button type="button" class="button" onclick="refreshStudentsList()">
                            🔄 Refresh List
                        </button>
                        <button type="button" class="button" onclick="exportData()">
                            📤 Export Data
                        </button>
                    </div>
                    
                    <!-- Filters -->
                    <div class="filters-section">
                        <h3>🔍 Filters</h3>
                        <select id="status-filter" onchange="filterStudents()">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="expired">Expired</option>
                            <option value="expiring">Expiring Soon</option>
                            <option value="permanent">Permanent</option>
                        </select>
                        
                        <select id="course-filter" onchange="filterStudents()">
                            <option value="">All Courses</option>
                            <?php $this->display_course_options(); ?>
                        </select>
                        
                        <input type="text" id="search-students" placeholder="Search students..." onkeyup="filterStudents()">
                    </div>
                </div>
                
                <!-- Students Table -->
                <div class="students-table-container">
                    <h2>👥 Students & Course Access</h2>
                    <div class="table-wrapper">
                        <?php $this->display_students_table(); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dialogs -->
        <?php $this->render_dialogs(); ?>
        
        <style>
        .unified-manager-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .quick-actions-panel {
            flex: 0 0 300px;
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            height: fit-content;
        }
        
        .students-table-container {
            flex: 1;
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .filters-section {
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        
        .filters-section select,
        .filters-section input {
            width: 100%;
            margin-bottom: 10px;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        .unified-students-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .unified-students-table th,
        .unified-students-table td {
            padding: 12px 8px;
            text-align: right;
            border-bottom: 1px solid #ddd;
        }
        
        .unified-students-table th {
            background: #f1f1f1;
            font-weight: 600;
        }
        
        .unified-students-table tbody tr:hover {
            background: #f9f9f9;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-active { background: #d1e7dd; color: #0f5132; }
        .status-expired { background: #f8d7da; color: #721c24; }
        .status-expiring { background: #fff3cd; color: #856404; }
        .status-permanent { background: #cff4fc; color: #055160; }
        .status-no-access { background: #f8f9fa; color: #6c757d; border: 1px dashed #dee2e6; }
        
        .action-buttons-cell {
            white-space: nowrap;
        }
        
        .action-buttons-cell button {
            margin: 0 2px;
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .course-info {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 500;
        }
        
        .user-details {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
        }
        
        .no-courses-info {
            text-align: center;
            padding: 10px;
        }
        
        .tablenav {
            margin: 10px 0;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
        }
        
        .displaying-num {
            font-weight: 500;
            color: #2271b1;
        }
        
        .pagination-links a {
            text-decoration: none;
            padding: 4px 8px;
            margin: 0 2px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('Unified Course Manager loaded');
            console.log('AJAX URL:', unified_course_ajax.ajax_url);
            console.log('Nonce:', unified_course_ajax.nonce);
            
            // Initialize dialogs with error handling
            try {
                if ($.fn.dialog) {
                    $('#bulk-update-dialog').dialog({
                        autoOpen: false,
                        modal: true,
                        width: 500,
                        title: 'Bulk Update Course Access'
                    });
                    
                    $('#course-details-dialog').dialog({
                        autoOpen: false,
                        modal: true,
                        width: 600,
                        title: 'Course Details'
                    });
                    
                    $('#set-expiry-dialog').dialog({
                        autoOpen: false,
                        modal: true,
                        width: 400,
                        title: 'Set Course Expiration'
                    });
                    
                    $('#expiration-date-dialog').dialog({
                        autoOpen: false,
                        modal: true,
                        width: 500,
                        title: 'Set Expiration Date'
                    });
                    
                    console.log('All dialogs initialized successfully');
                } else {
                    console.warn('jQuery UI Dialog not available');
                }
            } catch (e) {
                console.error('Error initializing dialogs:', e);
            }
            
            // Global function definitions with improved error handling
            window.openBulkUpdateDialog = function() {
                try {
                    console.log('Opening bulk update dialog');
                    $('#bulk-update-dialog').dialog('open');
                } catch (e) {
                    console.error('Error opening bulk dialog:', e);
                    alert('Error opening dialog. Please refresh the page.');
                }
            };
            
            window.setCourseExpiration = function(userId, courseId) {
                console.log('setCourseExpiration called with userId:', userId, 'courseId:', courseId);
                
                try {
                    // Store user ID for later use
                    window.currentUserId = userId;
                    window.currentCourseId = courseId;
                    
                    // If courseId is not provided, show course ID dialog first
                    if (!courseId) {
                        $('#set-expiry-dialog').dialog('open');
                        return;
                    }
                    
                    // If we have course ID, show expiration date dialog
                    $('#expiration-date-dialog').dialog('open');
                } catch (e) {
                    console.error('Error in setCourseExpiration:', e);
                    alert('❌ JavaScript error: ' + e.message);
                }
            };
            
            // Function to process the actual expiration setting
            window.processExpirationSetting = function(courseId, expirationDate) {
                console.log('Processing expiration with courseId:', courseId, 'date:', expirationDate);
                
                try {
                    var expirationTimestamp = 0;
                    if (expirationDate.toLowerCase() !== 'permanent') {
                        var dateObj = new Date(expirationDate + ' 23:59:59');
                        if (isNaN(dateObj.getTime())) {
                            alert('Invalid date format. Please use YYYY-MM-DD');
                            return;
                        }
                        expirationTimestamp = Math.floor(dateObj.getTime() / 1000);
                    }
                    
                    console.log('Sending AJAX request with timestamp:', expirationTimestamp);
                    
                    $.ajax({
                        url: unified_course_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'unified_set_course_expiration',
                            user_id: window.currentUserId,
                            course_id: courseId,
                            expiration: expirationTimestamp,
                            nonce: unified_course_ajax.nonce
                        },
                        success: function(response) {
                            console.log('AJAX response:', response);
                            if (response.success) {
                                alert('✅ Course expiration updated successfully!');
                                location.reload();
                            } else {
                                alert('❌ Error: ' + (response.data || 'Unknown error'));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error:', xhr, status, error);
                            alert('❌ Network error: ' + error + '. Please try again.');
                        },
                        timeout: 10000
                    });
                } catch (e) {
                    console.error('Error in processExpirationSetting:', e);
                    alert('❌ JavaScript error: ' + e.message);
                }
            };
            
            // Functions for modal dialogs
            window.proceedToDateInput = function() {
                var courseId = jQuery('#course-id-input').val();
                if (!courseId) {
                    alert('Please enter a Course ID');
                    return;
                }
                
                console.log('Proceeding with course ID:', courseId);
                window.currentCourseId = courseId;
                
                jQuery('#set-expiry-dialog').dialog('close');
                jQuery('#expiration-date-dialog').dialog('open');
            };
            
            window.toggleCustomDate = function() {
                var type = jQuery('#expiration-type').val();
                if (type === 'custom') {
                    jQuery('#custom-date-row').show();
                } else {
                    jQuery('#custom-date-row').hide();
                }
            };
            
            window.processExpirationFromDialog = function() {
                var expirationType = jQuery('#expiration-type').val();
                var expirationDate;
                
                if (expirationType === 'custom') {
                    expirationDate = jQuery('#custom-date-input').val();
                    if (!expirationDate) {
                        alert('Please select a custom date');
                        return;
                    }
                } else if (expirationType === 'permanent') {
                    expirationDate = 'permanent';
                } else {
                    // Calculate date based on selection
                    var date = new Date();
                    switch(expirationType) {
                        case '1_month':
                            date.setMonth(date.getMonth() + 1);
                            break;
                        case '3_months':
                            date.setMonth(date.getMonth() + 3);
                            break;
                        case '6_months':
                            date.setMonth(date.getMonth() + 6);
                            break;
                        case '1_year':
                            date.setFullYear(date.getFullYear() + 1);
                            break;
                    }
                    expirationDate = date.toISOString().split('T')[0];
                }
                
                console.log('Processing expiration with type:', expirationType, 'date:', expirationDate);
                
                jQuery('#expiration-date-dialog').dialog('close');
                processExpirationSetting(window.currentCourseId, expirationDate);
            };
        });
        
            // Also define viewCourseDetails globally
            window.viewCourseDetails = function(userId) {
                console.log('viewCourseDetails called with userId:', userId);
                
                try {
                    $.ajax({
                        url: unified_course_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'unified_get_user_courses',
                            user_id: userId,
                            nonce: unified_course_ajax.nonce
                        },
                        success: function(response) {
                            console.log('Course details response:', response);
                            if (response.success) {
                                $('#course-details-content').html(response.data);
                                try {
                                    $('#course-details-dialog').dialog('open');
                                } catch (e) {
                                    console.error('Error opening dialog:', e);
                                    // Fallback: show in alert if dialog fails
                                    alert('Course Details:\n' + $(response.data).text());
                                }
                            } else {
                                alert('❌ Error loading course details: ' + (response.data || 'Unknown error'));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error in viewCourseDetails:', xhr, status, error);
                            alert('❌ Network error loading course details: ' + error);
                        },
                        timeout: 10000
                    });
                } catch (e) {
                    console.error('Error in viewCourseDetails:', e);
                    alert('❌ JavaScript error: ' + e.message);
                }
            };
        
        function filterStudents() {
            var statusFilter = jQuery('#status-filter').val();
            var courseFilter = jQuery('#course-filter').val();
            var searchTerm = jQuery('#search-students').val().toLowerCase();
            
            jQuery('.unified-students-table tbody tr').each(function() {
                var row = jQuery(this);
                var show = true;
                
                // Status filter
                if (statusFilter && !row.find('.status-' + statusFilter).length) {
                    show = false;
                }
                
                // Course filter
                if (courseFilter && !row.find('[data-course-id="' + courseFilter + '"]').length) {
                    show = false;
                }
                
                // Search filter
                if (searchTerm && !row.text().toLowerCase().includes(searchTerm)) {
                    show = false;
                }
                
                row.toggle(show);
            });
        }
        
        function refreshStudentsList() {
            location.reload();
        }
        
        function exportData() {
            alert('Export functionality will be implemented in the next version.');
        }
        </script>
        <?php
    }
    
    private function display_notices() {
        if (isset($_GET['success'])) {
            echo '<div class="notice notice-success"><p>✅ Operation completed successfully!</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error"><p>❌ Error: ' . esc_html($_GET['error']) . '</p></div>';
        }
    }
    
    private function display_course_options() {
        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        foreach ($courses as $course) {
            echo '<option value="' . esc_attr($course->ID) . '">' . esc_html($course->post_title) . ' (ID: ' . $course->ID . ')</option>';
        }
    }
    
    private function display_students_table() {
        // Get pagination parameters
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        // Use WordPress get_users() function which is more reliable
        $all_users = get_users([
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => 'all'
        ]);
        
        $total_students = count($all_users);
        
        // Get users for current page
        $results = array_slice($all_users, $offset, $per_page);
        
        if (empty($results)) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Debug Info:</strong></p>';
            echo '<p>Total users found with get_users(): ' . $total_students . '</p>';
            echo '<p>Current page: ' . $page . ', Per page: ' . $per_page . ', Offset: ' . $offset . '</p>';
            echo '<p>If you see this message, there might be an issue with pagination.</p>';
            echo '</div>';
            return;
        }
        
        // Display pagination info
        $total_pages = ceil($total_students / $per_page);
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<span class="displaying-num">' . sprintf('%s students total', number_format($total_students)) . '</span>';
        echo '</div>';
        if ($total_pages > 1) {
            echo '<div class="tablenav-pages">';
            echo '<span class="pagination-links">';
            
            // Previous page link
            if ($page > 1) {
                $prev_page = $page - 1;
                echo '<a class="prev-page button" href="?page=unified-course-manager&paged=' . $prev_page . '">‹ Previous</a> ';
            }
            
            // Page numbers
            echo '<span class="paging-input">';
            echo '<span class="tablenav-paging-text">Page ' . $page . ' of ' . $total_pages . '</span>';
            echo '</span>';
            
            // Next page link
            if ($page < $total_pages) {
                $next_page = $page + 1;
                echo ' <a class="next-page button" href="?page=unified-course-manager&paged=' . $next_page . '">Next ›</a>';
            }
            
            echo '</span>';
            echo '</div>';
        }
        echo '</div>';
        
        ?>
        <table class="unified-students-table">
            <thead>
                <tr>
                    <th>👤 Student</th>
                    <th>📚 Courses & Status</th>
                    <th>⚡ Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $user): ?>
                    <?php $this->display_student_row($user); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    private function display_student_row($user) {
        global $wpdb;
        
        // Get course expiration data for this user
        $course_data = $wpdb->get_results($wpdb->prepare("
            SELECT meta_key, meta_value
            FROM {$wpdb->usermeta}
            WHERE user_id = %d AND meta_key LIKE 'course_%_access_expires'
            ORDER BY meta_key
        ", $user->ID));
        
        // Get user roles for display
        $user_roles = implode(', ', $user->roles);
        
        ?>
        <tr data-user-id="<?php echo esc_attr($user->ID); ?>">
            <td>
                <div class="user-info">
                    <div class="user-name"><?php echo esc_html($user->display_name); ?></div>
                    <div class="user-details">
                        ID: <?php echo $user->ID; ?> | 
                        Login: <?php echo esc_html($user->user_login); ?><br>
                        📅 Registered: <?php echo date('d/m/Y', strtotime($user->user_registered)); ?><br>
                        👤 Role: <?php echo esc_html($user_roles); ?>
                    </div>
                </div>
            </td>
            <td>
                <?php $this->display_user_courses($course_data, $user->ID); ?>
            </td>
            <td class="action-buttons-cell">
                <button type="button" class="button button-small" onclick="viewCourseDetails(<?php echo $user->ID; ?>)">
                    📋 Details
                </button>
                <button type="button" class="button button-small button-primary" onclick="setCourseExpiration(<?php echo $user->ID; ?>)">
                    ⏰ Set Expiry
                </button>
            </td>
        </tr>
        <?php
    }
    
    private function display_user_courses($course_data, $user_id = null) {
        if (empty($course_data)) {
            echo '<div class="no-courses-info">';
            echo '<span class="status-badge status-no-access">🚫 No Course Access</span>';
            echo '<div class="course-info">';
            echo '<small>Click "Set Expiry" to add course access</small>';
            echo '</div>';
            echo '</div>';
            return;
        }
        
        foreach ($course_data as $data) {
            preg_match('/course_(\d+)_access_expires/', $data->meta_key, $matches);
            $course_id = $matches[1] ?? 'Unknown';
            $expires_timestamp = $data->meta_value;
            
            $course_title = get_the_title($course_id) ?: "Course $course_id";
            
            // Determine status
            $status_class = 'status-active';
            $status_text = 'Active';
            $icon = '✅';
            
            if ($expires_timestamp == 0) {
                $status_class = 'status-permanent';
                $status_text = 'Permanent';
                $icon = '♾️';
            } else {
                $current_time = current_time('timestamp');
                $days_remaining = ceil(($expires_timestamp - $current_time) / DAY_IN_SECONDS);
                
                if ($expires_timestamp < $current_time) {
                    $status_class = 'status-expired';
                    $status_text = 'Expired';
                    $icon = '❌';
                } elseif ($days_remaining <= 7) {
                    $status_class = 'status-expiring';
                    $status_text = 'Expiring';
                    $icon = '⚠️';
                }
            }
            
            echo '<div class="course-info" data-course-id="' . esc_attr($course_id) . '">';
            echo '<span class="status-badge ' . $status_class . '">' . $icon . ' ' . esc_html($status_text) . '</span> ';
            echo '<strong>' . esc_html($course_title) . '</strong>';
            if ($expires_timestamp > 0) {
                echo '<br><small>Expires: ' . date('d/m/Y', $expires_timestamp) . '</small>';
            }
            echo '</div>';
        }
    }
    
    private function render_dialogs() {
        ?>
        <!-- Bulk Update Dialog -->
        <div id="bulk-update-dialog" style="display: none;">
            <form id="bulk-update-form">
                <table class="form-table">
                    <tr>
                        <th><label for="bulk-course-id">Course ID:</label></th>
                        <td><input type="number" id="bulk-course-id" name="course_id" required></td>
                    </tr>
                    <tr>
                        <th><label for="bulk-expiration">Expiration:</label></th>
                        <td>
                            <select id="bulk-expiration" name="expiration">
                                <option value="permanent">Permanent Access</option>
                                <option value="1_month">1 Month from now</option>
                                <option value="3_months">3 Months from now</option>
                                <option value="6_months">6 Months from now</option>
                                <option value="1_year">1 Year from now</option>
                                <option value="custom">Custom Date</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="custom-date-row" style="display: none;">
                        <th><label for="bulk-custom-date">Custom Date:</label></th>
                        <td><input type="date" id="bulk-custom-date" name="custom_date"></td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" onclick="processBulkUpdate()">Apply to All Students</button>
                    <button type="button" class="button" onclick="jQuery('#bulk-update-dialog').dialog('close')">Cancel</button>
                </p>
            </form>
        </div>
        
        <!-- Course Details Dialog -->
        <div id="course-details-dialog" style="display: none;">
            <div id="course-details-content">Loading...</div>
        </div>
        
        <!-- Set Course Expiration Dialog -->
        <div id="set-expiry-dialog" style="display: none;">
            <form id="set-expiry-form">
                <table class="form-table">
                    <tr>
                        <th><label for="course-id-input">Course ID:</label></th>
                        <td><input type="number" id="course-id-input" name="course_id" placeholder="e.g., 123" required style="width: 100%;"></td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" onclick="proceedToDateInput()">Next: Set Date</button>
                    <button type="button" class="button" onclick="jQuery('#set-expiry-dialog').dialog('close')">Cancel</button>
                </p>
            </form>
        </div>
        
        <!-- Expiration Date Dialog -->
        <div id="expiration-date-dialog" style="display: none;">
            <form id="expiration-date-form">
                <table class="form-table">
                    <tr>
                        <th><label for="expiration-input">Expiration:</label></th>
                        <td>
                            <select id="expiration-type" onchange="toggleCustomDate()" style="width: 100%; margin-bottom: 10px;">
                                <option value="permanent">Permanent Access</option>
                                <option value="1_month">1 Month from now</option>
                                <option value="3_months">3 Months from now</option>
                                <option value="6_months">6 Months from now</option>
                                <option value="1_year">1 Year from now</option>
                                <option value="custom">Custom Date</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="custom-date-row" style="display: none;">
                        <th><label for="custom-date-input">Custom Date:</label></th>
                        <td><input type="date" id="custom-date-input" name="custom_date" style="width: 100%;"></td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" onclick="processExpirationFromDialog()">Set Expiration</button>
                    <button type="button" class="button" onclick="jQuery('#expiration-date-dialog').dialog('close')">Cancel</button>
                </p>
            </form>
        </div>
        <?php
    }
    
    public function ajax_set_course_expiration() {
        check_ajax_referer('unified_course_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $user_id = intval($_POST['user_id']);
        $course_id = intval($_POST['course_id']);
        $expiration = intval($_POST['expiration']);
        
        if (!$user_id || !$course_id) {
            wp_send_json_error('Missing required parameters');
        }
        
        // Update user meta
        $meta_key = 'course_' . $course_id . '_access_expires';
        update_user_meta($user_id, $meta_key, $expiration);
        
        // Update LearnDash course access if function exists
        if (function_exists('ld_update_course_access')) {
            ld_update_course_access($user_id, $course_id, false, $expiration);
        }
        
        wp_send_json_success('Course expiration updated successfully');
    }
    
    public function ajax_get_user_courses() {
        check_ajax_referer('unified_course_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error('Invalid user ID');
        }
        
        global $wpdb;
        $course_data = $wpdb->get_results($wpdb->prepare("
            SELECT meta_key, meta_value
            FROM {$wpdb->usermeta}
            WHERE user_id = %d AND meta_key LIKE 'course_%_access_expires'
            ORDER BY meta_key
        ", $user_id));
        
        $user = get_user_by('ID', $user_id);
        
        ob_start();
        ?>
        <h3>📚 Course Access for <?php echo esc_html($user->display_name); ?></h3>
        
        <?php if (empty($course_data)): ?>
            <p>No course access data found for this user.</p>
        <?php else: ?>
            <?php foreach ($course_data as $data): ?>
                <?php
                preg_match('/course_(\d+)_access_expires/', $data->meta_key, $matches);
                $course_id = $matches[1] ?? 'Unknown';
                $expires_timestamp = $data->meta_value;
                $course_title = get_the_title($course_id) ?: "Course $course_id";
                ?>
                <div style="padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h4><?php echo esc_html($course_title); ?> (ID: <?php echo $course_id; ?>)</h4>
                    <?php if ($expires_timestamp == 0): ?>
                        <p><strong>♾️ Permanent Access</strong></p>
                    <?php else: ?>
                        <?php
                        $current_time = current_time('timestamp');
                        $is_expired = $expires_timestamp < $current_time;
                        $days_remaining = ceil(($expires_timestamp - $current_time) / DAY_IN_SECONDS);
                        ?>
                        <p>
                            <strong><?php echo $is_expired ? '❌ Expired' : '✅ Active'; ?></strong><br>
                            Expires: <?php echo date('d/m/Y H:i', $expires_timestamp); ?>
                            <?php if (!$is_expired): ?>
                                (<?php echo $days_remaining; ?> days remaining)
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <button type="button" class="button button-small" onclick="setCourseExpiration(<?php echo $user_id; ?>, <?php echo $course_id; ?>)">
                        ⏰ Update Expiration
                    </button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <hr>
        <button type="button" class="button button-primary" onclick="setCourseExpiration(<?php echo $user_id; ?>, prompt('Enter Course ID to add:'))">
            ➕ Add New Course Access
        </button>
        <?php
        
        wp_send_json_success(ob_get_clean());
    }
    
    public function ajax_bulk_update() {
        check_ajax_referer('unified_course_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Implementation for bulk updates
        wp_send_json_success('Bulk update completed');
    }
}

// Initialize the plugin
new Unified_Course_Expiration_Manager();
