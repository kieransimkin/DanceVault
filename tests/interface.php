<?php
declare(strict_types=1);
// Translation/output and record-name cache contracts; real hosting is a separate gate.
if (($argv[1] ?? '') === '--case') {
    define('ABSPATH', __DIR__);
    $case = $argv[2];
    $queries = 0;
    $cache = [];
    $id = str_repeat('a', 32);
    $row = ['label' => '<img src=x onerror=alert(1)>', 'filename' => '<script>file</script>.txt',
        'size' => 10, 'sha256' => str_repeat('b', 64), 'expires' => time() + 3600, 'revoked' => false];
    function add_action(...$args) {}
    function add_filter(...$args) {}
    function current_user_can($capability) { return $GLOBALS['case'] !== 'denied-admin'; }
    function is_ssl() { return true; }
    function __($text, $domain) {
        if ($domain !== 'cipherparcel') throw new RuntimeException('Wrong translation domain');
        return ['Label' => '<script>label</script>', 'Password' => 'Cyfrinair',
            'Download file' => 'Lawrlwytho <strong>ffeil</strong>'][$text] ?? $text;
    }
    function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($text) { return esc_html($text); }
    function esc_url($text) { return esc_attr($text); }
    function esc_html__($text, $domain) { return esc_html(__($text, $domain)); }
    function admin_url($path) { return 'https://fixture.invalid/wp-admin/' . $path; }
    function wp_nonce_field($action) { echo '<input name="_wpnonce" value="synthetic-nonce">'; }
    function submit_button($text, $type = 'primary') { echo '<button>' . esc_html($text) . '</button>'; }
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
    function get_option($name, $default = false) { return str_starts_with($name, 'dancevault_record_') ? $GLOBALS['row'] : $default; }
    function wp_cache_get($name, $group, $force = false, &$found = null) {
        $found = array_key_exists($group . $name, $GLOBALS['cache']);
        return $GLOBALS['cache'][$group . $name] ?? false;
    }
    function wp_cache_set($name, $value, $group, $expiry) { $GLOBALS['cache'][$group . $name] = $value; }
    function nocache_headers() {}
    function wp_salt($scheme) { return 'synthetic-salt'; }
    function wp_unslash($text) { return $text; }
    function sanitize_text_field($text) { return strip_tags($text); }
    function get_transient($name) { return false; }
    function get_bloginfo($name) { return 'cy-GB'; }
    $wpdb = new class {
        public $options = 'wp_options';
        public function prepare($query, $value) { return $query; }
        public function esc_like($value) { return $value; }
        public function get_col($query) { $GLOBALS['queries']++; return ['dancevault_record_' . $GLOBALS['id']]; }
    };
    require dirname(__DIR__) . '/dancevault.php';
    if ($case === 'recipient') {
        $_GET = ['id' => $id];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        DanceVault_Plugin::download();
    }
    DanceVault_Plugin::admin();
    if ($case === 'admin') {
        DanceVault_Plugin::admin();
        echo 'CACHE_QUERIES:' . $queries;
    }
    exit;
}
$count = 0;
foreach (['admin', 'recipient', 'denied-admin'] as $case) {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $actual = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($exit !== 0 || $stderr !== '') throw new RuntimeException($case . ': ' . $stderr);
    if ($case === 'admin' && (!str_contains($actual, '&lt;script&gt;label&lt;/script&gt;') || !str_contains($actual, '&lt;img src=x') || !str_contains($actual, 'CACHE_QUERIES:1') || str_contains($actual, '<script>'))) throw new RuntimeException('Administrator translation/escaping/cache regression');
    if ($case === 'recipient' && (!str_contains($actual, 'lang="cy-GB"') || !str_contains($actual, 'Cyfrinair') || !str_contains($actual, 'Lawrlwytho &lt;strong&gt;ffeil&lt;/strong&gt;') || str_contains($actual, '<strong>'))) throw new RuntimeException('Recipient translation/language/escaping regression');
    if ($case === 'denied-admin' && $actual !== '') throw new RuntimeException('Private administrator interface exposed');
    $count++;
}
echo 'PASS ' . $count . ' translated-interface and cache cases' . PHP_EOL;
