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
 * QuotaController — El Contador (Módulo 2)
 *
 * Verifica que el usuario no haya superado su límite diario de mensajes
 * ANTES de enviar nada a n8n u OmniRoute, evitando costos innecesarios.
 *
 * Límites por plan:
 *   standard → 300 mensajes/día
 *   premium  → configurable (daily_limit_override)
 *   admin    → sin límite
 *
 * Al llegar al límite: notifica al usuario y bloquea el request.
 * Al llegar al mensaje 300 (penúltimo): incluye aviso de límite próximo.
 */
class QuotaController implements MiddlewareInterface
{
    // Límites base por tipo de plan (pueden ser sobreescritos por daily_limit_override)
    private const PLAN_LIMITS = [
        'standard' => 300,
        'premium'  => 500,  // Base; puede aumentarse con daily_limit_override
        'admin'    => PHP_INT_MAX,
    ];

    // Mensajes de aviso cuando falta poco para el límite
    private const WARNING_THRESHOLD = 10; // Avisar cuando queden ≤10 mensajes

    public function __construct(
        private readonly DatabaseService $db,
        private readonly TelegramService $telegram,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {

        $user   = $request->getAttribute('user');
        $chatId = $request->getAttribute('chat_id');
        $botId  = $request->getAttribute('bot_id');

        // Admins no tienen límite
        if ($user['subscription_type'] === 'admin') {
            return $handler->handle($request);
        }

        // --- Calcular el límite efectivo ---
        $baseLimit     = self::PLAN_LIMITS[$user['subscription_type']] ?? self::PLAN_LIMITS['standard'];
        $effectiveLimit = $user['daily_limit_override'] ?? $baseLimit;

        // --- Contar mensajes del usuario HOY ---
        $todayCount = $this->db->queryScalar(
            "SELECT COUNT(*) FROM chat_messages
             WHERE uid = $1
               AND role = 'user'
               AND created_at >= CURRENT_DATE
               AND archived = FALSE",
            [$user['uid']],
        );

        // --- Límite superado: bloquear ---
        if ((int) $todayCount >= $effectiveLimit) {
            $botToken = $botId
                ? $this->db->getBotToken((int) $botId)
                : $_ENV['TELEGRAM_BOT_TOKEN'];

            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->getLimitReachedMessage($user['language'], $effectiveLimit),
            );

            return $this->blocked();
        }

        // --- Aviso cuando quedan pocos mensajes ---
        $remaining = $effectiveLimit - (int) $todayCount;
        if ($remaining <= self::WARNING_THRESHOLD) {
            $request = $request->withAttribute('quota_warning', $remaining);
        }

        // --- Pasar el conteo actual para registro posterior ---
        $request = $request->withAttribute('daily_count', (int) $todayCount);

        return $handler->handle($request);
    }

    // -------------------------------------------------------------------------

    private function blocked(): ResponseInterface
    {
        $response = new Response(200); // 200 a Telegram para evitar reintentos
        $response->getBody()->write('QUOTA_EXCEEDED');
        return $response;
    }

    private function getLimitReachedMessage(string $language, int $limit): string
    {
        return match ($language) {
            'en' => "🚫 Daily limit reached ({$limit} messages).\n\nUpgrade your plan or wait until midnight to continue. Use /subscribe to see options.",
            'pt' => "🚫 Limite diário atingido ({$limit} mensagens).\n\nFaça upgrade ou aguarde a meia-noite. Use /subscribe.",
            default => "🚫 Has alcanzado tu límite diario de {$limit} mensajes.\n\nMejora tu plan o espera hasta la medianoche. Usa /subscribe para ver opciones.",
        };
    }
}
