<?php
// WP-CLI eval-file wraps PHP source; strict_types cannot lead that eval wrapper.
// CLI-only fixture control, mounted outside the served root and excluded from ZIPs.
if (!defined('WP_CLI') || !WP_CLI) exit(1);
$operation = $args[0] ?? '';
$id = $args[1] ?? '';
$base = wp_upload_dir()['basedir'] . '/dancevault-encrypted';
if ($operation === 'setup') {
    $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Public EPK control', 'post_content' => 'DanceVault public-page control marker.']);
    update_option('dancevault_test_page_id', $page, false);
    echo json_encode(['ok' => true]);
} elseif ($operation === 'state') {
    global $wpdb;
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'dancevault_record_%'");
    $records = [];
    foreach ($names as $name) {
        $row = get_option($name);
        unset($row['password_hash']);
        $row['autoload'] = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $name));
        $records[substr($name, strlen('dancevault_record_'))] = $row;
    }
    $keyAutoload = $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name='dancevault_key'");
    echo json_encode(['records' => $records, 'public_page' => get_option('dancevault_test_page_id'), 'attachments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'"),
        'key_autoload' => $keyAutoload, 'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
        'sodium' => SODIUM_LIBRARY_VERSION, 'system_temp' => sys_get_temp_dir(), 'upload_temp' => ini_get('upload_tmp_dir'),
        'temporary_files' => count(glob(sys_get_temp_dir() . '/*')), 'upload_temporary_files' => count(glob(ini_get('upload_tmp_dir') . '/*'))]);
} elseif ($operation === 'http-auth') {
    $user = get_user_by('login', 'vault-admin');
    $expiry = time() + 600;
    $token = WP_Session_Tokens::get_instance($user->ID)->create($expiry);
    $logged = wp_generate_auth_cookie($user->ID, $expiry, 'logged_in', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = $logged;
    wp_set_current_user($user->ID);
    echo json_encode(['cookie' => AUTH_COOKIE . '=' . wp_generate_auth_cookie($user->ID, $expiry, 'auth', $token) . '; ' . LOGGED_IN_COOKIE . '=' . $logged,
        'nonce' => wp_create_nonce('dancevault_create')]);
} elseif ($operation === 'clear-rate') {
    global $wpdb;
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_dv_%'");
    foreach ($names as $name) delete_transient(substr($name, strlen('_transient_')));
    echo json_encode(['ok' => true]);
} elseif ($operation === 'expire-rate') {
    global $wpdb;
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dv_%'");
    foreach ($names as $name) update_option($name, time() - 1, false);
    echo json_encode(['expired' => count($names)]);
} elseif ($operation === 'end-admin-sessions') {
    WP_Session_Tokens::get_instance(get_user_by('login', 'vault-admin')->ID)->destroy_all();
    echo json_encode(['ok' => true]);
} else {
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) throw new RuntimeException('Invalid fixture ID');
    $path = $base . '/' . $id . '.dvlt';
    $row = get_option('dancevault_record_' . $id);
    if (!is_array($row)) throw new RuntimeException('Fixture record absent');
    if ($operation === 'expire') {
        $row['expires'] = time() - 1;
        update_option('dancevault_record_' . $id, $row, false);
    } elseif ($operation === 'mismatch') {
        $row['sha256'] = str_repeat('0', 64);
        update_option('dancevault_record_' . $id, $row, false);
    } elseif ($operation === 'corrupt') {
        $file = fopen($path, 'r+b');
        fseek($file, 1048700);
        $byte = fread($file, 1);
        fseek($file, -1, SEEK_CUR);
        fwrite($file, chr(ord($byte) ^ 1)); fclose($file);
    } elseif ($operation === 'truncate') {
        $file = fopen($path, 'r+b'); ftruncate($file, filesize($path) - 9); fclose($file);
    } elseif ($operation === 'readable') {
        chmod($path, 0644);
    } elseif ($operation === 'private') {
        chmod($path, 0600);
    } else throw new RuntimeException('Unknown fixture operation');
    echo json_encode(['ok' => true]);
}
