<?php
/*
* Plugin Name: <a href="https://mrlab.altervista.org/community/wpforo-plugin/wpforo-birthday-widget-plugin/" target="_blank">WPForo Birthday Widget</a>
* Description: A widget to display user birthdays in wpForo.
* Author: <a href="https://mrlab.altervista.org/community" target="_blank">MRLab Community</a>
* Version: 1.5
* Text Domain: WPForo-birthday-widget
*/

// Enqueue FontAwesome and custom admin CSS
function wpforo_birthday_enqueue_admin_assets() {
    // Enqueue FontAwesome
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css', array(), null);

    // Enqueue custom admin CSS
    wp_enqueue_style('wpforo-birthday-admin-styles', plugin_dir_url(__FILE__) . 'css/wpforo-birthday-admin-styles.css');
}
add_action('admin_enqueue_scripts', 'wpforo_birthday_enqueue_admin_assets');

// Add custom field to user profile
function wpforo_birthday_add_custom_user_profile_fields($user) {
    ?>
    <h3><?php _e('Birthday Information', 'wpforo-birthday'); ?></h3>
    <table class="form-table">
        <tr>
            <th>
                <label for="birthday"><?php _e('Birthday', 'wpforo-birthday'); ?></label>
            </th>
            <td>
                <input type="date" name="birthday" id="birthday" value="<?php echo esc_attr(get_the_author_meta('birthday', $user->ID)); ?>" class="regular-text" /><br />
                <span class="description"><?php _e('Please enter your birthday.', 'wpforo-birthday'); ?></span>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'wpforo_birthday_add_custom_user_profile_fields');
add_action('edit_user_profile', 'wpforo_birthday_add_custom_user_profile_fields');

function wpforo_birthday_save_custom_user_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }
    update_user_meta($user_id, 'birthday', $_POST['birthday']);
}
add_action('personal_options_update', 'wpforo_birthday_save_custom_user_profile_fields');
add_action('edit_user_profile_update', 'wpforo_birthday_save_custom_user_profile_fields');

// Enqueue scripts and styles
function wpforo_birthday_enqueue_scripts() {
    if (is_user_logged_in()) {
        wp_enqueue_script('wpforo-birthday-ajax', plugin_dir_url(__FILE__) . 'js/wpforo-birthday-ajax.js', array('jquery'), null, true);

        // Localize script to pass PHP variables to JavaScript
        wp_localize_script('wpforo-birthday-ajax', 'wpforo_birthday_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpforo_birthday_nonce'),
            'user_id' => get_current_user_id()
        ));
    }
}
add_action('wp_enqueue_scripts', 'wpforo_birthday_enqueue_scripts');

// Enqueue custom styles
function wpforo_birthday_enqueue_styles() {
    wp_enqueue_style('wpforo-birthday-styles', plugin_dir_url(__FILE__) . 'css/wpforo-birthday-styles.css');
}
add_action('wp_enqueue_scripts', 'wpforo_birthday_enqueue_styles');

// Handle AJAX request to update birthday
function wpforo_birthday_update_ajax() {
    check_ajax_referer('wpforo_birthday_nonce', 'security');

    if (isset($_POST['birthday']) && isset($_POST['user_id'])) {
        $user_id = intval($_POST['user_id']);
        $birthday = sanitize_text_field($_POST['birthday']);

        if (current_user_can('edit_user', $user_id)) {
            update_user_meta($user_id, 'birthday', $birthday);
            wp_send_json_success(__('Birthday updated successfully!', 'wpforo-birthday'));
        } else {
            wp_send_json_error(__('You do not have permission to edit this user.', 'wpforo-birthday'));
        }
    } else {
        wp_send_json_error(__('Invalid request.', 'wpforo-birthday'));
    }
}
add_action('wp_ajax_wpforo_birthday_update', 'wpforo_birthday_update_ajax');

// Handle AJAX request to reset birthday
function wpforo_birthday_reset_ajax() {
    check_ajax_referer('wpforo_birthday_nonce', 'security');

    if (isset($_POST['user_id'])) {
        $user_id = intval($_POST['user_id']);

        if (current_user_can('edit_user', $user_id)) {
            delete_user_meta($user_id, 'birthday');
            wp_send_json_success(__('Birthday reset successfully!', 'wpforo-birthday'));
        } else {
            wp_send_json_error(__('You do not have permission to edit this user.', 'wpforo-birthday'));
        }
    } else {
        wp_send_json_error(__('Invalid request.', 'wpforo-birthday'));
    }
}
add_action('wp_ajax_wpforo_birthday_reset', 'wpforo_birthday_reset_ajax');

