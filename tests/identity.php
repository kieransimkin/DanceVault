<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/dancevault.php');
$readme = file_get_contents($root . '/readme.txt');

if (!str_contains($source, 'Plugin Name: CipherParcel')) throw new RuntimeException('Public plugin name mismatch');
if (!str_contains($source, 'Version: 1.0.2')) throw new RuntimeException('Plugin version mismatch');
if (!str_contains($source, 'Text Domain: cipherparcel')) throw new RuntimeException('Text domain mismatch');
if (!str_starts_with($readme, "=== CipherParcel ===\n")) throw new RuntimeException('Directory name mismatch');
if (!str_contains($readme, "Stable tag: 1.0.2\n")) throw new RuntimeException('Directory version mismatch');

foreach (['dancevault_key', 'dancevault_record_', 'dancevault_download', 'dancevault-encrypted'] as $compatibilityIdentifier) {
    if (!str_contains($source, $compatibilityIdentifier)) throw new RuntimeException("Missing compatibility identifier: $compatibilityIdentifier");
}

echo "CipherParcel identity and DanceVault compatibility contracts verified.\n";
