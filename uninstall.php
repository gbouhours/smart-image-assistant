<?php
/**
 * Uninstall cleanup for Smart Image Assistant
 *
 * Deletes plugin options on uninstall.
 *
 * @package Smart_Image_Assistant
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$option_name = 'smart_image_assistant_options';

if ( is_multisite() ) {
    $sites = get_sites( [ 'fields' => 'ids' ] );
    if ( is_array( $sites ) ) {
        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );
            delete_option( $option_name );
            restore_current_blog();
        }
    }
} else {
    delete_option( $option_name );
}