// Register the widget
class WPForo_Birthday_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'wpforo_birthday_widget',
            __('WPForo Birthday Widget', 'wpforo-birthday'),
            array('description' => __('Displays users birthdays.', 'wpforo-birthday'))
        );
    }

    public function widget($args, $instance) {
        $title = apply_filters('widget_title', $instance['title']);

        // Query users who have a birthday today
        global $wpdb;
        $today = date('m-d'); // Get today's month and day
        $users = get_users(array(
            'meta_query' => array(
                array(
                    'key' => 'birthday',
                    'value' => $today,
                    'compare' => 'LIKE'
                )
            )
        ));

        if (empty($users)) {
            return; // Exit if no users have their birthday today
        }

        echo $args['before_widget'];
        if (!empty($title)) {
            echo $args['before_title'] . $title . $args['after_title'];
        }

        // Get image settings
        $image_url = get_option('wpforo_birthday_image_url', '');

        // Add balloons image (default position: top-right)
        if ($image_url) {
            echo '<div class="wpforo-balloons wpforo-balloons-top-right">';
            echo '<img src="' . esc_url($image_url) . '" alt="Happy Birthday Balloons" />';
            echo '</div>';
        }

        echo '<ul class="wpforo-birthday-widget">';
        foreach ($users as $user) {
            $display_name = $user->display_name;
            $birthday = get_user_meta($user->ID, 'birthday', true);

            if (strpos($birthday, $today) !== false) {
                $avatar = get_avatar($user->ID, 32);
                $profile_link = get_author_posts_url($user->ID);
                $age = !empty($birthday) ? date_diff(date_create($birthday), date_create('today'))->y : 'N/A';

                echo '<li class="wpforo-birthday-user">';
                echo '<span class="wpforo-avatar">' . $avatar . '</span>';
                echo '<span class="wpforo-info">';
                echo '<a href="' . esc_url($profile_link) . '">' . esc_html($display_name) . '</a>';
                echo '<span class="wpforo-age">' . esc_html($age) . '</span>';
                echo '</span>';
                echo '</li>';
            }
        }
        echo '</ul>';

        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : __('New title', 'wpforo-birthday');
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php _e('Title:', 'wpforo-birthday'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
        return $instance;
    }
}

function wpforo_register_birthday_widget() {
    register_widget('WPForo_Birthday_Widget');
}
add_action('widgets_init', 'wpforo_register_birthday_widget');

// Add shortcode to display birthday field
function wpforo_birthday_shortcode() {
    ob_start();
    ?>
    <form id="wpforo-birthday-form">
        <h3><?php _e('Update Your Birthday', 'wpforo-birthday'); ?></h3>
        <p>
            <label for="birthday"><?php _e('Birthday:', 'wpforo-birthday'); ?></label><br />
            <input type="date" name="birthday" id="wpforo_birthday_date" value="<?php echo esc_attr(get_the_author_meta('birthday', get_current_user_id())); ?>" class="regular-text" />
        </p>
        <p>
            <button type="button" id="update-birthday" class="button button-primary"><?php _e('Update Birthday', 'wpforo-birthday'); ?></button>
            <button type="button" id="reset-birthday" class="button"><?php _e('Reset Birthday', 'wpforo-birthday'); ?></button>
        </p>
        <div id="wpforo-birthday-message"></div>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('wpforo_birthday', 'wpforo_birthday_shortcode');

// Add admin menu item
function wpforo_birthday_add_admin_menu() {
    // Check if WPForo exists and has a menu page
    $wpforo_exists = false;

    global $submenu;
    if (isset($submenu['wpforo'])) {
        $wpforo_exists = true;
    }

    if ($wpforo_exists) {
        // Add a single submenu page under WPForo
        add_submenu_page(
            'wpforo', // Parent slug (WPForo)
            __('WPForo Birthday Widget Settings', 'wpforo-birthday'), // Page title
            __('Settings', 'wpforo-birthday'), // Menu title
            'manage_options', // Capability
            'wpforo-birthday-settings', // Menu slug
            'wpforo_birthday_settings_page' // Callback function
        );
    } else {
        // Fallback: Create a top-level menu if WPForo doesn't exist
        add_menu_page(
            __('WPForo Birthday Widget', 'wpforo-birthday'), // Page title
            __('WPForo Birthday', 'wpforo-birthday'), // Menu title
            'manage_options', // Capability
            'wpforo-birthday', // Menu slug
            'wpforo_birthday_settings_page', // Callback function
            'dashicons-cake', // Icon for the menu
            24 // Correct position (just after WPForo at position 24)
        );
    }
}
add_action('admin_menu', 'wpforo_birthday_add_admin_menu');

// Display settings page (includes instructions, language, and image settings)
function wpforo_birthday_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('WPForo Birthday Widget Settings', 'wpforo-birthday'); ?></h1>

        <?php
        // Display success message if settings are saved
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true') {
            echo '<div class="updated"><p>' . __('Settings saved successfully!', 'wpforo-birthday') . '</p></div>';
        }
        ?>

        <!-- Instructions -->
        <h2><?php _e('- Installation -', 'wpforo-birthday'); ?></h2>
        <ol>
            <li><?php _e('Upload the plugin files to your plugin directory.', 'wpforo-birthday'); ?></li>
            <li><?php _e('Go to the administration panel and activate the plugin.', 'wpforo-birthday'); ?></li>
        </ol>

        <h2><?php _e('- First Use -', 'wpforo-birthday'); ?></h2>
        <ol>
            <li><?php _e('Insert the shortcode [wpforo_birthday] where you want the birthday field to appear.', 'wpforo-birthday'); ?></li>
            <li><?php _e('Go to the administration panel → Appearance → Widgets, and select the "WPForo Birthday Widget" to place it where you want the public notice of who is celebrating their birthday to appear.', 'wpforo-birthday'); ?></li>
            <li><?php _e('Done!', 'wpforo-birthday'); ?></li>
        </ol>

        <hr />

        <!-- Language and Image Settings -->
        <form method="post" action="options.php">
            <?php
            settings_fields('wpforo-birthday-settings-group');
            do_settings_sections('wpforo-birthday');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register all settings
