<?php
declare(strict_types=1);
require __DIR__ . '/../src/crypto.php';
use DanceVault\Crypto;
$count = 0;
function check(bool $condition, string $label): void {
    global $count;
    if (!$condition) throw new RuntimeException($label);
    $count++;
}
function encrypted(string $data, string $key): string {
    $in = fopen('php://temp', 'w+b'); $out = fopen('php://temp', 'w+b');
    fwrite($in, $data); rewind($in);
    $result = Crypto::encrypt($in, $out, $key);
    check($result['size'] === strlen($data), 'size');
    check($result['sha256'] === hash('sha256', $data), 'hash');
    rewind($out); $bytes = stream_get_contents($out);
    fclose($in); fclose($out); return $bytes;
}
function decoded(string $bytes, string $key): string {
    $in = fopen('php://temp', 'w+b'); $out = fopen('php://temp', 'w+b');
    try {
        fwrite($in, $bytes); rewind($in);
        Crypto::decrypt($in, $out, $key); rewind($out);
        return stream_get_contents($out);
    } finally { fclose($in); fclose($out); }
}
function rejects(string $bytes, string $key, string $label): void {
    try { decoded($bytes, $key); } catch (Throwable $e) { check(true, $label); return; }
    check(false, $label);
}
$key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
foreach (['', 'synthetic fixture', random_bytes(Crypto::CHUNK), random_bytes(Crypto::CHUNK + 19), random_bytes(Crypto::CHUNK * 3)] as $data) {
    $bytes = encrypted($data, $key);
    check(decoded($bytes, $key) === $data, 'roundtrip');
    check(encrypted($data, $key) !== $bytes, 'randomised encryption');
    rejects($bytes, sodium_crypto_secretstream_xchacha20poly1305_keygen(), 'wrong key');
    rejects(substr($bytes, 0, -1), $key, 'truncated');
    rejects($bytes . 'x', $key, 'trailing data');
    $bad = $bytes; $bad[40] = chr(ord($bad[40]) ^ 1);
    rejects($bad, $key, 'tampered');
    rejects('INVALID!' . substr($bytes, 8), $key, 'bad magic');
    rejects(substr($bytes, 0, 32) . pack('N', 0xffffffff) . substr($bytes, 36), $key, 'oversized record');
}
echo "PASS $count crypto assertions\n";
