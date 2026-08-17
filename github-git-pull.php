<?php
/**
 * Plugin Name: GitHub Git Pull
 * Plugin URI:  https://github.com
 * Description: Pull from GitHub and browse branches/commits from the WordPress admin.
 * Version:     2.0.0
 * Author:      GitHub Git Pull
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GGP_VERSION',      '3.0.0' );
define( 'GGP_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'GGP_OPTIONS_KEY',  'ggp_settings' );
define( 'GGP_LOG_KEY',      'ggp_pull_log' );
define( 'GGP_DEFAULT_PATH', '/var/www/html' );

/* ---------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------- */
function ggp_get_options(): array {
    return (array) get_option( GGP_OPTIONS_KEY, [] );
}

function ggp_repo_path(): string {
    $opts = ggp_get_options();
    $p    = trim( $opts['repo_path'] ?? '' );
    return $p ?: GGP_DEFAULT_PATH;
}

function ggp_remote(): string {
    $opts = ggp_get_options();
    return trim( $opts['remote'] ?? 'origin' );
}

function ggp_ssh_key(): string {
    $opts = ggp_get_options();
    return trim( $opts['ssh_key_path'] ?? '' );
}

/**
 * Run a git command in the repo. Returns [output_string, return_code].
 */
function ggp_git( string $args, string $repo_path = '' ): array {
    if ( ! $repo_path ) {
        $repo_path = ggp_repo_path();
    }
    $ssh_key  = ggp_ssh_key();
    $path_env = 'PATH=/usr/local/bin:/usr/bin:/bin ';
    $ssh_env  = $ssh_key
        ? 'GIT_SSH_COMMAND=' . escapeshellarg( "ssh -i {$ssh_key} -o StrictHostKeyChecking=no" ) . ' '
        : '';
    $ep  = escapeshellarg( $repo_path );
    $cmd = "{$path_env}{$ssh_env}git -C {$ep} -c safe.directory={$ep} {$args} 2>&1";

    // A prior request that got killed mid-command (PHP timeout, worker
    // recycle, etc.) can leave this behind, which then blocks every
    // subsequent git command. Only clear it once it's clearly stale, so a
    // genuinely concurrent request isn't stepped on.
    $lock = $repo_path . '/.git/index.lock';
    if ( is_file( $lock ) && ( time() - filemtime( $lock ) ) > 30 ) {
        @unlink( $lock );
    }

    // Match the server's group-writable permission scheme (664/775) for any
    // files/dirs git creates, instead of letting the process umask (644/755)
    // silently strip group write access on every pull.
    $old_umask = umask( 0002 );
    $out = [];
    $code = 0;
    exec( $cmd, $out, $code );
    umask( $old_umask );

    return [ implode( "\n", $out ), $code ];
}

/* ---------------------------------------------------------------
 * Admin menu
 * ------------------------------------------------------------- */
add_action( 'admin_menu', function () {
    add_menu_page(
        'Git Manager', 'Git Manager', 'manage_options',
        'ggp-main', 'ggp_render_main_page', 'dashicons-update', 75
    );
    add_submenu_page( 'ggp-main', 'Git Manager', 'Pull & Status', 'manage_options', 'ggp-main',     'ggp_render_main_page' );
    add_submenu_page( 'ggp-main', 'Settings',    'Settings',      'manage_options', 'ggp-settings', 'ggp_render_settings_page' );
} );

/* ---------------------------------------------------------------
 * Styles
 * ------------------------------------------------------------- */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( strpos( $hook, 'ggp-' ) === false ) return;
    wp_enqueue_style( 'ggp-admin', GGP_PLUGIN_URL . 'assets/admin.css', [], GGP_VERSION );
} );

