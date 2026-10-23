<?php
/**
 * Plugin Name: CipherParcel
 * Description: Encrypted, password-controlled file downloads with expiry and revocation. https://kieransimkin.co.uk/
 * Version: 1.0.2
 * Requires at least: 6.7
 * Requires PHP: 8.0
 * Plugin URI: https://github.com/kieransimkin/DanceVault
 * Author URI: https://kieransimkin.co.uk/
 * Author: Kieran Simkin
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: cipherparcel
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/src/crypto.php';

final class DanceVault_Plugin {
    private const MAX_BYTES = 104857600;

    public static function boot(): void {
        add_action('admin_menu', static function (): void {
            add_management_page(__('CipherParcel', 'cipherparcel'), __('CipherParcel', 'cipherparcel'), 'manage_options', 'dancevault', [self::class, 'admin']);
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
        $names = wp_cache_get('record_names', 'dancevault', false, $found);
        if (!$found) {
            // No Options API enumerates a prefix; prepared core table query, administrator view only.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cache the minimal option-name list below; get_option owns row caching.
            $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC", $wpdb->esc_like('dancevault_record_') . '%'));
            wp_cache_set('record_names', $names, 'dancevault', 60);
        }
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

    private static function guard(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Forbidden', 'cipherparcel'), '', ['response' => 403]);
        if (!is_ssl()) wp_die(esc_html__('HTTPS is required.', 'cipherparcel'), '', ['response' => 400]);
    }

    private static function request_string(array $fields, string $name): string {
        $value = $fields[$name] ?? '';
        // PHP form/query parsers can supply arrays; never cast them to strings.
        return is_string($value) ? wp_unslash($value) : '';
    }

    public static function admin(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>' . esc_html__('CipherParcel', 'cipherparcel') . '</h1><p>' . esc_html__('Encrypted file delivery. Keep passwords in private correspondence, not page content. No public Media Library attachment is created.', 'cipherparcel') . '</p>';
        if (!extension_loaded('sodium') || !is_ssl()) {
            echo '<p>' . esc_html__('Uploads disabled: HTTPS and PHP sodium are required.', 'cipherparcel') . '</p></div>';
            return;
        }
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dancevault_create');
        echo '<input type="hidden" name="action" value="dancevault_create"><p><label>' . esc_html__('Label', 'cipherparcel') . ' <input name="label" required maxlength="120"></label></p>';
        echo '<p><label>' . esc_html__('File (maximum 100 MiB)', 'cipherparcel') . ' <input type="file" name="vault_file" required></label></p>';
        echo '<p><label>' . esc_html__('Password (16–128 characters)', 'cipherparcel') . ' <input type="password" name="vault_password" minlength="16" maxlength="128" autocomplete="new-password" required></label></p>';
        echo '<p><label>' . esc_html__('Expires after days', 'cipherparcel') . ' <input type="number" name="days" min="1" max="90" value="7" required></label></p>';
        submit_button(__('Create protected download', 'cipherparcel'));
        echo '</form><h2>' . esc_html__('Downloads', 'cipherparcel') . '</h2>';
        foreach (self::records() as $id => $row) {
            $url = add_query_arg(['action' => 'dancevault_download', 'id' => $id], admin_url('admin-post.php'));
            echo '<section><h3>' . esc_html($row['label']) . '</h3><p>' . esc_html($row['filename']) . ' — ';
            /* translators: 1: file size in bytes, 2: UTC expiry date, 3: active or revoked status. */
            printf(esc_html__('%1$s bytes; expires %2$s UTC; %3$s', 'cipherparcel'), esc_html((string) $row['size']), esc_html(gmdate('Y-m-d H:i:s', $row['expires'])), $row['revoked'] ? esc_html__('revoked', 'cipherparcel') : esc_html__('active', 'cipherparcel'));
            echo '</p><p>' . esc_html__('SHA-256:', 'cipherparcel') . ' <code>' . esc_html($row['sha256']) . '</code></p><p><a href="' . esc_url($url) . '">' . esc_html__('Password-controlled download', 'cipherparcel') . '</a></p>';
            if (!$row['revoked']) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('dancevault_revoke');
                echo '<input type="hidden" name="action" value="dancevault_revoke"><input type="hidden" name="id" value="' . esc_attr($id) . '">';
                submit_button(__('Revoke access', 'cipherparcel'), 'secondary');
                echo '</form>';
            }
            echo '</section>';
        }
        echo '</div>';
    }

    public static function create(): void {
        self::guard();
        check_admin_referer('dancevault_create');
        $output = null;
        $input = null;
        $path = '';
        $created = false;
        try {
            $password = self::request_string($_POST, 'vault_password');
            $days = filter_var(self::request_string($_POST, 'days'), FILTER_VALIDATE_INT);
            $label = sanitize_text_field(self::request_string($_POST, 'label'));
            if (strlen($password) < 16 || strlen($password) > 128 || $days === false || $days < 1 || $days > 90 || $label === '' || strlen($label) > 480) throw new RuntimeException('Invalid fields.');
            // Validate upload provenance/error/size below; sanitizing a PHP temporary path would corrupt it.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The filename is separately sanitized; bytes never become executable public files.
            $file = $_FILES['vault_file'] ?? [];
            if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('Upload failed.');
            $size = filesize($file['tmp_name']);
            if ($size === false || $size < 1 || $size > self::MAX_BYTES) throw new RuntimeException('Invalid file size.');
            $filename = sanitize_file_name(basename($file['name']));
            if ($filename === '') throw new RuntimeException('Invalid filename.');
            $key = self::key(true);
            $id = bin2hex(random_bytes(16));
            $path = self::directory() . '/' . $id . '.dvlt';
            // WP_Filesystem has no stream-handle API; whole-file get_contents/put_contents would break bounded encryption.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read a proven PHP upload as a bounded binary stream.
            $input = fopen($file['tmp_name'], 'rb');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation prevents overwriting an existing encrypted container.
            $output = fopen($path, 'xb');
            $created = is_resource($output);
            if (!$input || !$output) throw new RuntimeException('Storage unavailable.');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict the newly created local ciphertext before streaming data into it.
            chmod($path, 0600);
            $metadata = \DanceVault\Crypto::encrypt($input, $output, $key);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the bounded binary stream acquired above.
            fclose($input); $input = null;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusive ciphertext stream before recording success.
            fclose($output); $output = null;
            sodium_memzero($key);
            $row = $metadata + ['label' => $label, 'filename' => $filename, 'password_hash' => wp_hash_password($password), 'expires' => time() + $days * DAY_IN_SECONDS, 'revoked' => false];
            if (!add_option('dancevault_record_' . $id, $row, '', false)) throw new RuntimeException('Could not persist vault record.');
            wp_cache_delete('record_names', 'dancevault');
            wp_safe_redirect(admin_url('tools.php?page=dancevault')); exit;
        } catch (Throwable $error) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release an acquired stream on failure.
            if (is_resource($input)) fclose($input);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release an acquired stream on failure.
            if (is_resource($output)) fclose($output);
            if ($created && $path !== '' && is_file($path)) wp_delete_file($path);
            wp_die(esc_html__('Protected upload failed. No download was created. Check PHP upload limits, sodium and storage.', 'cipherparcel'), '', ['response' => 400]);
        }
    }

    public static function revoke(): void {
        self::guard();
        check_admin_referer('dancevault_revoke');
        $id = self::request_string($_POST, 'id');
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            $row = get_option('dancevault_record_' . $id, null);
            if (is_array($row)) {
                $row['revoked'] = true;
                if (!update_option('dancevault_record_' . $id, $row, false) && !get_option('dancevault_record_' . $id)['revoked']) wp_die(esc_html__('Revocation failed.', 'cipherparcel'), '', ['response' => 500]);
            }
        }
        wp_safe_redirect(admin_url('tools.php?page=dancevault')); exit;
    }

    public static function download(): void {
        // Older WordPress wp_die() sends nocache_headers again for errors.
        // Scope this filter to this terminating download request, not public pages.
        add_filter('nocache_headers', static function (array $headers): array {
            $headers['Cache-Control'] = 'private, no-store, max-age=0';
            return $headers;
        }, PHP_INT_MAX);
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        if (!is_ssl()) wp_die(esc_html__('HTTPS required.', 'cipherparcel'), '', ['response' => 400]);
        // Public recipient route: the per-file password authenticates every download; it does not perform administrator mutations.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A public GET displays only the password form, never file contents or private metadata.
        $id = self::request_string($_GET, 'id');
        $row = preg_match('/\A[a-f0-9]{32}\z/D', $id) ? get_option('dancevault_record_' . $id, null) : null;
        if (!$row || $row['revoked'] || time() >= $row['expires']) wp_die(esc_html__('Download unavailable.', 'cipherparcel'), '', ['response' => 404]);
        $address = filter_var(self::request_string($_SERVER, 'REMOTE_ADDR'), FILTER_VALIDATE_IP) ?: 'unavailable';
        $rate = 'dv_' . hash_hmac('sha256', $id . '|' . $address, wp_salt('auth'));
        $failures = (int) get_transient($rate);
        if ($failures >= 10) wp_die(esc_html__('Try again later.', 'cipherparcel'), '', ['response' => 429]);
        if (sanitize_text_field(self::request_string($_SERVER, 'REQUEST_METHOD')) !== 'POST') {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__('Protected download — CipherParcel', 'cipherparcel') . '</title><body><main><h1>' . esc_html__('Protected download', 'cipherparcel') . '</h1><form method="post"><label>' . esc_html__('Password', 'cipherparcel') . ' <input type="password" name="password" maxlength="128" required autocomplete="off"></label><button type="submit">' . esc_html__('Download file', 'cipherparcel') . '</button></form></main></body></html>';
            exit;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public recipients need the file password, not a WordPress user session/nonce; requests reveal no plaintext without it.
        $password = self::request_string($_POST, 'password');
        if (strlen($password) > 128 || !wp_check_password($password, $row['password_hash'])) {
            set_transient($rate, $failures + 1, 15 * MINUTE_IN_SECONDS);
            wp_die(esc_html__('Incorrect password.', 'cipherparcel'), '', ['response' => 403]);
        }
        $input = null; $plain = null;
        try {
            $key = self::key();
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded local ciphertext stream; validated ID never contains a path.
            $input = fopen(self::directory() . '/' . $id . '.dvlt', 'rb');
            // tmpfile uses the system temporary directory, never the public uploads directory.
            $plain = tmpfile();
            if (!$input || !$plain) throw new RuntimeException('Storage unavailable.');
            $result = \DanceVault\Crypto::decrypt($input, $plain, $key);
            sodium_memzero($key);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the acquired ciphertext stream.
            fclose($input); $input = null;
            if ($result['size'] !== $row['size'] || !hash_equals($row['sha256'], $result['sha256'])) throw new RuntimeException('Integrity mismatch.');
            rewind($plain);
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($row['filename']));
            header('Content-Length: ' . $row['size']);
            header('Accept-Ranges: none');
            fpassthru($plain);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing tmpfile atomically removes the private plaintext temporary file.
            fclose($plain); exit;
        } catch (Throwable $error) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release an acquired stream on integrity failure.
            if (is_resource($input)) fclose($input);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Remove any private temporary plaintext before returning an error.
            if (is_resource($plain)) fclose($plain);
            wp_die(esc_html__('Download unavailable.', 'cipherparcel'), '', ['response' => 503]);
        }
    }
}
DanceVault_Plugin::boot();
