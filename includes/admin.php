<?php
defined( 'ABSPATH' ) || exit;

// ── Menu ─────────────────────────────────────────────────────────

add_action( 'admin_menu', function() {
    add_menu_page(
        'QR Redirects', 'QR Redirects', 'manage_options',
        'qrm', 'qrm_page_router',
        'dashicons-qrcode', 30
    );
} );

// ── Enqueue QR library ────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'qrm' ) === false ) return;
    wp_enqueue_script( 'qrcodejs', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', [], '1.0.0', true );
} );

// ── Form POST handlers ────────────────────────────────────────────

add_action( 'admin_post_qrm_save',          'qrm_handle_save' );
add_action( 'admin_post_qrm_delete',        'qrm_handle_delete' );
add_action( 'admin_post_qrm_install_tables','qrm_handle_install_tables' );

function qrm_handle_save() {
    check_admin_referer( 'qrm_form' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['qrm_id'] ?? 0 );

    $data = [
        'code'       => $_POST['qrm_code']       ?? '',
        'label'      => $_POST['qrm_label']      ?? '',
        'dest_type'  => $_POST['qrm_dest_type']  ?? 'page',
        'page_id'    => $_POST['qrm_page_id']    ?? 0,
        'custom_url' => $_POST['qrm_custom_url'] ?? '',
    ];

    if ( empty( $data['code'] ) ) {
        wp_redirect( admin_url( 'admin.php?page=qrm&qrm_action=' . ( $id ? 'edit' : 'new' ) . '&qrm_id=' . $id . '&qrm_error=code_required' ) );
        exit;
    }

    if ( ! $id ) {
        $existing = qrm_get_code_by_code( $data['code'] );
        if ( $existing ) {
            wp_redirect( admin_url( 'admin.php?page=qrm&qrm_action=new&qrm_error=duplicate' ) );
            exit;
        }
    }

    $saved_id = qrm_save_code( $data, $id ?: null );

    if ( $saved_id === false ) {
        // Save failed — capture wpdb error and redirect with it
        global $wpdb;
        $err = urlencode( $wpdb->last_error ?: 'Unknown database error' );
        wp_redirect( admin_url( 'admin.php?page=qrm&qrm_action=' . ( $id ? 'edit' : 'new' ) . '&qrm_id=' . $id . '&qrm_error=db&dberr=' . $err ) );
        exit;
    }

    wp_redirect( admin_url( 'admin.php?page=qrm&qrm_action=edit&qrm_id=' . $saved_id . '&saved=1' ) );
    exit;
}

