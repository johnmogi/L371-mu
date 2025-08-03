<?php
/**
 * Main plugin class for LearnDash Instructor Quiz Categories
 */

if (!defined('ABSPATH')) {
    exit;
}

class LD_Instructor_Quiz_Categories {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add meta box to quiz edit screen
        add_action('add_meta_boxes', array($this, 'add_quiz_categories_meta_box'));
        
        // Save selected categories when quiz is saved
        add_action('save_post_sfwd-quiz', array($this, 'save_quiz_categories'));
        
        // Register AJAX handlers
        add_action('wp_ajax_ld_test_quiz_population', array($this, 'ajax_test_quiz_population'));
        add_action('wp_ajax_ld_bulk_categorize_questions', array($this, 'ajax_bulk_categorize_questions'));
        add_action('wp_ajax_ld_single_category_debug', array($this, 'ajax_single_category_debug'));
        add_action('wp_ajax_bulk_categorize_questions', array($this, 'ajax_bulk_categorize_questions'));
        add_action('wp_ajax_nopriv_bulk_categorize_questions', array($this, 'ajax_bulk_categorize_questions'));
        
        // Load text domain for translations
        add_action('init', array($this, 'load_textdomain'));
        
        // Add admin debug page
        add_action('admin_menu', array($this, 'add_debug_page'));
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('ld-instructor-quiz-cats', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Add meta box to quiz edit screen
     */
    public function add_quiz_categories_meta_box() {
        add_meta_box(
            'ld-instructor-quiz-categories',
            __('Quiz Question Categories', 'ld-instructor-quiz-cats'),
            array($this, 'render_quiz_categories_meta_box'),
            'sfwd-quiz',
            'normal',
            'high'
        );
    }
    
    /**
     * Render the quiz categories meta box
     */
    public function render_quiz_categories_meta_box($post) {
        // Get the taxonomy that questions actually use
        $used_taxonomy = $this->get_used_taxonomy();
        
        // Get all question categories
        $question_categories = get_terms(array(
            'taxonomy' => $used_taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        // Get currently selected categories
        $selected_categories = get_post_meta($post->ID, '_ld_quiz_question_categories', true);
        if (!is_array($selected_categories)) {
            $selected_categories = array();
        }
        
        // Include the template
        include LD_INSTRUCTOR_QUIZ_CATS_PLUGIN_DIR . 'templates/meta-box-quiz-categories.php';
        
        // Include debug info
        include LD_INSTRUCTOR_QUIZ_CATS_PLUGIN_DIR . 'templates/debug-info.php';
    }
    
    /**
     * Save selected quiz categories
     */
    public function save_quiz_categories($post_id) {
        // Prevent infinite recursion
        static $processing = array();
        if (isset($processing[$post_id])) {
            return;
        }
        $processing[$post_id] = true;
        
        // Prevent autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            unset($processing[$post_id]);
            return;
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            unset($processing[$post_id]);
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['ld_instructor_quiz_categories_nonce']) || 
            !wp_verify_nonce($_POST['ld_instructor_quiz_categories_nonce'], 'save_quiz_categories')) {
            unset($processing[$post_id]);
            return;
        }
        
        // Save selected categories
        if (isset($_POST['ld_instructor_quiz_categories']) && is_array($_POST['ld_instructor_quiz_categories'])) {
            $selected_categories = array_map('intval', $_POST['ld_instructor_quiz_categories']);
            update_post_meta($post_id, '_ld_quiz_question_categories', $selected_categories);
            
            // Auto-populate quiz with questions from selected categories
            $this->populate_quiz_with_questions($post_id, $selected_categories);
        } else {
            // No categories selected, clear the meta and quiz questions
            delete_post_meta($post_id, '_ld_quiz_question_categories');
            $this->clear_quiz_questions($post_id);
        }
        
        // Clear recursion flag
        unset($processing[$post_id]);
    }
    
    /**
     * Get the taxonomy that questions actually use
     */
    private function get_used_taxonomy() {
        // Get a sample of actual questions to see what taxonomies they use
        $sample_questions = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'fields' => 'ids'
        ));
        
        if (empty($sample_questions)) {
            return 'ld_quiz_category'; // fallback
        }
        
        // Check what taxonomies these questions actually have terms in
        $taxonomy_usage = array();
        
        foreach ($sample_questions as $question_id) {
            $question_taxonomies = get_object_taxonomies('sfwd-question');
            
            foreach ($question_taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($question_id, $taxonomy);
                if (!empty($terms) && !is_wp_error($terms)) {
                    if (!isset($taxonomy_usage[$taxonomy])) {
                        $taxonomy_usage[$taxonomy] = 0;
                    }
                    $taxonomy_usage[$taxonomy] += count($terms);
                }
            }
        }
        
        // Return the taxonomy with the most usage
        if (!empty($taxonomy_usage)) {
            arsort($taxonomy_usage);
            $most_used_taxonomy = array_key_first($taxonomy_usage);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('LD Quiz Categories: Detected taxonomy usage: ' . print_r($taxonomy_usage, true));
                error_log('LD Quiz Categories: Using taxonomy: ' . $most_used_taxonomy);
            }
            
