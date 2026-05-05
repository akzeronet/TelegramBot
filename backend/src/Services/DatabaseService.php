<?php
declare(strict_types=1);

namespace App\Services;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;

/**
 * DatabaseService — Capa de acceso a datos
 *
 * Abstrae todas las consultas SQL. Usa PDO con PostgreSQL.
 * Los bot_tokens se cifran/descifran transparentemente con AES-256 (defuse/php-encryption).
 */
class DatabaseService
{
    private PDO $pdo;
    private Key $encryptionKey;

    public function __construct()
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'] ?? '5432',
            $_ENV['DB_NAME'],
        );

        $this->pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // La MASTER_KEY es un hex de 32 bytes generado con openssl rand -hex 32
        // Key::loadFromAsciiSafeString espera el formato de defuse/php-encryption
        $this->encryptionKey = Key::loadFromAsciiSafeString($_ENV['MASTER_KEY']);
    }

    // -------------------------------------------------------------------------
    // Queries genéricas
    // -------------------------------------------------------------------------

    /** Ejecuta una query y retorna la primera fila o null. */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /** Ejecuta una query y retorna todas las filas. */
    public function queryAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Ejecuta una query y retorna el valor de la primera columna de la primera fila. */
    public function queryScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : null;
    }

    /** Ejecuta una query de escritura (INSERT, UPDATE, DELETE). */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // -------------------------------------------------------------------------
    // Gestión de usuarios
    // -------------------------------------------------------------------------

    /**
     * Crea un nuevo usuario a partir de un Telegram sender_id.
     * Registra la identidad en user_identities automáticamente.
     * Retorna el UID generado.
     */
    public function createUser(string $telegramId): string
    {
        $uid = Uuid::uuid4()->toString();

        $this->pdo->beginTransaction();

        try {
            $this->execute(
                "INSERT INTO users (uid) VALUES ($1)",
                [$uid],
            );

            $this->execute(
                "INSERT INTO user_identities (uid, platform, platform_id) VALUES ($1, 'telegram', $2)",
                [$uid, $telegramId],
            );

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $uid;
    }

    // -------------------------------------------------------------------------
    // Bot tokens cifrados (AES-256)
    // -------------------------------------------------------------------------

    /**
     * Guarda un bot_token cifrado en user_bots.
     * El token nunca se almacena en texto claro.
     */
    public function saveBotToken(string $uid, string $plainToken, ?string $username = null): int
    {
        $encrypted = Crypto::encrypt($plainToken, $this->encryptionKey);

        $stmt = $this->pdo->prepare(
            "INSERT INTO user_bots (uid, bot_token_encrypted, bot_username)
             VALUES ($1, $2, $3)
             RETURNING bot_id",
        );
        $stmt->execute([$uid, $encrypted, $username]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Recupera y descifra el bot_token de un bot personal.
     * Solo lo usa el sistema internamente para enviar respuestas.
     */
    public function getBotToken(int $botId): string
    {
        $encrypted = $this->queryScalar(
            "SELECT bot_token_encrypted FROM user_bots WHERE bot_id = $1 AND is_active = TRUE",
            [$botId],
        );

        if (!$encrypted) {
            throw new \RuntimeException("Bot token not found for bot_id={$botId}");
        }

        return Crypto::decrypt($encrypted, $this->encryptionKey);
    }

    // -------------------------------------------------------------------------
    // Feature usage (sub-límites de Tier 2 y Tier 3)
    // -------------------------------------------------------------------------

    /**
     * Incrementa el contador de uso de una feature para hoy.
     * Si no existe el registro, lo crea (UPSERT).
     */
    public function incrementFeatureUsage(string $uid, string $featureKey, string $tier): void
    {
        $this->execute(
            "INSERT INTO feature_usage (uid, feature_key, tier, used_count, period_start, last_used_at)
             VALUES ($1, $2, $3, 1, CURRENT_DATE, NOW())
             ON CONFLICT (uid, feature_key, period_start)
             DO UPDATE SET used_count   = feature_usage.used_count + 1,
                           last_used_at = NOW()",
            [$uid, $featureKey, $tier],
        );
    }
}
