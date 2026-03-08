<?php
/**
 * Plugin Name: Afro Love Life
 * Description: Custom dating modal + features for Afro Love Life.
 * Version: 1.0
 * Author: Felix Cordero Jr.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shortcode: [afl_register_modal]
 */
function afl_register_modal_shortcode() {
    ob_start();

    $template = plugin_dir_path( __FILE__ ) . 'templates/afl-register-modal.php';

    if ( file_exists( $template ) ) {
        include $template;
    } else {
        echo '<!-- Afro Love Life: modal template missing -->';
    }

    return ob_get_clean();
}
add_shortcode( 'afl_register_modal', 'afl_register_modal_shortcode' );

function afl_handle_register_user() {

    if ( ! isset( $_POST['afl_register_nonce'] ) ||
         ! wp_verify_nonce( $_POST['afl_register_nonce'], 'afl_register_user' ) ) {
        wp_die( 'Security check failed.' );
    }

    $email      = sanitize_email( $_POST['email'] ?? '' );
    $password   = $_POST['password'] ?? '';
    $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
    $gender     = sanitize_text_field( $_POST['gender'] ?? '' );
    $looking_for= sanitize_text_field( $_POST['looking_for'] ?? '' );
    $age        = intval( $_POST['age'] ?? 0 );

    // Kick out under 18
    if ( $age < 18 ) {
        wp_safe_redirect( site_url( '/age-restriction/' ) );
        exit;
    }

    // Create username from email
    $username = sanitize_user( current( explode( '@', $email ) ) );

    $user_id = wp_insert_user([
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => $password,
        'first_name'   => $first_name,
        'display_name' => $first_name,
        'role'         => 'subscriber',
    ]);

    if ( is_wp_error( $user_id ) ) {
        wp_die( $user_id->get_error_message() );
    }

    // Save meta
    update_user_meta( $user_id, 'afl_gender',      $gender );
    update_user_meta( $user_id, 'afl_looking_for', $looking_for );
    update_user_meta( $user_id, 'afl_age',         $age );

    // Auto-login
    wp_set_auth_cookie( $user_id, true );
    wp_set_current_user( $user_id );

    // Redirect to Step 2
    wp_safe_redirect( site_url( '/create-profile/' ) );
    exit;
}

add_action( 'admin_post_nopriv_afl_register_user', 'afl_handle_register_user' );
add_action( 'admin_post_afl_register_user',        'afl_handle_register_user' );