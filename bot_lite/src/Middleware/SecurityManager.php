<?php
namespace App\Middleware;

class SecurityManager {
    public static function decryptToken(string $encryptedToken, string $masterKey): string {
        $data = base64_decode($encryptedToken);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $masterKey, 0, $iv);
    }

    public static function encryptToken(string $token, string $masterKey): string {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($token, 'aes-256-cbc', $masterKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
}