/* ---------------------------------------------------------------
 * AJAX — fetch branches & commits
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_ggp_get_repo_data', function () {
    check_ajax_referer( 'ggp_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $repo = ggp_repo_path();
    if ( ! is_dir( $repo . '/.git' ) ) {
        wp_send_json_error( 'No .git folder found at: ' . $repo );
    }

    [ $branch, ] = ggp_git( 'rev-parse --abbrev-ref HEAD' );
    $branch = trim( $branch );

    [ $current_hash, ] = ggp_git( 'rev-parse HEAD' );
    $current_hash = trim( $current_hash );

    [ $branches_raw, ] = ggp_git( 'branch' );
    $branches = array_values( array_filter( array_map( function( $b ) {
        return trim( ltrim( trim( $b ), '*' ) );
    }, explode( "\n", $branches_raw ) ) ) );

    // Fetch quietly — run separately so output can't corrupt other commands
    ggp_git( 'fetch --all --quiet --no-progress' );

    [ $rbranches_raw, ] = ggp_git( 'branch -r' );
    $remote_branches = array_values( array_filter( array_map( function( $b ) {
        $b = trim( $b );
        return ( strpos( $b, '->' ) !== false ) ? null : $b;
    }, explode( "\n", $rbranches_raw ) ) ) );

    // Use a separator that will never appear in a commit message
    $sep     = '<<GGP>>';
    $log_fmt = '--pretty=format:%H' . $sep . '%h' . $sep . '%an' . $sep . '%ar' . $sep . '%s';
    [ $log_raw, ] = ggp_git( 'log ' . escapeshellarg( $log_fmt ) . ' -50' );

    $commits = [];
    foreach ( explode( "\n", $log_raw ) as $line ) {
        $line = trim( $line );
        if ( $line === '' ) continue;
        $parts = explode( $sep, $line, 5 );
        if ( count( $parts ) < 5 ) continue;
        $commits[] = [
            'hash'    => trim( $parts[0] ),
            'short'   => trim( $parts[1] ),
            'author'  => trim( $parts[2] ),
            'date'    => trim( $parts[3] ),
            'message' => trim( $parts[4] ),
            'current' => ( trim( $parts[0] ) === $current_hash ),
        ];
    }

    wp_send_json_success( [
        'branch'          => $branch,
        'current_hash'    => $current_hash,
        'branches'        => $branches,
        'remote_branches' => $remote_branches,
        'commits'         => $commits,
    ] );
} );

/* ---------------------------------------------------------------
 * AJAX — checkout branch or commit
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_ggp_checkout', function () {
    check_ajax_referer( 'ggp_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $target = sanitize_text_field( $_POST['target'] ?? '' );
    if ( ! $target ) wp_send_json_error( 'No target specified.' );

    $repo = ggp_repo_path();
    if ( ! is_dir( $repo . '/.git' ) ) wp_send_json_error( 'No .git folder found.' );

    // This is a deploy target — local (server-side) modifications never win,
    // so always force-discard them in favor of the target branch/commit.
    // Untracked files can also block a checkout, so clean those first too.
    ggp_git( 'clean -fd' );
    [ $out, $code ] = ggp_git( 'checkout --force ' . escapeshellarg( $target ) );
    if ( $code !== 0 ) wp_send_json_error( $out );

    [ $new_branch, ] = ggp_git( 'rev-parse --abbrev-ref HEAD' );
    [ $new_hash, ]   = ggp_git( 'rev-parse --short HEAD' );

    wp_send_json_success( [
        'message' => "Checked out: {$target}",
        'branch'  => trim( $new_branch ),
        'hash'    => trim( $new_hash ),
        'output'  => $out,
    ] );
} );

/* ---------------------------------------------------------------
 * AJAX — pull
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_ggp_pull', function () {
    check_ajax_referer( 'ggp_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $result = ggp_do_pull();
    if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
    wp_send_json_success( [ 'message' => $result ] );
} );

/* ---------------------------------------------------------------
 * Main page
 * ------------------------------------------------------------- */
