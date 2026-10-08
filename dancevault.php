<?php
/**
 * Plugin Name: DanceVault
 * Description: Password-controlled encrypted file delivery for the DanceFlow ecosystem.
 * Version: 0.1.1
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * Author: Kieran Simkin
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/src/crypto.php';

final class DanceVault_Plugin {
    private const MAX_BYTES = 104857600;

    public static function boot(): void {
        add_action('admin_menu', static function (): void {
            add_management_page('DanceVault', 'DanceVault', 'manage_options', 'dancevault', [self::class, 'admin']);
        });
        add_action('admin_post_dancevault_create', [self::class, 'create']);
        add_action('admin_post_dancevault_revoke', [self::class, 'revoke']);
        add_action('admin_post_dancevault_download', [self::class, 'download']);
        add_action('admin_post_nopriv_dancevault_download', [self::class, 'download']);
    }

    private static function key(bool $create = false): string {
        if (!extension_loaded('sodium')) throw new RuntimeException('PHP sodium is required.');
        $stored = get_option('dancevault_key', '');
        if ($stored === '' && $create) {
            add_option('dancevault_key', base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen()), '', false);
            $stored = get_option('dancevault_key', '');
        }
        $key = base64_decode((string) $stored, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) throw new RuntimeException('Vault key unavailable.');
        return $key;
    }

    private static function directory(): string {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) throw new RuntimeException('Upload storage unavailable.');
        $directory = $uploads['basedir'] . '/dancevault-encrypted';
        if (!wp_mkdir_p($directory)) throw new RuntimeException('Vault storage unavailable.');
        // Defence in depth. Ciphertext remains protected if nginx ignores these files.
        if (!is_file($directory . '/.htaccess')) file_put_contents($directory . '/.htaccess', "Require all denied\n");
        if (!is_file($directory . '/index.php')) file_put_contents($directory . '/index.php', "<?php http_response_code(404); exit;\n");
        return $directory;
    }

    private static function records(): array {
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC", $wpdb->esc_like('dancevault_record_') . '%'));
        $records = [];
        foreach ($names as $name) {
            $id = substr($name, strlen('dancevault_record_'));
            if (preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
                $row = get_option($name, null);
                if (is_array($row)) $records[$id] = $row;
            }
        }
        return $records;
    }

    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer($nonce);
        if (!is_ssl()) wp_die('HTTPS is required.', '', ['response' => 400]);
    }

    public static function admin(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>DanceVault</h1><p>Encrypted file delivery. Keep passwords in private correspondence, not page content. No public Media Library attachment is created.</p>';
        if (!extension_loaded('sodium') || !is_ssl()) {
            echo '<p>Uploads disabled: HTTPS and PHP sodium are required.</p></div>';
            return;
        }
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dancevault_create');
        echo '<input type="hidden" name="action" value="dancevault_create"><p><label>Label <input name="label" required maxlength="120"></label></p><p><label>File (maximum 100 MiB) <input type="file" name="vault_file" required></label></p><p><label>Password (16–128 characters) <input type="password" name="vault_password" minlength="16" maxlength="128" autocomplete="new-password" required></label></p><p><label>Expires after days <input type="number" name="days" min="1" max="90" value="7" required></label></p>';
        submit_button('Create protected download');
        echo '</form><h2>Downloads</h2>';
        foreach (self::records() as $id => $row) {
            $url = add_query_arg(['action' => 'dancevault_download', 'id' => $id], admin_url('admin-post.php'));
            echo '<section><h3>' . esc_html($row['label']) . '</h3><p>' . esc_html($row['filename']) . ' — ' . esc_html((string) $row['size']) . ' bytes; expires ' . esc_html(gmdate('Y-m-d H:i:s', $row['expires'])) . ' UTC; ' . ($row['revoked'] ? 'revoked' : 'active') . '</p><p>SHA-256: <code>' . esc_html($row['sha256']) . '</code></p><p><a href="' . esc_url($url) . '">Password-controlled download</a></p>';
            if (!$row['revoked']) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('dancevault_revoke');
                echo '<input type="hidden" name="action" value="dancevault_revoke"><input type="hidden" name="id" value="' . esc_attr($id) . '">';
                submit_button('Revoke access', 'secondary');
                echo '</form>';
            }
            echo '</section>';
        }
        echo '</div>';
    }

    public static function create(): void {
        self::guard('dancevault_create');
        $output = null;
        $input = null;
        $path = '';
        $created = false;
        try {
            $password = (string) wp_unslash($_POST['vault_password'] ?? '');
            $days = filter_var($_POST['days'] ?? '', FILTER_VALIDATE_INT);
            $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
            if (strlen($password) < 16 || strlen($password) > 128 || $days === false || $days < 1 || $days > 90 || $label === '' || strlen($label) > 480) throw new RuntimeException('Invalid fields.');
            $file = $_FILES['vault_file'] ?? [];
            if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Upload failed.');
            $size = filesize($file['tmp_name']);
            if ($size === false || $size < 1 || $size > self::MAX_BYTES) throw new RuntimeException('Invalid file size.');
            $filename = sanitize_file_name(basename($file['name']));
            if ($filename === '') throw new RuntimeException('Invalid filename.');
            $key = self::key(true);
            $id = bin2hex(random_bytes(16));
            $path = self::directory() . '/' . $id . '.dvlt';
            $input = fopen($file['tmp_name'], 'rb');
            $output = fopen($path, 'xb');
            $created = is_resource($output);
            if (!$input || !$output) throw new RuntimeException('Storage unavailable.');
            chmod($path, 0600);
            $metadata = \DanceVault\Crypto::encrypt($input, $output, $key);
            fclose($input); $input = null;
            fclose($output); $output = null;
            sodium_memzero($key);
            $row = $metadata + ['label' => $label, 'filename' => $filename, 'password_hash' => wp_hash_password($password), 'expires' => time() + $days * DAY_IN_SECONDS, 'revoked' => false];
            if (!add_option('dancevault_record_' . $id, $row, '', false)) throw new RuntimeException('Could not persist vault record.');
            wp_safe_redirect(admin_url('tools.php?page=dancevault')); exit;
        } catch (Throwable $error) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            if ($created && $path !== '' && is_file($path)) unlink($path);
            wp_die('Protected upload failed. No download was created. Check PHP upload limits, sodium and storage.', '', ['response' => 400]);
        }
    }

    public static function revoke(): void {
        self::guard('dancevault_revoke');
        $id = (string) ($_POST['id'] ?? '');
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            $row = get_option('dancevault_record_' . $id, null);
            if (is_array($row)) {
                $row['revoked'] = true;
                if (!update_option('dancevault_record_' . $id, $row, false) && !get_option('dancevault_record_' . $id)['revoked']) wp_die('Revocation failed.', '', ['response' => 500]);
            }
        }
        wp_safe_redirect(admin_url('tools.php?page=dancevault')); exit;
    }

    public static function download(): void {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        if (!is_ssl()) wp_die('HTTPS required.', '', ['response' => 400]);
        $id = (string) ($_GET['id'] ?? '');
        $row = preg_match('/\A[a-f0-9]{32}\z/D', $id) ? get_option('dancevault_record_' . $id, null) : null;
        if (!$row || $row['revoked'] || time() >= $row['expires']) wp_die('Download unavailable.', '', ['response' => 404]);
        $rate = 'dv_' . hash_hmac('sha256', $id . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''), wp_salt('auth'));
        $failures = (int) get_transient($rate);
        if ($failures >= 10) wp_die('Try again later.', '', ['response' => 429]);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Protected download — DanceVault</title><body><main><h1>Protected download</h1><form method="post"><label>Password <input type="password" name="password" maxlength="128" required autocomplete="off"></label><button type="submit">Download file</button></form></main></body></html>';
            exit;
        }
        $password = (string) wp_unslash($_POST['password'] ?? '');
        if (strlen($password) > 128 || !wp_check_password($password, $row['password_hash'])) {
            set_transient($rate, $failures + 1, 15 * MINUTE_IN_SECONDS);
            wp_die('Incorrect password.', '', ['response' => 403]);
        }
        $input = null; $plain = null;
        try {
            $key = self::key();
            $input = fopen(self::directory() . '/' . $id . '.dvlt', 'rb');
            // tmpfile uses the system temporary directory, never the public uploads directory.
            $plain = tmpfile();
            if (!$input || !$plain) throw new RuntimeException('Storage unavailable.');
            $result = \DanceVault\Crypto::decrypt($input, $plain, $key);
            sodium_memzero($key);
            fclose($input); $input = null;
            if ($result['size'] !== $row['size'] || !hash_equals($row['sha256'], $result['sha256'])) throw new RuntimeException('Integrity mismatch.');
            rewind($plain);
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($row['filename']));
            header('Content-Length: ' . $row['size']);
            header('Accept-Ranges: none');
            fpassthru($plain);
            fclose($plain); exit;
        } catch (Throwable $error) {
            if (is_resource($input)) fclose($input);
            if (is_resource($plain)) fclose($plain);
            wp_die('Download unavailable.', '', ['response' => 503]);
        }
    }
}
DanceVault_Plugin::boot();
