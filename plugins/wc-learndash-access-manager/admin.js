/* WooCommerce LearnDash Access Manager Admin JavaScript */

jQuery(document).ready(function($) {
    
    // Duration change handler
    $('#_learndash_access_duration').on('change', function() {
        var duration = $(this).val();
        var infoDiv = $('.wc-learndash-duration-info');
        
        // Remove existing info
        infoDiv.remove();
        
        if (duration) {
            var info = getDurationInfo(duration);
            $(this).closest('.form-field').after(
                '<div class="wc-learndash-duration-info">' +
                '<strong>Access Duration:</strong> ' + info +
                '</div>'
            );
        }
    });
    
    // Custom date change handler
    $('#_learndash_custom_end_date').on('change', function() {
        var customDate = $(this).val();
        var durationSelect = $('#_learndash_access_duration');
        
        if (customDate) {
            // Show warning that custom date overrides duration
            if (!$('.wc-learndash-custom-date-warning').length) {
                $(this).closest('.form-field').after(
                    '<div class="wc-learndash-duration-info wc-learndash-custom-date-warning">' +
                    '<strong>Note:</strong> Custom end date will override the duration setting above.' +
                    '</div>'
                );
            }
        } else {
            $('.wc-learndash-custom-date-warning').remove();
        }
    });
    
    // Course selection enhancement
    if ($('#_learndash_courses').length) {
        enhanceCourseSelection();
    }
    
    // Initialize on page load
    $('#_learndash_access_duration').trigger('change');
    $('#_learndash_custom_end_date').trigger('change');
    
    function getDurationInfo(duration) {
        var info = {
            'paused_2weeks': 'User gets 2 weeks access when subscription is activated',
            'trial_2weeks': '2 weeks from purchase date',
            'access_1month': '1 month (30 days) from purchase date',
            'access_1year': '1 year (365 days) from purchase date'
        };
        
        return info[duration] || 'Custom duration';
    }
    
    function enhanceCourseSelection() {
        var courseSelect = $('#_learndash_courses');
        var selectedCourses = courseSelect.val() || [];
        
        // Create preview area
        if (!$('.wc-learndash-course-preview').length) {
            courseSelect.after('<div class="wc-learndash-course-preview"></div>');
        }
        
        courseSelect.on('change', function() {
            updateCoursePreview();
        });
        
        updateCoursePreview();
    }
    
    function updateCoursePreview() {
        var courseSelect = $('#_learndash_courses');
        var selectedCourses = courseSelect.val() || [];
        var previewDiv = $('.wc-learndash-course-preview');
        
        if (selectedCourses.length > 0) {
            var html = '<div class="wc-learndash-access-preview">';
            html += '<strong>Selected Courses (' + selectedCourses.length + '):</strong><br>';
            
            selectedCourses.forEach(function(courseId) {
                var courseText = $('#_learndash_courses option[value="' + courseId + '"]').text();
                html += '• ' + courseText + '<br>';
            });
            
            html += '</div>';
            previewDiv.html(html);
        } else {
            previewDiv.html('<div class="wc-learndash-access-preview">No courses selected</div>');
        }
    }
    
    // Add validation before saving
    $('form#post').on('submit', function(e) {
        var duration = $('#_learndash_access_duration').val();
        var courses = $('#_learndash_courses').val();
        
        if (duration && (!courses || courses.length === 0)) {
            if (!confirm('You have set an access duration but no courses are selected. Users will get access duration metadata but no actual course enrollment. Continue?')) {
                e.preventDefault();
                return false;
            }
        }
        
        if (courses && courses.length > 0 && !duration && !$('#_learndash_custom_end_date').val()) {
            if (!confirm('You have selected courses but no access duration or end date. Users will get permanent access to these courses. Continue?')) {
                e.preventDefault();
                return false;
            }
        }
    });
    
    // Add real-time preview for date calculations
    function addDatePreview() {
        var duration = $('#_learndash_access_duration').val();
        var customDate = $('#_learndash_custom_end_date').val();
        
        if (duration || customDate) {
            var endDate = calculateEndDate(duration, customDate);
            var previewText = 'Access will end: ' + endDate;
            
            if (!$('.wc-learndash-date-preview').length) {
                $('#_learndash_custom_end_date').closest('.form-field').after(
                    '<div class="wc-learndash-duration-info wc-learndash-date-preview">' +
                    previewText +
                    '</div>'
                );
            } else {
                $('.wc-learndash-date-preview').html(previewText);
            }
        } else {
            $('.wc-learndash-date-preview').remove();
        }
    }
    
    function calculateEndDate(duration, customDate) {
        if (customDate) {
            return new Date(customDate).toLocaleDateString();
        }
        
        var now = new Date();
        var endDate;
        
        switch (duration) {
            case 'paused_2weeks':
            case 'trial_2weeks':
                endDate = new Date(now.getTime() + (14 * 24 * 60 * 60 * 1000));
                break;
            case 'access_1month':
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, now.getDate());
                break;
            case 'access_1year':
                endDate = new Date(now.getFullYear() + 1, now.getMonth(), now.getDate());
                break;
            default:
                return 'No expiration';
        }
        
        return endDate.toLocaleDateString();
    }
    
    // Update date preview when values change
    $('#_learndash_access_duration, #_learndash_custom_end_date').on('change', addDatePreview);
    
    // Add helpful tooltips
    if (typeof tippy !== 'undefined') {
        tippy('[data-tip]', {
            content: function(reference) {
                return reference.getAttribute('data-tip');
            }
        });
    }
    
    // Add copy functionality for debugging
    if ($('.wc-learndash-debug-info').length) {
        $('.wc-learndash-debug-info').on('click', function() {
            var debugText = $(this).text();
            navigator.clipboard.writeText(debugText).then(function() {
                alert('Debug info copied to clipboard');
            });
        });
    }
    
    console.log('WC LearnDash Access Manager: Admin scripts loaded');
});