function ggp_render_main_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $repo     = ggp_repo_path();
    $has_repo = is_dir( $repo . '/.git' );
    $log      = get_option( GGP_LOG_KEY, [] );

    if ( isset( $_POST['ggp_clear_log'] ) && check_admin_referer( 'ggp_pull_action', 'ggp_pull_nonce' ) ) {
        update_option( GGP_LOG_KEY, [] );
        $log = [];
    }
    ?>
    <div class="wrap ggp-wrap">
        <h1><span class="dashicons dashicons-update"></span> Git Manager</h1>

        <div id="ggp-notices"></div>

        <!-- Action bar -->
        <div class="ggp-card ggp-action-bar">
            <div class="ggp-repo-info">
                <span class="ggp-info-item">
                    <span class="dashicons dashicons-portfolio"></span>
                    <code><?php echo esc_html( $repo ); ?></code>
                </span>
                <span class="ggp-info-item">
                    <span class="dashicons dashicons-randomize"></span>
                    <strong id="ggp-current-branch"><?php echo $has_repo ? '…' : '—'; ?></strong>
                </span>
                <span class="ggp-info-item">
                    <span class="dashicons dashicons-tag"></span>
                    <code id="ggp-current-hash"><?php echo $has_repo ? '…' : '—'; ?></code>
                </span>
            </div>
            <div class="ggp-action-buttons">
                <?php if ( ! $has_repo ) : ?>
                    <span class="ggp-warn">⚠ No .git repo found. <a href="<?php echo esc_url( admin_url( 'admin.php?page=ggp-settings' ) ); ?>">Check Settings →</a></span>
                <?php else : ?>
                    <button id="ggp-refresh-btn" class="button button-secondary">
                        <span class="dashicons dashicons-image-rotate"></span> Refresh
                    </button>
                    <button id="ggp-pull-btn" class="button button-primary ggp-pull-btn">
                        <span class="dashicons dashicons-update"></span> Pull Now
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( $has_repo ) : ?>

        <!-- Explorer -->
        <div class="ggp-card">
            <div class="ggp-explorer-header">
                <h2 style="margin:0"><span class="dashicons dashicons-randomize"></span> Branch &amp; Commit Explorer</h2>
                <div class="ggp-explorer-tabs">
                    <button class="ggp-tab active" data-tab="commits">Commits</button>
                    <button class="ggp-tab" data-tab="branches">Branches</button>
                </div>
            </div>
            <div id="ggp-tab-commits" class="ggp-tab-panel" style="margin-top:16px">
                <div id="ggp-commit-list"><p class="ggp-loading">⏳ Loading commits…</p></div>
            </div>
            <div id="ggp-tab-branches" class="ggp-tab-panel" style="display:none;margin-top:16px">
                <div id="ggp-branch-list"><p class="ggp-loading">⏳ Loading branches…</p></div>
            </div>
        </div>

        <!-- Pull log -->
        <div class="ggp-card" id="ggp-log-card" <?php echo empty( $log ) ? 'style="display:none"' : ''; ?>>
            <h2>Pull History <span class="ggp-badge" id="ggp-log-count"><?php echo count( $log ); ?></span></h2>
            <div id="ggp-log-table-wrap">
                <?php ggp_render_log_table( $log ); ?>
            </div>
            <form method="post" style="margin-top:8px;">
                <?php wp_nonce_field( 'ggp_pull_action', 'ggp_pull_nonce' ); ?>
                <button type="submit" name="ggp_clear_log" class="button button-secondary">Clear Log</button>
            </form>
        </div>

        <script>
        (function($){
            var nonce   = '<?php echo wp_create_nonce( 'ggp_ajax' ); ?>';
            var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

            function notice(msg, type) {
                var cls = (type === 'error') ? 'notice-error' : 'notice-success';
                $('#ggp-notices').html('<div class="notice ' + cls + ' is-dismissible"><p>' + msg + '</p></div>');
                $('html,body').animate({ scrollTop: 0 }, 200);
            }

            function setBusy(btn, busy) {
                $(btn).prop('disabled', busy).toggleClass('ggp-spinning', busy);
            }

            function esc(s) { return $('<div>').text(s).html(); }

            function updateHead(branch, hash) {
                $('#ggp-current-branch').text(branch || '—');
                $('#ggp-current-hash').text(hash ? hash.substring(0,8) : '—');
            }

            function renderCommits(commits) {
                if (!commits || !commits.length) {
                    $('#ggp-commit-list').html('<p>No commits found.</p>');
                    return;
                }
                var h = '<table class="widefat ggp-commit-table"><thead><tr>'
                    + '<th style="width:20px"></th><th>Hash</th><th>Message</th><th>Author</th><th>When</th><th style="width:90px"></th>'
                    + '</tr></thead><tbody>';
                commits.forEach(function(c) {
                    h += '<tr class="' + (c.current ? 'ggp-row-current' : '') + '">'
                        + '<td>' + (c.current ? '<span class="ggp-dot-current" title="HEAD">●</span>' : '<span class="ggp-dot">○</span>') + '</td>'
                        + '<td><code class="ggp-hash">' + esc(c.short) + '</code></td>'
                        + '<td class="ggp-msg">' + esc(c.message) + '</td>'
                        + '<td class="ggp-author">' + esc(c.author) + '</td>'
                        + '<td class="ggp-date">' + esc(c.date) + '</td>'
                        + '<td>' + (c.current
                            ? '<span class="ggp-status ggp-status-ok">HEAD</span>'
                            : '<button class="button button-small ggp-checkout-btn" data-target="' + esc(c.hash) + '">Checkout</button>')
                        + '</td></tr>';
                });
                h += '</tbody></table>';
                $('#ggp-commit-list').html(h);
            }

            function renderBranches(branches, remote, current) {
                var h = '<table class="widefat ggp-branch-table"><thead><tr>'
                    + '<th style="width:20px"></th><th>Name</th><th>Type</th><th style="width:90px"></th>'
                    + '</tr></thead><tbody>';
                branches.forEach(function(b) {
                    var cur = (b === current);
                    h += '<tr class="' + (cur ? 'ggp-row-current' : '') + '">'
                        + '<td>' + (cur ? '<span class="ggp-dot-current">●</span>' : '<span class="ggp-dot">○</span>') + '</td>'
                        + '<td><code>' + esc(b) + '</code></td>'
                        + '<td><span class="ggp-tag-local">local</span></td>'
                        + '<td>' + (cur
                            ? '<span class="ggp-status ggp-status-ok">Current</span>'
                            : '<button class="button button-small ggp-checkout-btn" data-target="' + esc(b) + '">Switch</button>')
                        + '</td></tr>';
                });
                remote.forEach(function(b) {
                    var local = b.replace(/^[^\/]+\//, '');
                    h += '<tr>'
                        + '<td><span class="ggp-dot">○</span></td>'
                        + '<td><code>' + esc(b) + '</code></td>'
                        + '<td><span class="ggp-tag-remote">remote</span></td>'
                        + '<td><button class="button button-small ggp-checkout-btn" data-target="' + esc(local) + '">Checkout</button></td>'
                        + '</tr>';
                });
                h += '</tbody></table>';
                $('#ggp-branch-list').html(h);
            }

            function loadData(callback) {
                $.post(ajaxUrl, { action: 'ggp_get_repo_data', nonce: nonce }, function(res) {
                    if (!res.success) { notice(res.data, 'error'); return; }
                    var d = res.data;
                    updateHead(d.branch, d.current_hash);
                    renderCommits(d.commits);
                    renderBranches(d.branches, d.remote_branches, d.branch);
                    if (callback) callback(d);
                });
            }

            // Tabs
            $('.ggp-tab').on('click', function() {
                $('.ggp-tab').removeClass('active');
                $(this).addClass('active');
                $('.ggp-tab-panel').hide();
                $('#ggp-tab-' + $(this).data('tab')).show();
            });

            // Pull
            $('#ggp-pull-btn').on('click', function() {
                var $b = $(this);
                setBusy($b, true);
                $.post(ajaxUrl, { action: 'ggp_pull', nonce: nonce }, function(res) {
                    setBusy($b, false);
                    if (!res.success) {
                        notice('<strong>Pull failed:</strong><br><pre style="white-space:pre-wrap">' + esc(res.data) + '</pre>', 'error');
                    } else {
                        notice('<strong>Pull successful!</strong> ' + res.data.message);
                        setTimeout(function(){ location.reload(); }, 1200);
                    }
                }).fail(function(){ setBusy($b, false); notice('Request failed.', 'error'); });
            });

            // Refresh
            $('#ggp-refresh-btn').on('click', function() {
                var $b = $(this);
                setBusy($b, true);
                $('#ggp-commit-list, #ggp-branch-list').html('<p class="ggp-loading">⏳ Loading…</p>');
                loadData(function(){ setBusy($b, false); });
            });

            // Checkout
            $(document).on('click', '.ggp-checkout-btn', function() {
                var $b     = $(this);
                var target = $b.data('target');
                if (!confirm('Checkout "' + target + '"?\n\nLocal uncommitted changes may be overwritten.')) return;
                setBusy($b, true);
                $.post(ajaxUrl, { action: 'ggp_checkout', nonce: nonce, target: target }, function(res) {
                    setBusy($b, false);
                    if (!res.success) {
                        notice('<strong>Checkout failed:</strong><br><pre style="white-space:pre-wrap">' + esc(res.data) + '</pre>', 'error');
                    } else {
                        notice('✓ Switched to <strong>' + esc(target) + '</strong> — now on <strong>' + esc(res.data.branch) + '</strong> @ <code>' + esc(res.data.hash) + '</code>');
                        loadData();
                    }
                }).fail(function(){ setBusy($b, false); notice('Request failed.', 'error'); });
            });

            // Boot
            loadData();

        })(jQuery);
        </script>

        <?php endif; ?>
    </div>
    <?php
}

