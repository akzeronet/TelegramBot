<?php
declare(strict_types=1);

namespace App\Features\Tier1;

use App\Features\FeatureInterface;
use App\Services\DatabaseService;
use App\Services\TelegramService;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * QrGeneratorFeature — Tier 1 (PHP Puro)
 *
 * Comando: /qr <texto o URL>
 * Ejemplo: /qr https://google.com
 *          /qr Mi WiFi: Nombre | Contraseña: abc123
 *
 * Genera un QR code como imagen PNG y lo envía directamente al usuario.
 * El archivo es temporal — se genera en memoria y se envía, sin persistir en disco.
 *
 * Librería: endroid/qr-code
 * Límite: 50 QRs por día (controlado por FeatureDispatcher).
 * No consume tokens de IA. No toca n8n.
 */
class QrGeneratorFeature implements FeatureInterface
{
    private const MAX_CONTENT_LENGTH = 500; // Límite de caracteres para el QR
    private const QR_SIZE_PX         = 400; // Tamaño de la imagen generada

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

        // Extraer el contenido del comando: "/qr <contenido>"
        $parts   = explode(' ', $text, 2);
        $content = trim($parts[1] ?? '');

        // --- Validar que hay contenido ---
        if ($content === '') {
            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->noContentMessage($language),
            );
            return $this->ok();
        }

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->tooLongMessage($language, self::MAX_CONTENT_LENGTH),
            );
            return $this->ok();
        }

        // --- Generar el QR code ---
        $tmpFile = $this->generateQrPng($content);

        // --- Registrar uso de la feature ---
        $this->db->incrementFeatureUsage($uid, 'qr_generator', 'tier1_php');

        // --- Enviar imagen al usuario ---
        $caption = $this->captionMessage($language, $content);
        $this->telegram->sendDocument(
            botToken: $botToken,
            chatId:   $chatId,
            filePath: $tmpFile,
            caption:  $caption,
        );

        // Limpiar archivo temporal
        @unlink($tmpFile);

        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function generateQrPng(string $content): string
    {
        $qrCode = new QrCode(
            data:                $content,
            encoding:            new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size:                self::QR_SIZE_PX,
            margin:              20,
            foregroundColor:     new Color(30, 30, 40),    // Casi negro — profesional
            backgroundColor:     new Color(255, 255, 255), // Blanco
        );

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        // Guardar en archivo temporal
        $tmpFile = sys_get_temp_dir() . '/qr_' . bin2hex(random_bytes(8)) . '.png';
        $result->saveToFile($tmpFile);

        return $tmpFile;
    }

    private function captionMessage(string $language, string $content): string
    {
        $preview = strlen($content) > 60 ? substr($content, 0, 57) . '...' : $content;

        return match ($language) {
            'en' => "📷 *QR Code generated*\n\nContent: `{$preview}`",
            'pt' => "📷 *QR Code gerado*\n\nConteúdo: `{$preview}`",
            default => "📷 *Código QR generado*\n\nContenido: `{$preview}`",
        };
    }

    private function noContentMessage(string $language): string
    {
        return match ($language) {
            'en' => "❌ Please provide text or a URL to encode.\n\nUsage: `/qr https://example.com`",
            'pt' => "❌ Forneça um texto ou URL para codificar.\n\nUso: `/qr https://example.com`",
            default => "❌ Proporciona un texto o URL para codificar.\n\nUso: `/qr https://ejemplo.com`",
        };
    }

    private function tooLongMessage(string $language, int $max): string
    {
        return match ($language) {
            'en' => "❌ Content too long. Maximum {$max} characters for a QR code.",
            'pt' => "❌ Conteúdo muito longo. Máximo {$max} caracteres para um QR code.",
            default => "❌ Contenido muy largo. Máximo {$max} caracteres para un código QR.",
        };
    }

    private function ok(): Response
    {
        $r = new Response(200);
        $r->getBody()->write('OK');
        return $r;
    }
}