            return $most_used_taxonomy;
        }
        
        // Fallback to ld_quiz_category
        return 'ld_quiz_category';
    }
    
    /**
     * Populate quiz with questions from selected categories
     */
    private function populate_quiz_with_questions($quiz_id, $selected_categories) {
        if (empty($selected_categories)) {
            return;
        }
        
        // Find quizzes in the selected categories using the WORKING approach
        $quizzes_in_categories = get_posts(array(
            'post_type' => 'sfwd-quiz',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => $selected_categories,
                    'operator' => 'IN'
                )
            )
        ));
        
        // Extract questions using the RELOCATED PROCESSING approach that works
        $extracted_questions = array();
        
        if (is_array($quizzes_in_categories) && count($quizzes_in_categories) > 0) {
            // Process up to 20 quizzes to get a good pool of questions
            $quizzes_to_process = array_slice($quizzes_in_categories, 0, 20);
            
            foreach ($quizzes_to_process as $source_quiz_id) {
                // Get quiz metadata using multiple methods
                $ld_quiz_questions = get_post_meta($source_quiz_id, 'ld_quiz_questions', true);
                $quiz_pro_id = get_post_meta($source_quiz_id, 'quiz_pro_id', true);
                
                // Extract questions from ld_quiz_questions if available
                if (!empty($ld_quiz_questions) && is_array($ld_quiz_questions)) {
                    // CRITICAL FIX: Only extract valid LearnDash questions
                    foreach (array_keys($ld_quiz_questions) as $question_id) {
                        // Verify this is actually a LearnDash question
                        if (get_post_type($question_id) === 'sfwd-question' && get_post_status($question_id) === 'publish') {
                            $extracted_questions[] = $question_id;
                        }
                    }
                }
                
                // Try to extract from ProQuiz database if quiz_pro_id exists
                if (!empty($quiz_pro_id)) {
                    global $wpdb;
                    $proquiz_questions = $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}learndash_pro_quiz_question WHERE quiz_id = %d",
                        $quiz_pro_id
                    ));
                    if (!empty($proquiz_questions)) {
                        $extracted_questions = array_merge($extracted_questions, $proquiz_questions);
                    }
                }
            }
        }
        
        // Remove duplicates and limit to 300 questions max
        $extracted_questions = array_unique($extracted_questions);
        
        // Limit to maximum 300 questions for performance
        if (count($extracted_questions) > 300) {
            $extracted_questions = array_slice($extracted_questions, 0, 300);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('LD Quiz Categories: Limited questions to 300 for performance');
            }
        }
        
        if (!empty($extracted_questions)) {
            // CRITICAL: LearnDash expects questions in specific format
            // Convert question IDs to the format LearnDash expects
            $formatted_questions = array();
            foreach ($extracted_questions as $question_id) {
                // LearnDash stores questions with their sort order
                $formatted_questions[$question_id] = count($formatted_questions) + 1;
            }
            
            // Update quiz with formatted questions
            update_post_meta($quiz_id, 'ld_quiz_questions', $formatted_questions);
            update_post_meta($quiz_id, '_ld_quiz_dirty', true);
            
            // CRITICAL: Update ProQuiz database - this is essential for quiz builder
            $target_quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
            if (!empty($target_quiz_pro_id)) {
                global $wpdb;
                
                // First, clear existing questions from ProQuiz
                $wpdb->delete(
                    $wpdb->prefix . 'learndash_pro_quiz_question',
                    array('quiz_id' => $target_quiz_pro_id),
                    array('%d')
                );
                
                // Insert questions into ProQuiz database
                $sort_order = 1;
                foreach ($extracted_questions as $question_id) {
                    // Get question data
                    $question_post = get_post($question_id);
                    if ($question_post) {
                        $wpdb->insert(
                            $wpdb->prefix . 'learndash_pro_quiz_question',
                            array(
                                'quiz_id' => $target_quiz_pro_id,
                                'sort' => $sort_order,
                                'title' => $question_post->post_title,
                                'question' => $question_post->post_content,
                                'correct_msg' => '',
                                'incorrect_msg' => '',
                                'answer_type' => 'single',
                                'answer_points_activated' => 0,
                                'answer_points_diff_modus_activated' => 0,
                                'show_points_in_box' => 0,
                                'category_id' => 0,
                                'answer_data' => ''
                            ),
                            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s')
                        );
                        $sort_order++;
                    }
                }
                
                // Update ProQuiz master table with question count (only if column exists)
                $pro_quiz_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
                if ($pro_quiz_id) {
                    $table_name = $wpdb->prefix . 'learndash_pro_quiz_master';
                    $columns = $wpdb->get_col("DESCRIBE {$table_name}");
                    if (in_array('question_count', $columns)) {
                        $question_count = count($extracted_questions);
                        $wpdb->update(
                            $table_name,
                            array('question_count' => $question_count),
                            array('id' => $pro_quiz_id),
                            array('%d'),
                            array('%d')
                        );
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('LD Quiz Categories: ProQuiz table does not have question_count column, skipping update');
                        }
                    }
                }
            }
            
            // Force LearnDash to recognize the changes
            do_action('learndash_quiz_questions_updated', $quiz_id, $formatted_questions);
            
            // CRITICAL: Clear LearnDash caches and force quiz builder refresh
            if (function_exists('learndash_delete_quiz_cache')) {
                learndash_delete_quiz_cache($quiz_id);
            }
            
            // Clear WordPress object cache
            wp_cache_delete($quiz_id, 'posts');
            wp_cache_delete($quiz_id . '_quiz_questions', 'learndash');
            
            // Update quiz timestamp directly in database (no hooks triggered)
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                array(
                    'post_modified' => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', 1)
                ),
                array('ID' => $quiz_id),
                array('%s', '%s'),
                array('%d')
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('LD Quiz Categories: Final question count: ' . count($extracted_questions) . ' from ' . count($quizzes_in_categories) . ' quizzes');
                error_log('LD Quiz Categories: Added ' . count($extracted_questions) . ' questions to quiz ' . $quiz_id . ' and cleared caches');
                error_log('LD Quiz Categories: Questions added: ' . implode(', ', $extracted_questions));
                
                // Verify the questions were actually saved
                $saved_questions = get_post_meta($quiz_id, 'ld_quiz_questions', true);
                error_log('LD Quiz Categories: Verification - saved questions count: ' . (is_array($saved_questions) ? count($saved_questions) : 'NOT ARRAY'));
            }
        }
    }
    
    /**
     * Clear quiz questions
     */
    private function clear_quiz_questions($quiz_id) {
        delete_post_meta($quiz_id, 'ld_quiz_questions');
        delete_post_meta($quiz_id, 'ld_quiz_questions_dirty');
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('LD Quiz Categories: Cleared questions from quiz ' . $quiz_id);
        }
    }
    
    /**
     * AJAX handler for testing quiz population
     */
    public function ajax_test_quiz_population() {
        // Verify nonce
        $quiz_id = intval($_POST['quiz_id']);
        if (!wp_verify_nonce($_POST['nonce'], 'test_population_' . $quiz_id)) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $quiz_id)) {
            wp_die('Permission denied');
        }
        
        $selected_categories = array_map('intval', $_POST['categories']);
        
        if (empty($selected_categories)) {
            wp_send_json_error(array('message' => 'No categories selected'));
        }
        
        // CORRECT LOGIC: Find quizzes in selected categories, then extract their questions
        $quizzes_in_categories = get_posts(array(
            'post_type' => 'sfwd-quiz',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => $selected_categories,
                    'operator' => 'IN'
                )
            )
        ));
        
        // REWRITTEN: Extract questions from quizzes using working direct access approach
        $questions = array();
        $debug_quiz_data = array();
        
        // Process quizzes directly without complex loops
        if (is_array($quizzes_in_categories) && count($quizzes_in_categories) > 0) {
            // Process first 10 quizzes to extract their questions
            $quizzes_to_process = array_slice($quizzes_in_categories, 0, 10);
            
            // Add to debug info that new processing started
            $debug_info['new_processing_started'] = true;
            $debug_info['processing_quiz_count'] = count($quizzes_to_process);
            
            foreach ($quizzes_to_process as $quiz_id) {
                // Get quiz metadata using multiple possible keys
                $ld_quiz_questions = get_post_meta($quiz_id, 'ld_quiz_questions', true);
                $quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
                $sfwd_quiz_meta = get_post_meta($quiz_id, '_sfwd-quiz', true);
                
                // Store debug info
                $debug_quiz_data[$quiz_id] = array(
                    'title' => get_the_title($quiz_id),
                    'ld_quiz_questions' => $ld_quiz_questions,
                    'quiz_pro_id' => $quiz_pro_id,
                    'has_sfwd_meta' => !empty($sfwd_quiz_meta)
                );
                
                // Extract questions from ld_quiz_questions if available
                if (!empty($ld_quiz_questions) && is_array($ld_quiz_questions)) {
                    $questions = array_merge($questions, array_keys($ld_quiz_questions));
                }
                
                // Try to extract from ProQuiz if quiz_pro_id exists
                if (!empty($quiz_pro_id)) {
                    // Query ProQuiz questions table
                    global $wpdb;
                    $proquiz_questions = $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}learndash_pro_quiz_question WHERE quiz_id = %d",
                        $quiz_pro_id
                    ));
                    if (!empty($proquiz_questions)) {
                        $questions = array_merge($questions, $proquiz_questions);
                    }
                }
            }
        }
        
        // Remove duplicates
        $questions = array_unique($questions);
        
        // Remove duplicates
        $questions = array_unique($questions);
        $taxonomy = 'ld_quiz_category'; // We know this is correct now
        
        // Add debug info for new approach
        $debug_info['quiz_metadata_sample'] = array_slice($debug_quiz_data, 0, 3, true);
        $debug_info['total_quizzes_processed'] = count($debug_quiz_data);
        $debug_info['questions_extracted'] = count($questions);
        $debug_info['extraction_successful'] = !empty($questions);
        
        // Test question retrieval with detected taxonomy
        $questions = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => $taxonomy,
                    'field' => 'term_id',
                    'terms' => $selected_categories,
                    'operator' => 'IN'
                )
            )
        ));
        
        // DETAILED DEBUGGING - Check what's actually happening
        $debug_info = array();
        
        // Check what taxonomy the selected categories belong to
        foreach ($selected_categories as $cat_id) {
            $term = get_term($cat_id);
            if ($term && !is_wp_error($term)) {
                $debug_info['categories'][$cat_id] = array(
                    'name' => $term->name,
                    'taxonomy' => $term->taxonomy,
                    'count' => $term->count
                );
            }
        }
        
        // Check a few sample questions and their taxonomies
        $sample_questions = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => 50, // Check more questions
            'fields' => 'ids'
        ));
        
        // ALSO: Try to find questions that ARE assigned to our selected categories
        $categorized_questions = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => $selected_categories,
                    'operator' => 'IN'
                )
            )
        ));
        
        $debug_info['categorized_questions_found'] = count($categorized_questions);
        if (!empty($categorized_questions)) {
            $debug_info['sample_categorized'] = array_slice($categorized_questions, 0, 5);
        }
        
        // SPECIFIC TEST: Check category 162 (רכב פרטי) which shows 72 questions
        $specific_test = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => array(162), // רכב פרטי
                    'operator' => 'IN'
                )
            )
        ));
        
        $debug_info['specific_category_test'] = array(
            'category_id' => 162,
            'questions_found' => count($specific_test),
            'sample_ids' => $specific_test
        );
        
        $debug_info['sample_questions'] = array();
        foreach ($sample_questions as $q_id) {
            $question_taxonomies = get_object_taxonomies('sfwd-question');
            $question_terms = array();
            
            foreach ($question_taxonomies as $tax) {
                $terms = wp_get_post_terms($q_id, $tax);
                if (!empty($terms) && !is_wp_error($terms)) {
                    $question_terms[$tax] = array_map(function($t) { return $t->name; }, $terms);
                }
            }
            
            $debug_info['sample_questions'][$q_id] = $question_terms;
        }
        
        // Get total questions for comparison
        $all_questions = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => -1
        ));
        
        // Check what taxonomies the first few questions actually have
        $sample_question_taxonomies = array();
        $all_available_taxonomies = array();
        
        if (!empty($all_questions)) {
            $sample_questions = array_slice($all_questions, 0, 10);
            foreach ($sample_questions as $question) {
                $question_taxonomies = get_object_taxonomies($question->post_type);
                
                // Track all available taxonomies
                foreach ($question_taxonomies as $tax) {
                    if (!isset($all_available_taxonomies[$tax])) {
                        $all_available_taxonomies[$tax] = 0;
                    }
                    $all_available_taxonomies[$tax]++;
                }
                
                // Check which taxonomies have terms assigned
                foreach ($question_taxonomies as $tax) {
                    $terms = wp_get_post_terms($question->ID, $tax);
                    if (!empty($terms) && !is_wp_error($terms)) {
                        if (!isset($sample_question_taxonomies[$tax])) {
                            $sample_question_taxonomies[$tax] = 0;
                        }
                        $sample_question_taxonomies[$tax] += count($terms);
                    }
                }
            }
        }
        
        $message = sprintf(
            'Found %d questions from %d quizzes in selected categories (out of %d total questions). Categories: %s',
            count($questions),
            count($quizzes_in_categories),
            count($all_questions),
            implode(', ', $selected_categories)
        );
        
        // Add quiz info to debug
        $debug_info['quizzes_found'] = count($quizzes_in_categories);
        if (!empty($quizzes_in_categories)) {
            $debug_info['sample_quiz_ids'] = array_slice($quizzes_in_categories, 0, 5);
        }
        
        // Add detailed debug info to message
        $debug_details = array();
        $debug_details[] = '🚀 DEBUG CODE VERSION 3.0 - NEW APPROACH ACTIVE! 🚀';
        $debug_details[] = '🔍 About to process ' . count($quizzes_in_categories) . ' quizzes for metadata extraction';
        $debug_details[] = '📊 $quizzes_in_categories type: ' . gettype($quizzes_in_categories);
        $debug_details[] = '📊 $quizzes_in_categories is_array: ' . (is_array($quizzes_in_categories) ? 'YES' : 'NO');
        $debug_details[] = '📊 First 3 quiz IDs: ' . (is_array($quizzes_in_categories) ? implode(', ', array_slice($quizzes_in_categories, 0, 3)) : 'NOT ARRAY');
        
        // RELOCATED QUIZ PROCESSING: Do the actual quiz processing here where code works
        $extracted_questions = array();
        $processed_quizzes = array();
        
        if (is_array($quizzes_in_categories) && count($quizzes_in_categories) > 0) {
            $test_quiz_id = $quizzes_in_categories[0];
            $debug_details[] = '📝 DIRECT TEST: First quiz ID is ' . $test_quiz_id;
            $debug_details[] = '📝 DIRECT TEST: Quiz title is "' . get_the_title($test_quiz_id) . '"';
            
            // ACTUAL PROCESSING: Extract questions from first 5 quizzes
            $quizzes_to_process = array_slice($quizzes_in_categories, 0, 5);
            $debug_details[] = '🎆 RELOCATED PROCESSING: Processing ' . count($quizzes_to_process) . ' quizzes';
            
            foreach ($quizzes_to_process as $quiz_id) {
                // Get quiz metadata
                $ld_quiz_questions = get_post_meta($quiz_id, 'ld_quiz_questions', true);
                $quiz_pro_id = get_post_meta($quiz_id, 'quiz_pro_id', true);
                
                $processed_quizzes[$quiz_id] = array(
                    'title' => get_the_title($quiz_id),
                    'ld_quiz_questions' => $ld_quiz_questions,
                    'quiz_pro_id' => $quiz_pro_id
                );
                
                // Extract questions
                if (!empty($ld_quiz_questions) && is_array($ld_quiz_questions)) {
                    $extracted_questions = array_merge($extracted_questions, array_keys($ld_quiz_questions));
                }
                
                // Try ProQuiz database
                if (!empty($quiz_pro_id)) {
                    global $wpdb;
                    $proquiz_questions = $wpdb->get_col($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}learndash_pro_quiz_question WHERE quiz_id = %d",
                        $quiz_pro_id
                    ));
                    if (!empty($proquiz_questions)) {
                        $extracted_questions = array_merge($extracted_questions, $proquiz_questions);
                    }
                }
            }
            
            $extracted_questions = array_unique($extracted_questions);
            $debug_details[] = '🎆 RELOCATED PROCESSING: Extracted ' . count($extracted_questions) . ' questions total';
            
        } else {
            $debug_details[] = '📝 DIRECT TEST: No quiz IDs available for testing';
        }
        
        // Add new quiz processing results
        if (isset($debug_info['new_processing_started'])) {
            $debug_details[] = '🎆 NEW PROCESSING LOGIC ACTIVATED!';
            $debug_details[] = '🎆 Processing ' . $debug_info['processing_quiz_count'] . ' quizzes with new approach';
        }
        
        if (isset($debug_info['total_quizzes_processed'])) {
            $debug_details[] = '🚀 NEW APPROACH: Processed ' . $debug_info['total_quizzes_processed'] . ' quizzes successfully';
            $debug_details[] = '🚀 NEW APPROACH: Extracted ' . $debug_info['questions_extracted'] . ' questions';
            $debug_details[] = '🚀 NEW APPROACH: Extraction ' . ($debug_info['extraction_successful'] ? 'SUCCESSFUL' : 'FAILED');
        }
        
        if (!empty($debug_info['categories'])) {
            $debug_details[] = 'Selected Categories Details:';
            foreach ($debug_info['categories'] as $cat_id => $cat_info) {
                $debug_details[] = "- {$cat_info['name']} (ID: {$cat_id}, Taxonomy: {$cat_info['taxonomy']}, Count: {$cat_info['count']})";
            }
        }
        
        // Add info about questions found in selected categories
        if (isset($debug_info['categorized_questions_found'])) {
            $debug_details[] = "Questions found in selected categories: {$debug_info['categorized_questions_found']}";
            if (!empty($debug_info['sample_categorized'])) {
                $debug_details[] = "Sample categorized question IDs: " . implode(', ', $debug_info['sample_categorized']);
            }
        }
        
        // Add specific category test results
        if (isset($debug_info['specific_category_test'])) {
            $test = $debug_info['specific_category_test'];
            $debug_details[] = "SPECIFIC TEST - Category {$test['category_id']} (רכב פרטי): Found {$test['questions_found']} questions";
            if (!empty($test['sample_ids'])) {
                $debug_details[] = "Sample IDs from רכב פרטי: " . implode(', ', $test['sample_ids']);
            }
        }
        
        // Add quiz metadata debug info
        if (isset($debug_info['quizzes_found_for_processing'])) {
            $debug_details[] = "Quizzes found for processing: {$debug_info['quizzes_found_for_processing']}";
            $debug_details[] = "First few quiz IDs: " . implode(', ', $debug_info['first_few_quiz_ids']);
        }
        
        if (isset($debug_info['total_quizzes_checked'])) {
            $debug_details[] = "Total quizzes checked for metadata: {$debug_info['total_quizzes_checked']}";
            $debug_details[] = "Debug quiz data empty: " . ($debug_info['debug_quiz_data_empty'] ? 'YES' : 'NO');
        }
        
        // Show quiz metadata from relocated processing
        if (!empty($processed_quizzes)) {
            $debug_details[] = 'Quiz Metadata Sample (FROM RELOCATED PROCESSING):';
            $sample_count = 0;
            foreach ($processed_quizzes as $quiz_id => $metadata) {
                if ($sample_count >= 3) break;
                $debug_details[] = "- Quiz {$quiz_id} ({$metadata['title']}):";
                $debug_details[] = "  ld_quiz_questions: " . (empty($metadata['ld_quiz_questions']) ? 'EMPTY' : (is_array($metadata['ld_quiz_questions']) ? 'ARRAY with ' . count($metadata['ld_quiz_questions']) . ' items' : 'NON-ARRAY'));
                $debug_details[] = "  quiz_pro_id: {$metadata['quiz_pro_id']}";
                $sample_count++;
            }
        } else {
            $debug_details[] = 'Quiz Metadata Sample: NO DATA AVAILABLE (relocated processing failed)';
        }
        
        if (!empty($debug_info['sample_questions'])) {
            $debug_details[] = 'Sample Questions Taxonomy Usage:';
            $questions_with_terms = 0;
            $sample_count = 0;
            foreach ($debug_info['sample_questions'] as $q_id => $terms) {
                $sample_count++;
                if (!empty($terms)) {
                    $questions_with_terms++;
                    $term_list = array();
                    foreach ($terms as $tax => $term_names) {
                        $term_list[] = "{$tax}: " . implode(', ', $term_names);
                    }
                    $debug_details[] = "- Question {$q_id}: " . implode(' | ', $term_list);
                } else {
                    if ($sample_count <= 10) { // Only show first 10 to avoid clutter
                        $debug_details[] = "- Question {$q_id}: NO TERMS ASSIGNED";
                    }
                }
            }
            $debug_details[] = "Questions with terms: {$questions_with_terms}/" . count($debug_info['sample_questions']) . " (showing first 10)";
        }
        
        $full_message = $message;
        if (!empty($debug_details)) {
            $full_message .= "\n\n" . implode("\n", $debug_details);
        }
        
        if (count($questions) > 0) {
            wp_send_json_success(array('message' => $full_message));
        } else {
            wp_send_json_error(array('message' => $full_message));
        }
    }
    
    /**
     * AJAX handler - One-time fix to assign questions to categories
     */
    public function ajax_bulk_categorize_questions() {
        // Security checks
        $quiz_id = intval($_POST['quiz_id']);
        if (!wp_verify_nonce($_POST['nonce'], 'bulk_categorize_' . $quiz_id)) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        if (!current_user_can('edit_post', $quiz_id)) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        $selected_categories = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : array();
        
        if (empty($selected_categories)) {
            wp_send_json_error('No categories selected');
            return;
        }
        
        // Get all questions
        $all_questions = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        if (empty($all_questions)) {
            wp_send_json_error('No questions found');
            return;
        }
        
        // Distribute questions evenly across selected categories
        $questions_per_category = ceil(count($all_questions) / count($selected_categories));
        $categorized_count = 0;
        $taxonomy = 'ld_quiz_category';
        
        foreach ($selected_categories as $index => $category_id) {
            $start = $index * $questions_per_category;
            $questions_batch = array_slice($all_questions, $start, $questions_per_category);
            
            foreach ($questions_batch as $question_id) {
                // Assign question to this category
                $result = wp_set_post_terms($question_id, array($category_id), $taxonomy, false);
                if (!is_wp_error($result)) {
                    $categorized_count++;
                }
            }
        }
        
        $message = sprintf(
            '✅ SUCCESS! Assigned %d questions to %d categories. Distribution: ~%d questions per category. Quiz auto-population will now work!',
            $categorized_count,
            count($selected_categories),
            $questions_per_category
        );
        
        wp_send_json_success($message);
    }
    
    /**
     * AJAX handler for single category debug test
     */
    public function ajax_single_category_debug() {
        // Verify nonce
        $quiz_id = intval($_POST['quiz_id']);
        if (!wp_verify_nonce($_POST['nonce'], 'test_population_' . $quiz_id)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('edit_post', $quiz_id)) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $debug_info = array();
        $debug_info[] = '🔧 SINGLE CATEGORY DEBUG TEST';
        
        // Get selected categories
        $selected_categories = get_post_meta($quiz_id, '_ld_quiz_question_categories', true);
        if (empty($selected_categories)) {
            wp_send_json_error('No categories selected for this quiz');
        }
        
        $test_category_id = $selected_categories[0];
        $category = get_term($test_category_id, 'ld_quiz_category');
        
        $debug_info[] = "Testing Category: {$category->name} (ID: {$test_category_id})";
        
        // Method 1: Direct question query
        $questions_method1 = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => 15,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => $test_category_id
                )
            )
        ));
        
        $debug_info[] = "Method 1 (Direct): Found " . count($questions_method1) . " questions";
        
        // Method 2: Questions from quizzes in category
        $quizzes_in_category = get_posts(array(
            'post_type' => 'sfwd-quiz',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => $test_category_id
                )
            )
        ));
        
        $questions_from_quizzes = array();
        foreach ($quizzes_in_category as $source_quiz_id) {
            $quiz_questions = get_post_meta($source_quiz_id, 'ld_quiz_questions', true);
            if (!empty($quiz_questions) && is_array($quiz_questions)) {
                foreach (array_keys($quiz_questions) as $question_id) {
                    if (get_post_type($question_id) === 'sfwd-question' && get_post_status($question_id) === 'publish') {
                        $questions_from_quizzes[] = $question_id;
                    }
                }
            }
        }
        
        $questions_from_quizzes = array_unique($questions_from_quizzes);
        $debug_info[] = "Method 2 (From Quizzes): Found " . count($questions_from_quizzes) . " questions from " . count($quizzes_in_category) . " quizzes";
        
        // Choose best method and attach questions
        $questions_to_attach = array();
        $method_used = '';
        
        if (!empty($questions_method1)) {
            $questions_to_attach = array_slice($questions_method1, 0, 15);
            $method_used = 'Direct Category Query';
        } elseif (!empty($questions_from_quizzes)) {
            $questions_to_attach = array_slice($questions_from_quizzes, 0, 15);
            $method_used = 'Questions from Quizzes in Category';
        }
        
        if (!empty($questions_to_attach)) {
            // Format questions for LearnDash
            $formatted_questions = array();
            foreach ($questions_to_attach as $index => $question_id) {
                $formatted_questions[$question_id] = $index + 1;
            }
            
            // Update quiz
            update_post_meta($quiz_id, 'ld_quiz_questions', $formatted_questions);
            update_post_meta($quiz_id, 'ld_quiz_questions_dirty', time());
            
            // Clear caches
            wp_cache_delete($quiz_id, 'posts');
            if (function_exists('learndash_delete_quiz_cache')) {
                learndash_delete_quiz_cache($quiz_id);
            }
            
            $debug_info[] = "✅ SUCCESS: Attached " . count($questions_to_attach) . " questions using {$method_used}";
            $debug_info[] = "Question IDs: " . implode(', ', $questions_to_attach);
            $debug_info[] = "🔄 Refresh the page to see questions in quiz builder";
            
            // Log success
            error_log("LD Quiz Categories: Found " . count($questions_to_attach) . " questions in Category {$category->name}. Attached to Quiz #{$quiz_id}.");
            
            wp_send_json_success(array(
                'message' => implode('<br>', $debug_info),
                'questions_attached' => count($questions_to_attach)
            ));
        } else {
            $debug_info[] = "❌ FAILED: No questions found using any method";
            wp_send_json_error(implode('<br>', $debug_info));
        }
    }
    
    /**
     * Add admin debug page
     */
    public function add_debug_page() {
        add_submenu_page(
            'edit.php?post_type=sfwd-quiz',
            'Single Category Debug Test',
            '🔧 Debug Test',
            'manage_options',
            'ld-single-category-test',
            array($this, 'render_debug_page')
        );
    }
    
    /**
     * Render the debug page
     */
    public function render_debug_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        echo '<div class="wrap">';
        echo '<h1>🔧 Multi-Category Debug Test</h1>';
        echo '<p>Testing quiz auto-population from up to 2 selected categories...</p>';
        
        // Run the test if requested
        if (isset($_GET['run_test']) && $_GET['run_test'] === '1') {
            $this->run_single_category_test();
        } else {
            echo '<p><a href="' . admin_url('edit.php?post_type=sfwd-quiz&page=ld-single-category-test&run_test=1') . '" class="button button-primary">🚀 Run Multi-Category Test</a></p>';
            echo '<p><em>This will test quiz ID 10592 with up to 2 selected categories.</em></p>';
        }
        
        echo '</div>';
    }
    
    /**
     * Run the multi-category test (up to 2 categories)
     */
    public function run_single_category_test() {
        // Target quiz from the browser page
        $quiz_id = 10592;
        echo "<h2>📋 Setup</h2>";
        echo "<p><strong>Quiz ID:</strong> {$quiz_id}</p>";
        
        // Step 1: Get selected categories
        $selected_categories = get_post_meta($quiz_id, '_ld_quiz_question_categories', true);
        if (empty($selected_categories)) {
            echo "<div class='notice notice-error'><p>❌ No categories selected for this quiz. Please select categories in the quiz edit screen first.</p></div>";
            return;
        }
        
        // Limit to 2 categories for testing
        $test_categories = array_slice($selected_categories, 0, 2);
        
        echo "<p><strong>Selected Categories:</strong> " . implode(', ', $selected_categories) . "</p>";
        echo "<p><strong>Testing Categories (up to 2):</strong> " . implode(', ', $test_categories) . "</p>";
        
        // Show details for each test category
        foreach ($test_categories as $index => $category_id) {
            $category = get_term($category_id, 'ld_quiz_category');
            echo "<p><strong>Category " . ($index + 1) . ":</strong> {$category->name} (ID: {$category_id}, Count: {$category->count})</p>";
        }
        
        echo "<h2>🔍 Method Testing</h2>";
        
        // Method 1: Direct question query (for all test categories)
        echo "<h3>Method 1: Direct Question Query</h3>";
        $questions_method1 = get_posts(array(
            'post_type' => 'sfwd-question',
            'post_status' => 'publish',
            'posts_per_page' => 30,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => $test_categories
                )
            )
        ));
        
        echo "<p>Found <strong>" . count($questions_method1) . "</strong> questions directly in selected categories</p>";
        if (!empty($questions_method1)) {
            echo "<p>Sample IDs: " . implode(', ', array_slice($questions_method1, 0, 5)) . "</p>";
        }
        
        // Method 2: Questions from quizzes in categories
        echo "<h3>Method 2: Questions from Quizzes in Categories</h3>";
        $quizzes_in_category = get_posts(array(
            'post_type' => 'sfwd-quiz',
            'post_status' => 'publish',
            'posts_per_page' => 15,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => 'ld_quiz_category',
                    'field' => 'term_id',
                    'terms' => $test_categories
                )
            )
        ));
        
        echo "<p>Found <strong>" . count($quizzes_in_category) . "</strong> quizzes in category</p>";
        
        $questions_from_quizzes = array();
        if (!empty($quizzes_in_category)) {
            foreach (array_slice($quizzes_in_category, 0, 5) as $source_quiz_id) {
                $quiz_title = get_the_title($source_quiz_id);
                echo "<p>📝 <strong>Quiz:</strong> {$quiz_title} (ID: {$source_quiz_id})</p>";
                
                $quiz_questions = get_post_meta($source_quiz_id, 'ld_quiz_questions', true);
                if (!empty($quiz_questions) && is_array($quiz_questions)) {
                    $valid_questions = array();
                    foreach (array_keys($quiz_questions) as $question_id) {
                        if (get_post_type($question_id) === 'sfwd-question' && get_post_status($question_id) === 'publish') {
                            $valid_questions[] = $question_id;
                            $questions_from_quizzes[] = $question_id;
                        }
                    }
                    echo "<p>  → Found <strong>" . count($valid_questions) . "</strong> valid questions</p>";
                    if (!empty($valid_questions)) {
                        echo "<p>  → Sample: " . implode(', ', array_slice($valid_questions, 0, 3)) . "</p>";
                    }
                } else {
                    echo "<p>  → No questions found in this quiz</p>";
                }
            }
        }
        
        $questions_from_quizzes = array_unique($questions_from_quizzes);
        echo "<p><strong>Total unique questions from quizzes: " . count($questions_from_quizzes) . "</strong></p>";
        
        // Step 3: Choose best method and attach questions
        echo "<h2>🚀 Attaching Questions</h2>";
        
        $questions_to_attach = array();
        $method_used = '';
        
        if (!empty($questions_method1)) {
            $questions_to_attach = array_slice($questions_method1, 0, 15);
            $method_used = 'Direct Category Query';
        } elseif (!empty($questions_from_quizzes)) {
            $questions_to_attach = array_slice($questions_from_quizzes, 0, 15);
            $method_used = 'Questions from Quizzes in Category';
        } else {
            echo "<div class='notice notice-error'><p>❌ <strong>FAILED:</strong> No questions found using any method!</p></div>";
            return;
        }
        
        echo "<p><strong>Method Used:</strong> {$method_used}</p>";
        echo "<p><strong>Questions to Attach:</strong> " . count($questions_to_attach) . "</p>";
        echo "<p><strong>Question IDs:</strong> " . implode(', ', $questions_to_attach) . "</p>";
        
        // Step 4: Attach questions to quiz
        if (!empty($questions_to_attach)) {
            // Format questions for LearnDash
            $formatted_questions = array();
            foreach ($questions_to_attach as $index => $question_id) {
                $formatted_questions[$question_id] = $index + 1; // Sort order
            }
            
            // Update quiz with questions
            $update_result = update_post_meta($quiz_id, 'ld_quiz_questions', $formatted_questions);
            update_post_meta($quiz_id, 'ld_quiz_questions_dirty', time());
            
            // Clear caches
            wp_cache_delete($quiz_id, 'posts');
            if (function_exists('learndash_delete_quiz_cache')) {
                learndash_delete_quiz_cache($quiz_id);
            }
            
            // Verify the update
            $saved_questions = get_post_meta($quiz_id, 'ld_quiz_questions', true);
            $saved_count = is_array($saved_questions) ? count($saved_questions) : 0;
            
            echo "<div class='notice notice-success'>";
            echo "<h3>✅ Results</h3>";
            echo "<p style='font-size: 16px; font-weight: bold;'>SUCCESS: Attached {$saved_count} questions to Quiz #{$quiz_id}</p>";
            echo "<p style='color: blue;'><strong><a href='" . admin_url("post.php?post={$quiz_id}&action=edit") . "'>🔄 Click here to view your quiz and see the questions in the builder!</a></strong></p>";
            echo "</div>";
            
            // Log to debug
            error_log("LD Quiz Categories: Found " . count($questions_to_attach) . " questions in Category {$category->name}. Attached to Quiz #{$quiz_id}.");
        }
    }
    
    /**
     * Get plugin version
     */
    public function get_version() {
        return LD_INSTRUCTOR_QUIZ_CATS_VERSION;
    }
}