/* ---------------------------------------------------------------
 * Log table renderer
 * ------------------------------------------------------------- */
function ggp_render_log_table( array $log ): void {
    if ( empty( $log ) ) return;
    echo '<table class="widefat striped ggp-log-table">';
    echo '<thead><tr><th>Date / Time</th><th>Status</th><th>Output</th></tr></thead><tbody>';
    foreach ( array_reverse( $log ) as $entry ) {
        $status = $entry['success']
            ? '<span class="ggp-status ggp-status-ok">✓ Success</span>'
            : '<span class="ggp-status ggp-status-err">✗ Error</span>';
        echo '<tr>';
        echo '<td class="ggp-td-date">' . esc_html( $entry['date'] ) . '</td>';
        echo '<td>' . $status . '</td>';
        echo '<td><pre class="ggp-log-output">' . esc_html( $entry['output'] ) . '</pre></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/* ---------------------------------------------------------------
 * Settings page
 * ------------------------------------------------------------- */
function ggp_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $opts  = ggp_get_options();
    $saved = false;

    if ( isset( $_POST['ggp_save_settings'] ) && check_admin_referer( 'ggp_settings_action', 'ggp_settings_nonce' ) ) {
        $opts['repo_path']    = sanitize_text_field( $_POST['repo_path'] ?? '' );
        $opts['remote']       = sanitize_text_field( $_POST['remote'] ?? 'origin' );
        $opts['ssh_key_path'] = sanitize_text_field( $_POST['ssh_key_path'] ?? '' );
        update_option( GGP_OPTIONS_KEY, $opts );
        $saved = true;
    }
    ?>
    <div class="wrap ggp-wrap">
        <h1><span class="dashicons dashicons-admin-settings"></span> Git Manager — Settings</h1>
        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>
        <div class="ggp-card">
            <form method="post">
                <?php wp_nonce_field( 'ggp_settings_action', 'ggp_settings_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="repo_path">Repository Path</label></th>
                        <td>
                            <input type="text" id="repo_path" name="repo_path"
                                   value="<?php echo esc_attr( $opts['repo_path'] ?? GGP_DEFAULT_PATH ); ?>"
                                   class="regular-text" placeholder="<?php echo GGP_DEFAULT_PATH; ?>">
                            <p class="description">Defaults to <code><?php echo GGP_DEFAULT_PATH; ?></code> if left blank.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="remote">Remote</label></th>
                        <td>
                            <input type="text" id="remote" name="remote"
                                   value="<?php echo esc_attr( $opts['remote'] ?? 'origin' ); ?>"
                                   class="regular-text" placeholder="origin">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ssh_key_path">SSH Key Path</label></th>
                        <td>
                            <input type="text" id="ssh_key_path" name="ssh_key_path"
                                   value="<?php echo esc_attr( $opts['ssh_key_path'] ?? '' ); ?>"
                                   class="regular-text" placeholder="/opt/bitnami/.ssh/id_ed25519">
                            <p class="description">Optional. Leave blank for HTTPS repos.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="ggp_save_settings" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------
 * Core pull logic
 * ------------------------------------------------------------- */
function ggp_do_pull(): WP_Error|string {
    $repo   = ggp_repo_path();
    $remote = ggp_remote();

    if ( ! is_dir( $repo ) )         return new WP_Error( 'ggp_bad_path', "Directory does not exist: {$repo}" );
    if ( ! is_dir( $repo . '/.git' ) ) return new WP_Error( 'ggp_not_git', "No .git folder in: {$repo}" );

    // Pull the current branch
    [ $branch, ] = ggp_git( 'rev-parse --abbrev-ref HEAD' );
    $branch = trim( $branch );

    if ( ! $branch || $branch === 'HEAD' ) {
        return new WP_Error( 'ggp_detached', 'Cannot pull in detached HEAD state — switch to a branch first.' );
    }

    // This is a deploy target — local (server-side) modifications never win.
    // A merge-style `git pull` aborts if local changes would be overwritten,
    // so fetch first and force the working tree to match the remote branch
    // exactly instead.
    [ $fetch_out, $fetch_code ] = ggp_git( 'fetch ' . escapeshellarg( $remote ) . ' ' . escapeshellarg( $branch ) );
    if ( $fetch_code !== 0 ) {
        $log   = get_option( GGP_LOG_KEY, [] );
        $log[] = [ 'date' => current_time( 'Y-m-d H:i:s' ), 'success' => false, 'output' => $fetch_out ];
        if ( count( $log ) > 20 ) $log = array_slice( $log, -20 );
        update_option( GGP_LOG_KEY, $log );
        return new WP_Error( 'ggp_fetch_failed', "Fetch exit {$fetch_code}:\n{$fetch_out}" );
    }

    // Untracked files can also block a hard reset, so clean those first too.
    ggp_git( 'clean -fd' );
    [ $output, $code ] = ggp_git( 'reset --hard ' . escapeshellarg( "{$remote}/{$branch}" ) );

    $success = ( $code === 0 );
    $log     = get_option( GGP_LOG_KEY, [] );
    $log[]   = [ 'date' => current_time( 'Y-m-d H:i:s' ), 'success' => $success, 'output' => $output ];
    if ( count( $log ) > 20 ) $log = array_slice( $log, -20 );
    update_option( GGP_LOG_KEY, $log );

    if ( ! $success ) return new WP_Error( 'ggp_pull_failed', "Exit {$code}:\n{$output}" );

    return "{$branch}: {$output}";
}
