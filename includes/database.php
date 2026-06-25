<?php
defined( 'ABSPATH' ) || exit;

function qrm_create_tables() {
    global $wpdb;
    $charset     = $wpdb->get_charset_collate();
    $codes_table = $wpdb->prefix . 'qrm_codes';
    $logs_table  = $wpdb->prefix . 'qrm_scan_logs';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // dbDelta requires each CREATE TABLE as a separate call
    dbDelta( "CREATE TABLE IF NOT EXISTS $codes_table (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code       VARCHAR(100) NOT NULL,
        label      VARCHAR(255) NOT NULL DEFAULT '',
        dest_type  VARCHAR(10)  NOT NULL DEFAULT 'page',
        page_id    BIGINT UNSIGNED DEFAULT NULL,
        custom_url TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY code (code)
    ) $charset;" );

    dbDelta( "CREATE TABLE IF NOT EXISTS $logs_table (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code_id    INT UNSIGNED NOT NULL,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY code_id (code_id),
        KEY scanned_at (scanned_at)
    ) $charset;" );
}

function qrm_tables_exist() {
    global $wpdb;
    $codes = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}qrm_codes'" );
    $logs  = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}qrm_scan_logs'" );
    return [ 'codes' => ! empty( $codes ), 'logs' => ! empty( $logs ) ];
}

// ── CRUD ─────────────────────────────────────────────────────────

function qrm_get_all_codes() {
    global $wpdb;
    $c = $wpdb->prefix . 'qrm_codes';
    $l = $wpdb->prefix . 'qrm_scan_logs';
    return $wpdb->get_results(
        "SELECT c.*, COUNT(l.id) AS scan_count, MAX(l.scanned_at) AS last_scan
         FROM $c c LEFT JOIN $l l ON l.code_id = c.id
         GROUP BY c.id ORDER BY c.label ASC"
    );
}

function qrm_get_code_by_id( $id ) {
    global $wpdb;
    $t = $wpdb->prefix . 'qrm_codes';
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
}

function qrm_get_code_by_code( $code ) {
    global $wpdb;
    $t = $wpdb->prefix . 'qrm_codes';
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE code = %s", $code ) );
}

function qrm_save_code( $data, $id = null ) {
    global $wpdb;
    $table = $wpdb->prefix . 'qrm_codes';

    $row = [
        'code'       => sanitize_key( $data['code'] ),
        'label'      => sanitize_text_field( $data['label'] ),
        'dest_type'  => $data['dest_type'] === 'url' ? 'url' : 'page',
        'page_id'    => $data['dest_type'] === 'page' ? intval( $data['page_id'] ) : null,
        'custom_url' => $data['dest_type'] === 'url'  ? esc_url_raw( $data['custom_url'] ) : null,
    ];

    $formats = [ '%s', '%s', '%s', '%d', '%s' ];

    if ( $id ) {
        $result = $wpdb->update( $table, $row, [ 'id' => $id ], $formats, [ '%d' ] );
        return ( $result !== false ) ? $id : false;
    } else {
        $result = $wpdb->insert( $table, $row, $formats );
        return $result ? $wpdb->insert_id : false;
    }
}

function qrm_delete_code( $id ) {
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'qrm_codes',     [ 'id'      => $id ], [ '%d' ] );
    $wpdb->delete( $wpdb->prefix . 'qrm_scan_logs', [ 'code_id' => $id ], [ '%d' ] );
}

function qrm_resolve_destination( $record ) {
    if ( $record->dest_type === 'page' && $record->page_id ) {
        return get_permalink( $record->page_id ) ?: home_url();
    }
    if ( $record->dest_type === 'url' && $record->custom_url ) {
        return $record->custom_url;
    }
    return null;
}

function qrm_log_scan( $code_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'qrm_scan_logs';
    $ip    = sanitize_text_field( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '' )[0] );
    $ua    = sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ) );
    $wpdb->insert( $table, [
        'code_id'    => $code_id,
        'scanned_at' => current_time( 'mysql' ),
        'ip_address' => $ip,
        'user_agent' => $ua,
    ], [ '%d', '%s', '%s', '%s' ] );
}

function qrm_get_scan_logs( $code_id, $limit = 200 ) {
    global $wpdb;
    $t = $wpdb->prefix . 'qrm_scan_logs';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $t WHERE code_id = %d ORDER BY scanned_at DESC LIMIT %d",
        $code_id, $limit
    ) );
}
