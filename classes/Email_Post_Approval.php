<?php
if (!function_exists('add_filter')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit();
}

class Email_Post_Approval {
    var $post_status_types;
    var $email_fields;

    // Initialize plugin
    public function __construct() {
        add_action('save_post', [$this, 'send_email']);
    }

    // Fire off function when plugin is activated
    public function activation() {
        add_option('epa_send_to', get_bloginfo('admin_email'));
        add_option('epa_post_statuses', ['pending']);
        add_option('epa_email_fields', ['title', 'post_author', 'post_date', 'categories', 'tags', 'post_meta', 'body', 'thumbnail']);
    }

    // Fire off function when plugin is deactivated
    public function deactivation() {
        delete_option('epa_send_to');
        delete_option('epa_post_statuses');
        delete_option('epa_email_fields');
    }

    // Fire off function if a post is saved
    public function send_email($post_ID) {
        $post_data = get_page($post_ID);

        // If post is saved via autosave, post is a revision or if it is not in the designated list of post status types, stop running.
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || $post_data->post_type=="revision" || ! is_array(get_option('epa_post_statuses')) || ((array_search($post_data->post_status, get_option('epa_post_statuses')) == FALSE) && (array_search($post_data->post_status, get_option('epa_post_statuses')) !== 0))) { return false; }

        // Get the needed information
        $post_taxonomies = get_the_taxonomies($post_ID);
        $post_author_email = get_the_author_meta('user_email', $post_data->post_author);

        // Check to see if there is already a hash
        $existing_hash = get_post_meta($post_ID, 'imagepress_approve_key', true);
        if ($existing_hash) {
            $post_hash = $existing_hash;
        } else {
            // Otherwise, generate and return a hash
            $post_hash = sha1($post_ID * time());
            add_post_meta($post_ID, 'imagepress_approve_key', $post_hash);
        }

        $post_meta = get_post_meta($post_ID);

        // Clean up the taxonomies print out for the email message
        if (isset($post_taxonomies['category'])) {
            $post_taxonomies['category'] = str_replace('Categories: ', '', $post_taxonomies['category']);
        } else {
            $post_taxonomies['category'] = '';
        }

        $message = '';

        // Generate email message
        if (in_array('title', get_option('epa_email_fields')) !== false) {
            $message .= '<b>Title:</b> ' . $post_data->post_title . '<br>';
        }
        if (in_array('post_author', get_option('epa_email_fields')) !== false) {
            $message .= '<b>Author:</b> ' . get_the_author_meta('display_name', $post_data->post_author) . '<br>';
        }
        if (in_array('post_date', get_option('epa_email_fields')) !== false) {
            $message .= '<b>Post Date:</b> ' . $post_data->post_date . '<br>';
        }
        if (in_array('categories', get_option('epa_email_fields')) !== false) {
            $message .= '<b>Categories:</b> '. $post_taxonomies['category'] . '<br>';
        }
        if (in_array('thumbnail', get_option('epa_email_fields')) !== false) {
            $message .= '<b>Featured image:</b> ' . get_the_post_thumbnail_url($post_data->ID) . '<br>';
            $message .= get_the_post_thumbnail($post_data->ID, 'large') . '<br>';
        }
        if (in_array('body', get_option('epa_email_fields')) !== false) {
            $message .= '<br><b>Post Body:</b><br>' . str_replace('<!--more-->', '&lt;!--more--&gt;', $post_data->post_content) . '<br>';
        }

        $message .= '<br>----------------------------------------------------<br>';
        $message .= '<p>
            <a href="' . get_bloginfo('url') . '/?approve_post=true&approve_key=' . $post_hash . '&default_author=false">Approve as ' . get_the_author_meta('display_name', $post_data->post_author) . '</a>
             or
            <a href="' . get_bloginfo('url') . '/?approve_post=true&approve_key=' . $post_hash . '&default_author=true">Approve as ' . get_the_author_meta('display_name', get_option('epa_default_author')) . '</a>
        </p>
        <p><small>This email was generated on ' . date('m/d/Y h:i:s a', time()) . '.</p>';

	    // Change From to site's name & admin email, author's email as the Reply-To email, set HTML header and send email.
	    /**
	    $headers[] = 'From: "' . get_bloginfo('name') . '" <' .  get_bloginfo('admin_email') . '>';
	    $headers[] = 'Reply-To: '. $post_author_email;
	    /**/

        add_filter('wp_mail_content_type', create_function('', 'return "text/html";'));
        wp_mail(get_option('epa_send_to'), 'Post Needing Approval: '. $post_data->post_title, $message, $headers);
    }
}

$email_post_approval = new Email_Post_Approval;
register_activation_hook(__FILE__, ['Email_Post_Approval', 'activation']);
register_deactivation_hook(__FILE__, ['Email_Post_Approval', 'deactivation']);

add_action('init', 'epa_approve_post', 0);

function epa_approve_post() {
    // If URL includes "approve_post" argument, check the key and approve post if key exists.
    if (isset($_GET['approve_post'])) {
        $args = [
            'post_type' => get_option('ip_slug'),
            'posts_per_page' => 1,
            'post_status' => ['publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash'],
            'meta_query' => [
                [
                    'key' => 'imagepress_approve_key',
                    'value' => sanitize_text_field($_GET['approve_key'])
                ]
            ]
        ];
        $get_post_to_approve = get_posts($args);
        $change_author = sanitize_text_field($_GET['default_author']);

        // If key exists, publish post, delete key, and redirect to published post.
        if ($get_post_to_approve) {
            $the_post = get_post($get_post_to_approve[0]->ID, 'ARRAY_A');
            $the_post['post_status'] = 'future';
            if ($change_author === 'true') {
                $the_post['post_author'] = get_option('epa_default_author');
            }
            wp_update_post($the_post);
            delete_post_meta($get_post_to_approve[0]->ID, 'imagepress_approve_key');
            wp_redirect(get_permalink($get_post_to_approve[0]->ID), 301);
            exit;
        } else {
            // If key doesn't exist, display an alert saying post is not found.
            if (!defined('MULTISITE')) {
                echo '<script>alert(\'The post you are attempting to approve is not found.\');</script>';
            }
        }
    }
}
