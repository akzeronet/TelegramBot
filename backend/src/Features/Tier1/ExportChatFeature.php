<?php
declare(strict_types=1);

namespace App\Features\Tier1;

use App\Features\FeatureInterface;
use App\Services\DatabaseService;
use App\Services\TelegramService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * ExportChatFeature — Tier 1 (PHP Puro)
 *
 * Comando: /export
 * Comando: /export 30   (últimos 30 días, default: 90)
 *
 * Recupera el historial de chat_messages del usuario desde Postgres,
 * genera un PDF con formato legible y lo envía como documento de Telegram.
 *
 * El PDF se genera en memoria (string), se escribe en archivo temporal,
 * se envía y se elimina. Nunca persiste en disco.
 *
 * NOTA: El historial > 90 días está en S3 (Fase 2).
 * Por ahora solo exporta lo que está en Postgres (últimos 90 días activos).
 *
 * Librería: dompdf/dompdf
 * No consume tokens de IA. No toca n8n.
 */
class ExportChatFeature implements FeatureInterface
{
    private const DEFAULT_DAYS = 90;
    private const MAX_DAYS     = 90; // Lo que hay en Postgres (ver Módulo C)
    private const MAX_MESSAGES = 500; // Límite de mensajes por exportación

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

        // Parsear días opcionales: "/export 30" o solo "/export"
        $parts = explode(' ', $text, 2);
        $days  = isset($parts[1]) ? (int) trim($parts[1]) : self::DEFAULT_DAYS;
        $days  = max(1, min($days, self::MAX_DAYS)); // Clamp entre 1 y 90

        // --- Aviso de que se está generando (puede tardar) ---
        $this->telegram->sendTyping($botToken, $chatId);
        $this->telegram->sendMessage(
            botToken: $botToken,
            chatId:   $chatId,
            text:     $this->processingMessage($language, $days),
        );

        // --- Recuperar mensajes de la DB ---
        $messages = $this->db->queryAll(
            "SELECT role, content, created_at
             FROM chat_messages
             WHERE uid = $1
               AND created_at >= NOW() - ($2 || ' days')::INTERVAL
               AND archived = FALSE
             ORDER BY created_at ASC
             LIMIT $3",
            [$uid, $days, self::MAX_MESSAGES],
        );

        if (empty($messages)) {
            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->emptyMessage($language, $days),
            );
            return $this->ok();
        }

        // --- Generar PDF ---
        $tmpFile  = $this->generatePdf($messages, $days, $language);
        $filename = 'chat_export_' . date('Y-m-d') . '.pdf';

        // --- Registrar uso ---
        $this->db->incrementFeatureUsage($uid, 'export_chat', 'tier1_php');

        // --- Enviar PDF al usuario ---
        $this->telegram->sendDocument(
            botToken: $botToken,
            chatId:   $chatId,
            filePath: $tmpFile,
            caption:  $this->captionMessage($language, count($messages), $days),
        );

        @unlink($tmpFile);

        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function generatePdf(array $messages, int $days, string $language): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false); // Seguridad: sin recursos externos
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildHtml($messages, $days, $language));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $tmpFile = sys_get_temp_dir() . '/export_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($tmpFile, $dompdf->output());

        return $tmpFile;
    }

    private function buildHtml(array $messages, int $days, string $language): string
    {
        $title     = match ($language) {
            'en' => "Chat History Export",
            'pt' => "Exportação do Histórico de Chat",
            default => "Exportación del Historial de Chat",
        };
        $generated = match ($language) {
            'en' => "Generated on",
            'pt' => "Gerado em",
            default => "Generado el",
        };
        $period    = match ($language) {
            'en' => "Last {$days} days",
            'pt' => "Últimos {$days} dias",
            default => "Últimos {$days} días",
        };

        $rows = '';
        foreach ($messages as $msg) {
            $isUser   = $msg['role'] === 'user';
            $roleLabel = $isUser
                ? ($language === 'en' ? 'You' : ($language === 'pt' ? 'Você' : 'Tú'))
                : 'AI';
            $bgColor  = $isUser ? '#EBF5FB' : '#FDFEFE';
            $align    = $isUser ? 'right' : 'left';
            $date     = (new \DateTime($msg['created_at']))->format('d/m/Y H:i');
            $content  = htmlspecialchars($msg['content'], ENT_QUOTES, 'UTF-8');
            $content  = nl2br($content);

            $rows .= "
            <tr style='background:{$bgColor};'>
                <td style='text-align:{$align}; padding:10px 16px; vertical-align:top;'>
                    <span style='font-size:10px; color:#888; display:block; margin-bottom:4px;'>
                        <strong>{$roleLabel}</strong> · {$date}
                    </span>
                    <span style='font-size:12px; line-height:1.6; color:#222;'>{$content}</span>
                </td>
            </tr>";
        }

        return "
        <!DOCTYPE html>
        <html lang='{$language}'>
        <head>
            <meta charset='UTF-8'>
            <style>
                body  { font-family: 'DejaVu Sans', sans-serif; color: #333; margin: 30px; }
                h1    { font-size: 20px; color: #1a1a2e; border-bottom: 2px solid #4A90D9;
                        padding-bottom: 8px; margin-bottom: 4px; }
                .meta { font-size: 11px; color: #888; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; }
                tr    { border-bottom: 1px solid #EEE; }
            </style>
        </head>
        <body>
            <h1>{$title}</h1>
            <p class='meta'>{$generated}: " . date('d/m/Y H:i') . " · {$period} · " . count($messages) . " msgs</p>
            <table>
                <tbody>{$rows}</tbody>
            </table>
        </body>
        </html>";
    }

    private function processingMessage(string $language, int $days): string
    {
        return match ($language) {
            'en' => "⏳ Generating PDF for the last {$days} days... please wait.",
            'pt' => "⏳ Gerando PDF dos últimos {$days} dias... aguarde.",
            default => "⏳ Generando PDF de los últimos {$days} días... un momento.",
        };
    }

    private function emptyMessage(string $language, int $days): string
    {
        return match ($language) {
            'en' => "📭 No messages found in the last {$days} days.",
            'pt' => "📭 Nenhuma mensagem encontrada nos últimos {$days} dias.",
            default => "📭 No se encontraron mensajes en los últimos {$days} días.",
        };
    }

    private function captionMessage(string $language, int $count, int $days): string
    {
        return match ($language) {
            'en' => "📄 *Chat Export* — {$count} messages from the last {$days} days.",
            'pt' => "📄 *Exportação de Chat* — {$count} mensagens dos últimos {$days} dias.",
            default => "📄 *Exportación de Chat* — {$count} mensajes de los últimos {$days} días.",
        };
    }

    private function ok(): Response
    {
        $r = new Response(200);
        $r->getBody()->write('OK');
        return $r;
    }
}
