<?php
declare(strict_types=1);

namespace DanceVault;

/** Versioned authenticated streaming container. Never stores plaintext in uploads. */
final class Crypto {
    public const MAGIC = "DVLT0001";
    public const CHUNK = 1048576;

    private static function write($handle, string $bytes): void {
        while ($bytes !== '') {
            $n = fwrite($handle, $bytes);
            if ($n === false || $n === 0) throw new \RuntimeException('Storage write failed.');
            $bytes = substr($bytes, $n);
        }
    }

    private static function read($handle, int $length): string {
        $bytes = '';
        while (strlen($bytes) < $length && !feof($handle)) {
            $part = fread($handle, $length - strlen($bytes));
            if ($part === false) throw new \RuntimeException('Storage read failed.');
            $bytes .= $part;
        }
        if (strlen($bytes) !== $length) throw new \RuntimeException('Truncated container.');
        return $bytes;
    }

    public static function encrypt($input, $output, string $key): array {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        self::write($output, self::MAGIC . $header);
        $hash = hash_init('sha256');
        $size = 0;
        while (!feof($input)) {
            $plain = fread($input, self::CHUNK);
            if ($plain === false) throw new \RuntimeException('Input read failed.');
            if ($plain === '') break;
            $size += strlen($plain);
            hash_update($hash, $plain);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, self::MAGIC);
            self::write($output, pack('N', strlen($cipher)) . $cipher);
        }
        $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', self::MAGIC, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        self::write($output, pack('N', strlen($final)) . $final);
        sodium_memzero($state);
        return ['size' => $size, 'sha256' => hash_final($hash)];
    }

    /** Caller verifies the complete result before emitting any plaintext. */
    public static function decrypt($input, $output, string $key): array {
        if (self::read($input, 8) !== self::MAGIC) throw new \RuntimeException('Invalid container.');
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
        $hash = hash_init('sha256');
        $size = 0;
        for (;;) {
            $length = unpack('Nlength', self::read($input, 4))['length'];
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) throw new \RuntimeException('Invalid record size.');
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::read($input, $length), self::MAGIC);
            if ($result === false) throw new \RuntimeException('Authentication failed.');
            [$plain, $tag] = $result;
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if ($plain !== '' || fread($input, 1) !== '') throw new \RuntimeException('Invalid final record.');
                sodium_memzero($state);
                return ['size' => $size, 'sha256' => hash_final($hash)];
            }
            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) throw new \RuntimeException('Invalid record tag.');
            $size += strlen($plain);
            hash_update($hash, $plain);
            self::write($output, $plain);
        }
    }
}
