<?php
namespace App\Services;

use PDO;

class DatabaseService {
    private PDO $db;

    public function __construct() {
        $dbPath = __DIR__ . '/../../database/bot.db';
        $this->db = new PDO("sqlite:" . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Activar modo WAL para máximo rendimiento
        $this->db->exec("PRAGMA journal_mode=WAL;");
        $this->initSchema();
    }

    private function initSchema(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                uid TEXT PRIMARY KEY,
                platform_id TEXT UNIQUE,
                subscription TEXT DEFAULT 'free',
                status TEXT DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS user_bots (
                bot_id TEXT PRIMARY KEY,
                uid TEXT,
                token TEXT,
                is_active INTEGER DEFAULT 1,
                FOREIGN KEY(uid) REFERENCES users(uid)
            );
        ");
    }

    public function getOrCreateUser(string $platformId): array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE platform_id = ?");
        $stmt->execute([$platformId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $uid = bin2hex(random_bytes(16));
            $stmt = $this->db->prepare("INSERT INTO users (uid, platform_id) VALUES (?, ?)");
            $stmt->execute([$uid, $platformId]);
            return ['uid' => $uid, 'subscription' => 'free', 'status' => 'active'];
        }

        return $user;
    }

    public function getBotToken(string $botId): ?string {
        $stmt = $this->db->prepare("SELECT token FROM user_bots WHERE bot_id = ? AND is_active = 1");
        $stmt->execute([$botId]);
        return $stmt->fetchColumn() ?: null;
    }
}
