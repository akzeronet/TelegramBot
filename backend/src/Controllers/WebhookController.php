<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\N8nService;
use App\Services\TelegramService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * WebhookController — Punto de entrada de mensajes
 */
class WebhookController
{
    public function __construct(
        private readonly DatabaseService $db,
        private readonly N8nService      $n8n,
        private readonly TelegramService $telegram,
        private readonly MenuController  $menu,
        private readonly PaymentController $payments, // Inyectamos el controlador de pagos para reutilizar activación
    ) {}

    public function handleMaster(
        ServerRequestInterface $request,
        ResponseInterface      $response,
    ): ResponseInterface {
        return $this->processMessage($request, $response, botId: null);
    }

    public function handlePersonalBot(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args,
    ): ResponseInterface {
        return $this->processMessage($request, $response, botId: (int) $args['bot_id']);
    }

    public function handleClose(
        ServerRequestInterface $request,
        ResponseInterface      $response,
    ): ResponseInterface {
        $secret = $request->getHeaderLine('X-N8N-Secret');
        if (!hash_equals($_ENV['N8N_WEBHOOK_SECRET'], $secret)) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $body       = (array) $request->getParsedBody();
        $uid        = $body['uid']         ?? null;
        $content    = $body['content']     ?? null;
        $tokensUsed = $body['tokens_used'] ?? 0;

        if (!$uid || !$content) {
            $response->getBody()->write(json_encode(['error' => 'Missing uid or content']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $this->db->execute(
            "INSERT INTO chat_messages (uid, role, content, tokens_used)
             VALUES ($1, 'assistant', $2, $3)",
            [$uid, $content, (int) $tokensUsed],
        );

        $response->getBody()->write(json_encode(['status' => 'saved']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function processMessage(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        ?int                   $botId,
    ): ResponseInterface {

        $user       = $request->getAttribute('user');
        $update     = $request->getAttribute('update');
        $senderId   = $request->getAttribute('sender_id');
        $chatId     = $request->getAttribute('chat_id');
        $quotaWarn  = $request->getAttribute('quota_warning');
        
        $botToken = $botId
            ? $this->db->getBotToken((int) $botId)
            : $_ENV['TELEGRAM_BOT_TOKEN'];

        // --- 1. Manejar Pre-Checkout Query (Validación de Estrellas) ---
        $preCheckout = $request->getParsedBody()['pre_checkout_query'] ?? null;
        if ($preCheckout) {
            $this->telegram->answerPreCheckoutQuery($botToken, $preCheckout['id'], true);
            $response->getBody()->write('OK');
            return $response;
        }

        // --- 2. Manejar Pago Exitoso (Confirmation) ---
        $successPayment = $update['successful_payment'] ?? null;
        if ($successPayment) {
            $payload  = $successPayment['invoice_payload']; // "pay_premium_UID"
            $parts    = explode('_', $payload);
            $planType = $parts[1] ?? 'premium';
            $uid      = $parts[2] ?? null;

            if ($uid) {
                // Reutilizamos la lógica de activación que ya tenemos
                $this->payments->activateSubscription($uid, $planType);
            }

            $response->getBody()->write('OK');
            return $response;
        }

        // --- 3. Manejar Clicks en Botones (Callback Query) ---
        $callbackData = $request->getParsedBody()['callback_query']['data'] ?? null;
        if ($callbackData) {
            return $this->menu->handleCallback($callbackData, $user, $chatId, $botToken);
        }

        $text = trim($update['text'] ?? '');

        // --- 4. Manejar Comandos de Menú (/start, /subscribe, /settings, /persona) ---
        if (str_starts_with($text, '/')) {
            $parts   = explode(' ', $text, 2);
            $command = $parts[0];
            $args    = $parts[1] ?? '';
            
            $handled = $this->menu->handleCommand($command, $user, $chatId, $botToken, $args);
            
            // Si el MenuController respondió algo (200), terminamos aquí
            if ($handled->getStatusCode() === 200 && (string)$handled->getBody() !== 'OK') {
                return $handled;
            }
        }

        // Ignorar mensajes vacíos si no es callback
        if ($text === '') {
            $response->getBody()->write('OK');
            return $response;
        }

        // --- 3. Flujo normal de IA ---
        $this->db->execute(
            "INSERT INTO chat_messages (uid, role, content) VALUES ($1, 'user', $2)",
            [$user['uid'], $text],
        );

        $payload = [
            'uid'        => $user['uid'],
            'chat_id'    => $chatId,
            'sender_id'  => $senderId,
            'bot_id'     => $botId,
            'text'       => $text,
            'has_photo'  => isset($update['photo']),
            'photo'      => $update['photo'] ?? null,
            'has_voice'  => isset($update['voice']),
            'voice'      => $update['voice'] ?? null,
            'language'   => $user['language'],
            'features_active' => json_decode($user['features_active'], true),
            'persona_prompt'  => $user['persona_prompt'],
            'subscription'    => $user['subscription_type'],
            'quota_warning'   => $quotaWarn,
            'model'           => $this->selectModel($user['subscription_type']),
        ];

        $this->n8n->dispatch($payload);

        $response->getBody()->write('OK');
        return $response;
    }

    private function selectModel(string $subscriptionType): string
    {
        return match ($subscriptionType) {
            'premium', 'admin' => 'anthropic/claude-3-5-haiku-20241022',
            default            => 'openai/gpt-4o-mini',
        };
    }
}
