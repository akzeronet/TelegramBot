<?php
declare(strict_types=1);

namespace App\Features\Tier1;

use App\Features\FeatureInterface;
use App\Services\DatabaseService;
use App\Services\TelegramService;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * FileConverterFeature — Tier 1 (PHP Puro)
 *
 * Comando: /convert
 *
 * NOTA: Esta es la base estructural. Dado que la conversión real de archivos
 * (ej: PDF a Word, WebP a PNG, etc.) requiere binarios del sistema (ffmpeg, libreoffice,
 * imagemagick, etc.), en esta primera iteración el bot informa al usuario cómo usarlo
 * o simula la recepción. La conversión real requerirá agregar esas dependencias al Dockerfile.
 *
 * Tier 1 porque se hace localmente, sin IA.
 */
class FileConverterFeature implements FeatureInterface
{
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

        // Avisar al usuario que esta feature requiere adjuntar un archivo.
        $this->telegram->sendMessage(
            botToken: $botToken,
            chatId:   $chatId,
            text:     $this->instructionsMessage($language),
        );

        return $this->ok();
    }

    private function instructionsMessage(string $language): string
    {
        return match ($language) {
            'en' => "🔄 *File Converter*\n\nTo convert a file, please reply to a file with the command `/convert <format>`.\n\n_Example: Reply to a .webp image with `/convert png`_",
            'pt' => "🔄 *Conversor de Arquivos*\n\nPara converter, responda a um arquivo com o comando `/convert <formato>`.\n\n_Exemplo: Responda a uma imagem .webp com `/convert png`_",
            default => "🔄 *Conversor de Archivos*\n\nPara convertir un archivo, responde a un archivo con el comando `/convert <formato>`.\n\n_Ejemplo: Responde a una imagen .webp con `/convert png`_",
        };
    }

    private function ok(): Response
    {
        $r = new Response(200);
        $r->getBody()->write('OK');
        return $r;
    }
}