function qrm_handle_delete() {
    check_admin_referer( 'qrm_form' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    $id = intval( $_POST['qrm_id'] ?? 0 );
    if ( $id ) qrm_delete_code( $id );
    wp_redirect( admin_url( 'admin.php?page=qrm&deleted=1' ) );
    exit;
}

function qrm_handle_install_tables() {
    check_admin_referer( 'qrm_install' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    qrm_create_tables();
    // Also reset db version so main plugin re-runs flush
    delete_option( 'qrm_db_version' );
    wp_redirect( admin_url( 'admin.php?page=qrm&tables_installed=1' ) );
    exit;
}

// ── Page router ───────────────────────────────────────────────────

function qrm_page_router() {
    $action = sanitize_key( $_GET['qrm_action'] ?? 'list' );
    $id     = intval( $_GET['qrm_id'] ?? 0 );

    switch ( $action ) {
        case 'edit':
        case 'new':  qrm_render_form( $id ); break;
        case 'logs': qrm_render_logs( $id ); break;
        case 'diag': qrm_render_diag();      break;
        default:     qrm_render_list();
    }
}

// ── Styles ────────────────────────────────────────────────────────

function qrm_styles() { ?>
<style>
:root {
    --qrm-navy:   #1a2744;
    --qrm-gold:   #c9922a;
    --qrm-light:  #f4f6fb;
    --qrm-border: #dde1ea;
    --qrm-text:   #2c3147;
    --qrm-muted:  #6b7280;
    --qrm-green:  #16a34a;
    --qrm-red:    #dc2626;
}
.qrm-wrap { max-width:1100px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; color:var(--qrm-text); }
.qrm-header { display:flex; align-items:center; justify-content:space-between; padding:24px 0 16px; border-bottom:2px solid var(--qrm-navy); margin-bottom:24px; }
.qrm-header h1 { margin:0; font-size:22px; font-weight:700; color:var(--qrm-navy); display:flex; align-items:center; gap:10px; }
.qrm-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:5px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; border:none; transition:opacity .15s; line-height:1.4; }
.qrm-btn:hover { opacity:.85; }
.qrm-btn-primary { background:var(--qrm-navy); color:#fff !important; }
.qrm-btn-gold    { background:var(--qrm-gold);  color:#fff !important; }
.qrm-btn-outline { background:transparent; color:var(--qrm-navy) !important; border:1.5px solid var(--qrm-navy); }
.qrm-btn-danger  { background:var(--qrm-red);   color:#fff !important; }
.qrm-btn-sm { padding:5px 10px; font-size:12px; }
.qrm-notice { padding:10px 16px; border-radius:4px; margin-bottom:18px; font-size:13px; }
.qrm-notice-success { background:#dcfce7; border-left:4px solid var(--qrm-green); color:#15803d; }
.qrm-notice-error   { background:#fee2e2; border-left:4px solid var(--qrm-red);   color:#b91c1c; }
.qrm-notice-warn    { background:#fef9c3; border-left:4px solid #ca8a04;           color:#854d0e; }
.qrm-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--qrm-border); border-radius:6px; overflow:hidden; font-size:13px; }
.qrm-table th { background:var(--qrm-navy); color:#fff; padding:11px 14px; text-align:left; font-weight:600; font-size:12px; letter-spacing:.04em; text-transform:uppercase; }
.qrm-table td { padding:12px 14px; border-bottom:1px solid var(--qrm-border); vertical-align:middle; }
.qrm-table tr:last-child td { border-bottom:none; }
.qrm-table tr:hover td { background:var(--qrm-light); }
.qrm-code-badge { font-family:monospace; background:var(--qrm-light); border:1px solid var(--qrm-border); padding:3px 8px; border-radius:4px; font-size:12px; color:var(--qrm-navy); }
.qrm-empty { text-align:center; padding:60px 20px; background:#fff; border:1px solid var(--qrm-border); border-radius:6px; color:var(--qrm-muted); }
.qrm-card { background:#fff; border:1px solid var(--qrm-border); border-radius:6px; padding:28px 32px; margin-bottom:24px; }
.qrm-card h2 { margin:0 0 20px; font-size:16px; color:var(--qrm-navy); border-bottom:1px solid var(--qrm-border); padding-bottom:12px; }
.qrm-field { margin-bottom:20px; }
.qrm-field label { display:block; font-weight:600; font-size:13px; margin-bottom:6px; }
.qrm-field input[type=text], .qrm-field select { width:100%; max-width:500px; padding:9px 12px; border:1.5px solid var(--qrm-border); border-radius:5px; font-size:14px; box-sizing:border-box; }
.qrm-field input:focus, .qrm-field select:focus { border-color:var(--qrm-navy); outline:none; }
.qrm-hint { font-size:12px; color:var(--qrm-muted); margin-top:5px; }
.qrm-radio-row { display:flex; gap:24px; margin-bottom:14px; }
.qrm-radio-row label { font-weight:normal; display:flex; align-items:center; gap:6px; cursor:pointer; }
.qrm-form-actions { display:flex; gap:12px; align-items:center; margin-top:24px; padding-top:20px; border-top:1px solid var(--qrm-border); }
.qrm-qr-panel { background:var(--qrm-light); border:1px solid var(--qrm-border); border-radius:6px; padding:24px; text-align:center; }
.qrm-qr-panel h2 { margin:0 0 6px; font-size:15px; color:var(--qrm-navy); }
.qrm-qr-panel p { font-size:12px; color:var(--qrm-muted); margin:0 0 16px; }
#qrm-qr-canvas { display:flex; justify-content:center; margin-bottom:14px; min-height:200px; align-items:center; }
.qrm-qr-url { font-family:monospace; font-size:11px; word-break:break-all; background:#fff; border:1px solid var(--qrm-border); padding:8px; border-radius:4px; color:var(--qrm-navy); margin-bottom:12px; }
.qrm-grid { display:grid; grid-template-columns:1fr 280px; gap:24px; align-items:start; }
.qrm-log-table { width:100%; border-collapse:collapse; font-size:13px; }
.qrm-log-table th { background:var(--qrm-light); padding:9px 12px; text-align:left; font-weight:600; font-size:12px; border-bottom:2px solid var(--qrm-border); }
.qrm-log-table td { padding:9px 12px; border-bottom:1px solid var(--qrm-border); }
.qrm-badge-dest { font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600; }
.qrm-badge-page { background:#dbeafe; color:#1d4ed8; }
.qrm-badge-url  { background:#fef9c3; color:#854d0e; }
.qrm-diag-row { display:flex; gap:12px; align-items:center; padding:10px 0; border-bottom:1px solid var(--qrm-border); font-size:13px; }
.qrm-diag-row:last-child { border-bottom:none; }
.qrm-pill { display:inline-block; padding:2px 10px; border-radius:20px; font-size:12px; font-weight:700; }
.qrm-pill-ok  { background:#dcfce7; color:#15803d; }
.qrm-pill-err { background:#fee2e2; color:#b91c1c; }
</style>
<?php }

// ── List ──────────────────────────────────────────────────────────

function qrm_render_list() {
    $codes  = qrm_get_all_codes();
    $tables = qrm_tables_exist();
    qrm_styles();
    ?>
    <div class="qrm-wrap">
        <div class="qrm-header">
            <h1><span class="dashicons dashicons-qrcode"></span> QR Redirect Manager</h1>
            <div style="display:flex;gap:8px;">
                <a href="<?php echo admin_url('admin.php?page=qrm&qrm_action=diag'); ?>" class="qrm-btn qrm-btn-outline qrm-btn-sm">⚙ Diagnostics</a>
                <a href="<?php echo admin_url('admin.php?page=qrm&qrm_action=new'); ?>"  class="qrm-btn qrm-btn-primary">+ Add QR Code</a>
            </div>
        </div>

        <?php if ( ! $tables['codes'] || ! $tables['logs'] ) : ?>
        <div class="qrm-notice qrm-notice-error">
            <strong>Database tables are missing.</strong> The plugin cannot save anything until they are created.
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;margin-left:12px;">
                <?php wp_nonce_field('qrm_install'); ?>
                <input type="hidden" name="action" value="qrm_install_tables">
                <button type="submit" class="qrm-btn qrm-btn-danger qrm-btn-sm">Create Tables Now</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ( isset($_GET['tables_installed']) ) echo '<div class="qrm-notice qrm-notice-success">Database tables created. You can now save redirects.</div>'; ?>
        <?php if ( isset($_GET['deleted'])          ) echo '<div class="qrm-notice qrm-notice-success">QR redirect deleted.</div>'; ?>
        <?php if ( isset($_GET['saved'])            ) echo '<div class="qrm-notice qrm-notice-success">Saved successfully.</div>'; ?>

        <?php if ( empty( $codes ) ) : ?>
            <div class="qrm-empty">
                <p>No QR redirects yet.</p>
                <a href="<?php echo admin_url('admin.php?page=qrm&qrm_action=new'); ?>" class="qrm-btn qrm-btn-primary">Create your first one</a>
            </div>
        <?php else : ?>
        <table class="qrm-table">
            <thead><tr>
                <th>Label</th><th>Code</th><th>Destination</th><th>Scans</th><th>Last Scan</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $codes as $c ) :
                $dest = qrm_resolve_destination( $c ); ?>
                <tr>
                    <td><strong><?php echo esc_html($c->label); ?></strong></td>
                    <td><span class="qrm-code-badge"><?php echo esc_html($c->code); ?></span></td>
                    <td>
                        <span class="qrm-badge-dest <?php echo $c->dest_type==='page'?'qrm-badge-page':'qrm-badge-url'; ?>">
                            <?php echo $c->dest_type==='page'?'Page':'URL'; ?>
                        </span>
                        <?php if ($dest): ?>
                            <a href="<?php echo esc_url($dest); ?>" target="_blank" style="margin-left:6px;font-size:12px;color:#2271b1;"><?php echo esc_html(wp_trim_words($dest,5,'…')); ?></a>
                        <?php else: ?>
                            <em style="color:#9ca3af;margin-left:6px;font-size:12px;">Not set</em>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:700;color:var(--qrm-navy)"><?php echo intval($c->scan_count); ?></td>
                    <td style="color:var(--qrm-muted);font-size:12px;"><?php echo $c->last_scan ? esc_html(date_i18n('M j, Y g:i a',strtotime($c->last_scan))) : '—'; ?></td>
                    <td style="white-space:nowrap;">
                        <a href="<?php echo admin_url('admin.php?page=qrm&qrm_action=edit&qrm_id='.$c->id); ?>" class="qrm-btn qrm-btn-outline qrm-btn-sm">Edit</a>
                        <a href="<?php echo admin_url('admin.php?page=qrm&qrm_action=logs&qrm_id='.$c->id); ?>" class="qrm-btn qrm-btn-outline qrm-btn-sm">Logs</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

// ── Diagnostics ───────────────────────────────────────────────────

function qrm_render_diag() {
    global $wpdb;
    $tables  = qrm_tables_exist();
    $go_url  = home_url('/go/?code=test');
    $db_user = $wpdb->dbuser ?? 'unknown';
    $db_host = $wpdb->dbhost ?? 'unknown';
    $db_name = $wpdb->dbname ?? 'unknown';
    $last_err= $wpdb->last_error;

    // Test a direct insert and read-back
    $test_result = '';
    if ( $tables['codes'] ) {
        $t = $wpdb->prefix . 'qrm_codes';
        $code = 'qrm_diag_test_' . time();
        $ins  = $wpdb->insert( $t, [ 'code' => $code, 'label' => 'Diagnostic test', 'dest_type' => 'url', 'custom_url' => 'https://example.com' ], [ '%s','%s','%s','%s' ] );
        if ( $ins ) {
            $tid = $wpdb->insert_id;
            $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $t WHERE id=%d",$tid) );
            $wpdb->delete( $t, ['id'=>$tid], ['%d'] );
            $test_result = $row ? 'pass' : 'read_fail';
        } else {
            $test_result = 'insert_fail:' . $wpdb->last_error;
        }
    }

    qrm_styles();
    ?>
    <div class="qrm-wrap">
        <div class="qrm-header">
            <h1><span class="dashicons dashicons-admin-tools"></span> Diagnostics</h1>
            <a href="<?php echo admin_url('admin.php?page=qrm'); ?>" class="qrm-btn qrm-btn-outline">← Back</a>
        </div>

        <div class="qrm-card">
            <h2>Database Tables</h2>
            <div class="qrm-diag-row">
                <span class="qrm-pill <?php echo $tables['codes'] ? 'qrm-pill-ok':'qrm-pill-err'; ?>"><?php echo $tables['codes']?'OK':'MISSING'; ?></span>
                <span><code><?php echo $wpdb->prefix; ?>qrm_codes</code> — stores QR code → destination mappings</span>
            </div>
            <div class="qrm-diag-row">
                <span class="qrm-pill <?php echo $tables['logs'] ? 'qrm-pill-ok':'qrm-pill-err'; ?>"><?php echo $tables['logs']?'OK':'MISSING'; ?></span>
                <span><code><?php echo $wpdb->prefix; ?>qrm_scan_logs</code> — stores scan history</span>
            </div>

            <?php if ( ! $tables['codes'] || ! $tables['logs'] ) : ?>
            <div style="margin-top:16px;">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('qrm_install'); ?>
                    <input type="hidden" name="action" value="qrm_install_tables">
                    <button type="submit" class="qrm-btn qrm-btn-primary">Create Missing Tables</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <div class="qrm-card">
            <h2>Write / Read Test</h2>
            <?php if ( ! $tables['codes'] ) : ?>
                <p style="color:var(--qrm-muted)">Cannot test — table is missing.</p>
            <?php elseif ( $test_result === 'pass' ) : ?>
                <div class="qrm-diag-row">
                    <span class="qrm-pill qrm-pill-ok">PASS</span>
                    <span>Successfully inserted and read back a test row. Database writes are working.</span>
                </div>
            <?php else : ?>
                <div class="qrm-diag-row">
                    <span class="qrm-pill qrm-pill-err">FAIL</span>
                    <span><?php echo esc_html($test_result); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="qrm-card">
            <h2>Database Connection</h2>
            <div class="qrm-diag-row"><strong style="width:120px">Host</strong> <code><?php echo esc_html($db_host); ?></code></div>
            <div class="qrm-diag-row"><strong style="width:120px">Database</strong> <code><?php echo esc_html($db_name); ?></code></div>
            <div class="qrm-diag-row"><strong style="width:120px">User</strong> <code><?php echo esc_html($db_user); ?></code></div>
            <div class="qrm-diag-row"><strong style="width:120px">Table prefix</strong> <code><?php echo esc_html($wpdb->prefix); ?></code></div>
            <div class="qrm-diag-row"><strong style="width:120px">Last error</strong> <code style="color:var(--qrm-red)"><?php echo $last_err ? esc_html($last_err) : '(none)'; ?></code></div>
        </div>

        <div class="qrm-card">
            <h2>Rewrite / Redirect URL</h2>
            <div class="qrm-diag-row">
                <strong style="width:120px">Test URL</strong>
                <code><?php echo esc_url($go_url); ?></code>
                <a href="<?php echo esc_url($go_url); ?>" target="_blank" class="qrm-btn qrm-btn-outline qrm-btn-sm">Open</a>
            </div>
            <p class="qrm-hint" style="margin-top:10px;">If the URL above goes to your homepage instead of a 404, the rewrite rule is working. If it shows a WordPress 404, go to <a href="<?php echo admin_url('options-permalink.php'); ?>">Settings → Permalinks</a> and click Save Changes.</p>
        </div>
    </div>
    <?php
}

// ── Add / Edit form ───────────────────────────────────────────────

function qrm_render_form( $id = 0 ) {
    $record = null;
    if ( $id ) {
        $record = qrm_get_code_by_id( $id );
        if ( ! $record ) { echo '<div class="notice notice-error"><p>Redirect not found.</p></div>'; return; }
    }

    $label      = $record->label      ?? '';
    $code       = $record->code       ?? '';
    $dest_type  = $record->dest_type  ?? 'page';
    $page_id    = $record->page_id    ?? 0;
    $custom_url = $record->custom_url ?? '';
    $is_edit    = (bool) $id;

    $error = '';
    if ( isset($_GET['qrm_error']) ) {
        $map = [
            'code_required' => 'Code is required.',
            'duplicate'     => 'That code already exists.',
            'db'            => 'Database error: ' . urldecode( $_GET['dberr'] ?? 'unknown' ),
        ];
        $error = $map[ $_GET['qrm_error'] ] ?? 'An error occurred.';
    }

    $go_url = $code ? home_url('/go/?code='.$code) : '';
    $query = new WP_Query([
        'post_type'      => ['page', 'post'],
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);
    $pages = $query->posts;

    qrm_styles();
    ?>
    <div class="qrm-wrap">
        <div class="qrm-header">
            <h1><span class="dashicons dashicons-qrcode"></span> <?php echo $is_edit ? 'Edit QR Redirect' : 'New QR Redirect'; ?></h1>
            <a href="<?php echo admin_url('admin.php?page=qrm'); ?>" class="qrm-btn qrm-btn-outline">← Back</a>
        </div>

        <?php if ($error)              echo '<div class="qrm-notice qrm-notice-error">'  .esc_html($error).'</div>'; ?>
        <?php if (isset($_GET['saved'])) echo '<div class="qrm-notice qrm-notice-success">Saved successfully.</div>'; ?>

        <div class="qrm-grid">
            <div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('qrm_form'); ?>
                    <input type="hidden" name="action"  value="qrm_save">
                    <input type="hidden" name="qrm_id"  value="<?php echo intval($id); ?>">

                    <div class="qrm-card">
                        <h2>QR Code Identity</h2>
                        <div class="qrm-field">
                            <label>Label</label>
                            <input type="text" name="qrm_label" value="<?php echo esc_attr($label); ?>" placeholder="e.g. Summer Fundraiser Flyer">
                            <p class="qrm-hint">For your reference only.</p>
                        </div>
                        <div class="qrm-field">
                            <label>Code <span style="color:var(--qrm-red)">*</span></label>
                            <input type="text" name="qrm_code" value="<?php echo esc_attr($code); ?>"
                                   placeholder="e.g. summer2025"
                                   <?php echo $is_edit ? 'readonly style="background:#f4f6fb;color:var(--qrm-muted)"' : ''; ?>
                                   oninput="qrmUpdatePreview(this.value)">
                            <p class="qrm-hint"><?php echo $is_edit ? 'Cannot be changed after creation.' : 'Lowercase, numbers, hyphens only. Cannot be changed after saving.'; ?></p>
                        </div>
                    </div>

                    <div class="qrm-card">
                        <h2>Destination</h2>
                        <div class="qrm-field">
                            <div class="qrm-radio-row">
                                <label><input type="radio" name="qrm_dest_type" value="page" <?php checked($dest_type,'page'); ?> onchange="qrmToggleDest('page')"> WordPress Page / Post</label>
                                <label><input type="radio" name="qrm_dest_type" value="url"  <?php checked($dest_type,'url');  ?> onchange="qrmToggleDest('url')"> Custom URL</label>
                            </div>
                        </div>
                        <div id="qrm-dest-page" class="qrm-field" <?php echo $dest_type==='url'?'style="display:none"':''; ?>>
                            <label>Select Page or Post</label>
                            <select name="qrm_page_id">
                                <option value="">— Choose —</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?php echo $p->ID; ?>" <?php selected($page_id,$p->ID); ?>><?php echo esc_html($p->post_title); ?> (<?php echo $p->post_type; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="qrm-dest-url" class="qrm-field" <?php echo $dest_type!=='url'?'style="display:none"':''; ?>>
                            <label>Destination URL</label>
                            <input type="text" name="qrm_custom_url" value="<?php echo esc_attr($custom_url); ?>" placeholder="https://example.com/page">
                        </div>
                    </div>

                    <div class="qrm-form-actions">
                        <button type="submit" class="qrm-btn qrm-btn-primary">Save Redirect</button>
                        <a href="<?php echo admin_url('admin.php?page=qrm'); ?>" style="color:var(--qrm-muted);font-size:13px;">Cancel</a>
                    </div>
                </form>

                <?php if ($is_edit): ?>
                <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--qrm-border);">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('Delete this redirect and all its scan logs?')">
                        <?php wp_nonce_field('qrm_form'); ?>
                        <input type="hidden" name="action" value="qrm_delete">
                        <input type="hidden" name="qrm_id" value="<?php echo intval($id); ?>">
                        <button type="submit" class="qrm-btn qrm-btn-danger qrm-btn-sm">Delete this redirect</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <div class="qrm-qr-panel">
                <h2>QR Code</h2>
                <p>Scan to test the redirect.</p>
                <div id="qrm-qr-canvas"></div>
                <div class="qrm-qr-url" id="qrm-qr-url-display"><?php echo $go_url ? esc_html($go_url) : 'Enter a code to preview'; ?></div>
                <button type="button" class="qrm-btn qrm-btn-outline" style="width:100%;margin-bottom:8px;" onclick="qrmCopyUrl()">Copy URL</button>
                <button type="button" class="qrm-btn qrm-btn-gold"    style="width:100%;" onclick="qrmDownloadQR()">Download QR Image</button>
                <?php if ($is_edit): ?>
                <hr style="border:none;border-top:1px solid var(--qrm-border);margin:14px 0;">
                <a href="<?php echo admin_url('admin.php?page=qrm&qrm_action=logs&qrm_id='.$id); ?>" class="qrm-btn qrm-btn-outline" style="width:100%;">View Scan Logs</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    var qrmBaseUrl = '<?php echo esc_js(home_url("/go/?code=")); ?>';
    var qrmCurrentCode = '<?php echo esc_js($code); ?>';
    function qrmMakeQR(url) {
        var el = document.getElementById('qrm-qr-canvas');
        el.innerHTML = '';
        if (!url) { el.innerHTML = '<p style="color:#9ca3af;font-size:12px;padding:20px 0">Enter a code above</p>'; return; }
        new QRCode(el, { text:url, width:200, height:200, colorDark:'#1a2744', colorLight:'#ffffff', correctLevel:QRCode.CorrectLevel.M });
    }
    function qrmUpdatePreview(code) {
        qrmCurrentCode = code.trim();
        var url = qrmCurrentCode ? qrmBaseUrl + qrmCurrentCode : '';
        document.getElementById('qrm-qr-url-display').textContent = url || 'Enter a code to preview';
        qrmMakeQR(url);
    }
    function qrmCopyUrl() {
        var url = document.getElementById('qrm-qr-url-display').textContent;
        navigator.clipboard.writeText(url).then(function(){
            var b = event.target; b.textContent='✓ Copied!'; setTimeout(function(){b.textContent='Copy URL';},1500);
        });
    }
    function qrmDownloadQR() {
        var c = document.querySelector('#qrm-qr-canvas canvas');
        if (!c) { alert('Enter a code first.'); return; }
        var a = document.createElement('a'); a.download='qr-'+(qrmCurrentCode||'code')+'.png'; a.href=c.toDataURL('image/png'); a.click();
    }
    function qrmToggleDest(val) {
        document.getElementById('qrm-dest-page').style.display = val==='page' ? '' : 'none';
        document.getElementById('qrm-dest-url').style.display  = val==='url'  ? '' : 'none';
    }
    document.addEventListener('DOMContentLoaded', function(){ qrmUpdatePreview(qrmCurrentCode); });
    </script>
    <?php
}

// ── Scan logs ─────────────────────────────────────────────────────

function qrm_render_logs( $id ) {
    $record = qrm_get_code_by_id( $id );
    if (!$record) { echo '<div class="notice notice-error"><p>Redirect not found.</p></div>'; return; }
    $logs = qrm_get_scan_logs( $id, 200 );
    qrm_styles();
    ?>
    <div class="qrm-wrap">
        <div class="qrm-header">
            <h1><span class="dashicons dashicons-qrcode"></span> Scan Logs — <?php echo esc_html($record->label); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=qrm&qrm_action=edit&qrm_id='.$id); ?>" class="qrm-btn qrm-btn-outline">← Back</a>
        </div>
        <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
            <div style="background:#fff;border:1px solid var(--qrm-border);border-radius:6px;padding:16px 24px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:var(--qrm-navy)"><?php echo count($logs); ?></div>
                <div style="font-size:12px;color:var(--qrm-muted)">Total Scans (last 200)</div>
            </div>
            <div style="background:#fff;border:1px solid var(--qrm-border);border-radius:6px;padding:16px 24px;">
                <div style="font-size:12px;color:var(--qrm-muted);margin-bottom:4px">Short URL</div>
                <code><?php echo esc_url(home_url('/go/?code='.$record->code)); ?></code>
            </div>
        </div>
        <?php if (empty($logs)): ?>
            <div class="qrm-empty"><p>No scans recorded yet.</p></div>
        <?php else: ?>
        <div style="background:#fff;border:1px solid var(--qrm-border);border-radius:6px;overflow:hidden;">
            <table class="qrm-log-table">
                <thead><tr><th>#</th><th>Date &amp; Time</th><th>IP Address</th><th>User Agent</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $i => $log): ?>
                <tr>
                    <td style="color:var(--qrm-muted)"><?php echo $i+1; ?></td>
                    <td><?php echo esc_html(date_i18n('M j, Y g:i:s a', strtotime($log->scanned_at))); ?></td>
                    <td><code><?php echo esc_html($log->ip_address ?: '—'); ?></code></td>
                    <td style="font-size:11px;color:var(--qrm-muted);max-width:400px;word-break:break-word;"><?php echo esc_html($log->user_agent ?: '—'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