function wpforo_birthday_register_settings() {
    // Language settings
    register_setting('wpforo-birthday-settings-group', 'wpforo_birthday_language');
    add_settings_section('wpforo_birthday_language_section', __('Language Settings', 'wpforo-birthday'), '__return_empty_string', 'wpforo-birthday');
    add_settings_field(
        'wpforo_birthday_language_field',
        __('Select Language', 'wpforo-birthday'),
        'wpforo_birthday_language_callback',
        'wpforo-birthday',
        'wpforo_birthday_language_section'
    );

    // Image settings
    register_setting('wpforo-birthday-settings-group', 'wpforo_birthday_image_url');

    add_settings_section(
        'wpforo_birthday_image_settings_section',
        __('Birthday Widget Image Settings', 'wpforo-birthday'),
        '__return_empty_string',
        'wpforo-birthday'
    );

    // Image URL field
    add_settings_field(
        'wpforo_birthday_image_url_field',
        __('Image URL', 'wpforo-birthday'),
        'wpforo_birthday_image_url_callback',
        'wpforo-birthday',
        'wpforo_birthday_image_settings_section'
    );
}
add_action('admin_init', 'wpforo_birthday_register_settings');

// Language callback function
function wpforo_birthday_language_callback() {
    $languages = array(
        'en' => __('English', 'wpforo-birthday'),
        'it' => __('Italian', 'wpforo-birthday'),
        'de' => __('German', 'wpforo-birthday'),
        'fr' => __('French', 'wpforo-birthday'),
        'es' => __('Spanish', 'wpforo-birthday'),
        'ar' => __('Arabic', 'wpforo-birthday'),
        'ru' => __('Russian', 'wpforo-birthday')
    );

    $selected_language = get_option('wpforo_birthday_language', 'en'); // Default to English
    ?>
    <select name="wpforo_birthday_language" id="wpforo_birthday_language">
        <?php foreach ($languages as $code => $name): ?>
            <option value="<?php echo esc_attr($code); ?>" <?php selected($selected_language, $code); ?>>
                <?php echo esc_html($name); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

// Callback function for image URL input
function wpforo_birthday_image_url_callback() {
    $image_url = get_option('wpforo_birthday_image_url', '');
    ?>
    <input type="text" name="wpforo_birthday_image_url" id="wpforo_birthday_image_url" value="<?php echo esc_attr($image_url); ?>" placeholder="https://" style="width: 100%; max-width: 400px;" />
    <p style="font-size: smaller;"><?php _e('Enter the direct URL of the image you want to display in the birthday widget.', 'wpforo-birthday'); ?></p>
    <?php
}

// Load translations based on selected language
function wpforo_birthday_load_textdomain() {
    $language = get_option('wpforo_birthday_language', 'en'); // Get the selected language
    load_plugin_textdomain('wpforo-birthday', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'wpforo_birthday_load_textdomain');