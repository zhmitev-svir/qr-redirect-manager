<?php
defined( 'ABSPATH' ) || exit;

// ── Register rewrite rule + query var ───────────────────────────

function qrm_add_rewrite_rules() {
    add_rewrite_rule( '^go/?$', 'index.php?qrm_go=1', 'top' );
}
add_action( 'init', 'qrm_add_rewrite_rules' );

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'qrm_go';
    $vars[] = 'code';
    return $vars;
} );

// ── Handle the redirect via template_redirect (safe, headers not sent) ──

add_action( 'template_redirect', 'qrm_handle_redirect' );

function qrm_handle_redirect() {
    if ( ! get_query_var( 'qrm_go' ) ) return;

    $code = sanitize_key( get_query_var( 'code' ) );

    // Fallback: also check $_GET in case query var didn't populate
    if ( empty( $code ) ) {
        $code = sanitize_key( $_GET['code'] ?? '' );
    }

    if ( empty( $code ) ) {
        wp_redirect( home_url(), 302 );
        exit;
    }

    $record = qrm_get_code_by_code( $code );

    if ( ! $record ) {
        wp_redirect( home_url(), 302 );
        exit;
    }

    qrm_log_scan( $record->id );

    $destination = qrm_resolve_destination( $record );

    if ( ! $destination ) {
        wp_redirect( home_url(), 302 );
        exit;
    }

    wp_redirect( $destination, 302 );
    exit;
}
