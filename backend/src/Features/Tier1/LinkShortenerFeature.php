<?php
declare(strict_types=1);

namespace App\Features\Tier1;

use App\Features\FeatureInterface;
use App\Services\DatabaseService;
use App\Services\TelegramService;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * LinkShortenerFeature — Tier 1 (PHP Puro)
 *
 * Comando: /short <url>
 * Ejemplo: /short https://www.google.com/very/long/url
 *
 * Genera un código corto único, lo guarda en short_links,
 * y responde al usuario con la URL acortada.
 *
 * Ruta de redirección: GET /s/{code} → definida en index.php
 *
 * Límite: 50 acortamientos por día (controlado por FeatureDispatcher).
 * No consume tokens de IA. No toca n8n.
 */
class LinkShortenerFeature implements FeatureInterface
{
    private const CODE_LENGTH = 7;

    public function __construct(
        private readonly DatabaseService $db,
        private readonly TelegramService $telegram,
    ) {}

    public function handle(
        string $uid,
        string $chatId,
        string $botToken,
        string $text,
        string $language,
    ): ResponseInterface {

        // Extraer la URL del comando: "/short https://..."
        $parts = explode(' ', $text, 2);
        $url   = trim($parts[1] ?? '');

        // --- Validar que hay una URL ---
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->invalidUrlMessage($language),
            );
            return $this->ok();
        }

        // --- Verificar si ya existe una URL acortada para este usuario (deduplicación) ---
        $existing = $this->db->queryOne(
            'SELECT short_code FROM short_links WHERE uid = $1 AND original_url = $2',
            [$uid, $url],
        );

        if ($existing) {
            $shortUrl = $this->buildShortUrl($existing['short_code']);
            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->successMessage($language, $url, $shortUrl, reused: true),
            );
            $this->db->incrementFeatureUsage($uid, 'link_shortener', 'tier1_php');
            return $this->ok();
        }

        // --- Generar código único ---
        $code = $this->generateUniqueCode();

        // --- Guardar en DB ---
        $this->db->execute(
            'INSERT INTO short_links (uid, short_code, original_url) VALUES ($1, $2, $3)',
            [$uid, $code, $url],
        );

        // --- Registrar uso de la feature ---
        $this->db->incrementFeatureUsage($uid, 'link_shortener', 'tier1_php');

        // --- Responder al usuario ---
        $shortUrl = $this->buildShortUrl($code);
        $this->telegram->sendMessage(
            botToken: $botToken,
            chatId:   $chatId,
            text:     $this->successMessage($language, $url, $shortUrl),
        );

        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function generateUniqueCode(): string
    {
        do {
            // Genera un código alfanumérico de 7 caracteres (case-sensitive)
            $code = substr(
                str_replace(['+', '/', '='], ['a', 'b', 'c'], base64_encode(random_bytes(6))),
                0,
                self::CODE_LENGTH,
            );

            $exists = $this->db->queryScalar(
                'SELECT 1 FROM short_links WHERE short_code = $1',
                [$code],
            );
        } while ($exists);

        return $code;
    }

    private function buildShortUrl(string $code): string
    {
        $domain = rtrim($_ENV['APP_DOMAIN'] ?? 'https://tudominio.com', '/');
        return "{$domain}/s/{$code}";
    }

    private function successMessage(
        string $language,
        string $original,
        string $short,
        bool   $reused = false,
    ): string {
        $note = $reused ? ($language === 'en' ? ' _(already existed)_' : ' _(ya existía)_') : '';

        return match ($language) {
            'en' => "🔗 *Link shortened*{$note}\n\n`{$short}`\n\n_Original:_ {$original}",
            'pt' => "🔗 *Link encurtado*{$note}\n\n`{$short}`\n\n_Original:_ {$original}",
            default => "🔗 *Enlace acortado*{$note}\n\n`{$short}`\n\n_Original:_ {$original}",
        };
    }

    private function invalidUrlMessage(string $language): string
    {
        return match ($language) {
            'en' => "❌ Please send a valid URL.\n\nUsage: `/short https://example.com`",
            'pt' => "❌ Envie uma URL válida.\n\nUso: `/short https://example.com`",
            default => "❌ Envía una URL válida.\n\nUso: `/short https://ejemplo.com`",
        };
    }

    private function ok(): Response
    {
        $r = new Response(200);
        $r->getBody()->write('OK');
        return $r;
    }
}
