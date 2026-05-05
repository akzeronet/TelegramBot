<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\DatabaseService;
use App\Services\TelegramService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * SecurityManager — El Guardián (Módulo 1)
 *
 * Valida la cadena de seguridad completa por cada request:
 *   bot_token (URL) → uid (dueño del bot) → sender_id (platform_id vinculado)
 *
 * Para el bot maestro: solo valida que el sender exista en user_identities.
 * Para bots personales: valida además que el sender sea EXACTAMENTE el dueño.
 */
class SecurityManager implements MiddlewareInterface
{
    public function __construct(
        private readonly DatabaseService $db,
        private readonly TelegramService $telegram,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {

        // --- 1. Validar secreto de Telegram ---
        // Telegram firma cada webhook con el header X-Telegram-Bot-Api-Secret-Token
        $incomingSecret = $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token');
        if (!hash_equals($_ENV['TELEGRAM_WEBHOOK_SECRET'], $incomingSecret)) {
            return $this->deny($request, 401, 'Unauthorized: invalid webhook secret');
        }

        // --- 2. Parsear el payload de Telegram ---
        $body   = (array) $request->getParsedBody();
        $update = $body['message'] ?? $body['callback_query']['message'] ?? null;

        if (!$update) {
            // Update sin mensaje (ej. channel_post, edited_message) — ignorar silenciosamente
            return $this->ok();
        }

        $senderId   = (string) ($update['from']['id'] ?? '');
        $chatId     = (string) ($update['chat']['id'] ?? '');
        $isPersonal = $request->getAttribute('bot_id') !== null;
        $botId      = $request->getAttribute('bot_id');

        // --- 3. Flujo para Bot Personal ---
        if ($isPersonal) {
            $bot = $this->db->queryOne(
                'SELECT ub.uid, ui.platform_id AS owner_platform_id
                 FROM user_bots ub
                 JOIN user_identities ui ON ui.uid = ub.uid AND ui.platform = $1
                 WHERE ub.bot_id = $2 AND ub.is_active = TRUE',
                ['telegram', (int) $botId],
            );

            if (!$bot) {
                return $this->deny($request, 403, 'Bot not found or inactive');
            }

            // *** BOT-USER LOCK ***
            // El sender DEBE ser exactamente el dueño registrado del bot.
            // Cualquier otro usuario recibe "Acceso Denegado" — NO consume tokens.
            if ($senderId !== $bot['owner_platform_id']) {
                $this->telegram->sendMessage(
                    botToken: $this->db->getBotToken((int) $botId),
                    chatId:   $chatId,
                    text:     $this->getAccessDeniedMessage($request),
                );
                return $this->ok(); // Retorna 200 a Telegram (no reintentos)
            }

            $uid = $bot['uid'];

        // --- 4. Flujo para Bot Maestro ---
        } else {
            $identity = $this->db->queryOne(
                'SELECT uid FROM user_identities WHERE platform = $1 AND platform_id = $2',
                ['telegram', $senderId],
            );

            if (!$identity) {
                // Nuevo usuario — registrar y enviar onboarding
                $uid = $this->db->createUser($senderId);
            } else {
                $uid = $identity['uid'];
            }
        }

        // --- 5. Cargar perfil completo del usuario ---
        $user = $this->db->queryOne(
            'SELECT uid, language, subscription_type, subscription_status,
                    expiry_date, daily_limit_override, features_active, persona_prompt
             FROM users WHERE uid = $1',
            [$uid],
        );

        if (!$user) {
            return $this->deny($request, 500, 'User record not found after identity check');
        }

        // --- 6. Verificar suscripción activa e inactividad ---
        if ($user['subscription_status'] === 'inactive' || $user['subscription_status'] === 'expired') {
            // Permitir solo comandos básicos (start, subscribe, history, delete)
            $text = trim($update['text'] ?? '');
            $isBasicCommand = preg_match('/^\/(start|subscribe|plan|history|delete_data|export)/i', $text);
            $isCallback = isset($body['callback_query']);

            if (!$isBasicCommand && !$isCallback) {
                $this->telegram->sendMessage(
                    botToken: $_ENV['TELEGRAM_BOT_TOKEN'],
                    chatId:   $chatId,
                    text:     $this->getInactiveMessage($user['language']),
                );
                return $this->ok();
            }
        }

        // --- 7. Pasar datos al siguiente middleware/controlador ---
        $request = $request
            ->withAttribute('uid', $uid)
            ->withAttribute('user', $user)
            ->withAttribute('sender_id', $senderId)
            ->withAttribute('chat_id', $chatId)
            ->withAttribute('update', $update);

        return $handler->handle($request);
    }

    // -------------------------------------------------------------------------

    private function deny(
        ServerRequestInterface $request,
        int $status,
        string $reason,
    ): ResponseInterface {
        $response = new Response($status);
        $response->getBody()->write(json_encode(['error' => $reason]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function ok(): ResponseInterface
    {
        $response = new Response(200);
        $response->getBody()->write('OK');
        return $response;
    }

    private function getAccessDeniedMessage(ServerRequestInterface $request): string
    {
        // El bot personal no tiene contexto de idioma del intruso — usar inglés/español neutro
        return "⛔ Acceso denegado. Este bot es privado.\n\nAccess denied. This is a private bot.";
    }

    private function getInactiveMessage(string $language): string
    {
        return match ($language) {
            'en' => "🔒 *Account Inactive*\n\nYour subscription has expired. Access to AI chat and personal bots is disabled.\n\nYou can still:\n• /subscribe to reactivate.\n• /export to download your data.\n• /delete_data to wipe your history.",
            default => "🔒 *Cuenta Inactiva*\n\nTu suscripción ha vencido. El acceso al chat de IA y tus bots personales está desactivado.\n\nTodavía puedes:\n• /subscribe para reactivar.\n• /export para descargar tus datos.\n• /delete_data para borrar tu historial.",
        };
    }

    private function getExpiredMessage(string $language): string
    {
        return match ($language) {
            'en' => "⚠️ Your subscription has expired. Renew at /subscribe to continue.",
            'pt' => "⚠️ Sua assinatura expirou. Renove em /subscribe para continuar.",
            default => "⚠️ Tu suscripción ha vencido. Renueva con /subscribe para continuar.",
        };
    }
}
