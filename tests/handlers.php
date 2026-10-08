<?php
declare(strict_types=1);
// WordPress API contract harness, not a substitute for real server integration.
if (($argv[1] ?? '') === '--case') {
    define('ABSPATH', __DIR__);
    define('MINUTE_IN_SECONDS', 60);
    define('DAY_IN_SECONDS', 86400);
    $case = $argv[2];
    $id = str_repeat('a', 32);
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    $fixture = sys_get_temp_dir() . '/dancevault-test-' . bin2hex(random_bytes(8));
    mkdir($fixture);
    mkdir($fixture . '/dancevault-encrypted');
    register_shutdown_function(static function () use ($fixture): void {
        foreach (glob($fixture . '/dancevault-encrypted/*') as $path) unlink($path);
        if (is_file($fixture . '/dancevault-encrypted/.htaccess')) unlink($fixture . '/dancevault-encrypted/.htaccess');
        rmdir($fixture . '/dancevault-encrypted'); rmdir($fixture);
    });
    function add_action(...$args) {}
    function nocache_headers() {}
    function is_ssl() { return $GLOBALS['case'] !== 'http'; }
    function wp_die($message, $title = '', $args = []) { echo 'DENY:' . $args['response']; exit; }
    function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
    function get_transient($name) { return $GLOBALS['case'] === 'limited' ? 10 : 0; }
    function set_transient(...$args) { return true; }
    function wp_salt($name) { return 'synthetic-test-salt'; }
    function wp_unslash($text) { return $text; }
    function wp_check_password($plain, $hash) { return password_verify($plain, $hash); }
    function wp_upload_dir() { return ['basedir' => $GLOBALS['fixture']]; }
    function wp_mkdir_p($dir) { return is_dir($dir); }
    require dirname(__DIR__) . '/dancevault.php';
    $source = fopen('php://temp', 'w+b'); fwrite($source, 'synthetic private fixture'); rewind($source);
    $output = fopen($fixture . '/dancevault-encrypted/' . $id . '.dvlt', 'wb');
    $metadata = DanceVault\Crypto::encrypt($source, $output, $key); fclose($source); fclose($output);
    $options = ['dancevault_key' => base64_encode($key), 'dancevault_record_' . $id => $metadata + ['filename' => 'fixture.txt', 'password_hash' => password_hash('test-password-123456', PASSWORD_DEFAULT), 'revoked' => $case === 'revoked', 'expires' => $case === 'expired' ? time() - 1 : time() + 3600]];
    if ($case === 'missing') unset($options['dancevault_record_' . $id]);
    if ($case === 'corrupt') file_put_contents($fixture . '/dancevault-encrypted/' . $id . '.dvlt', 'bad');
    if ($case === 'mismatch') $options['dancevault_record_' . $id]['sha256'] = str_repeat('0', 64);
    $_GET = ['id' => $id]; $_POST = ['password' => $case === 'wrong' ? 'wrong' : 'test-password-123456'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    DanceVault_Plugin::download();
    exit;
}
$cases = ['http' => 'DENY:400', 'missing' => 'DENY:404', 'expired' => 'DENY:404', 'revoked' => 'DENY:404', 'limited' => 'DENY:429', 'wrong' => 'DENY:403', 'corrupt' => 'DENY:503', 'mismatch' => 'DENY:503', 'valid' => 'synthetic private fixture'];
foreach ($cases as $case => $expected) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $actual = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($actual !== $expected || $exit !== 0 || $stderr !== '') throw new RuntimeException($case . ': ' . $actual . ' ' . $stderr);
}
echo 'PASS 9 download-handler contract cases' . PHP_EOL;
