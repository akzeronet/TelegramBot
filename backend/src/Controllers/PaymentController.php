<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\TelegramService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

/**
 * PaymentController — Pasarela de Pagos (Stripe)
 *
 * Maneja:
 * 1. Webhooks de Stripe (activación de suscripción al recibir pago).
 * 2. Redirección de éxito.
 */
class PaymentController
{
    public function __construct(
        private readonly DatabaseService $db,
        private readonly TelegramService $telegram,
    ) {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
    }

    /**
     * handleWebhook — El punto más importante de la monetización.
     * Recibe la notificación de Stripe y activa el plan en la DB.
     */
    public function handleWebhook(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload   = (string)$request->getBody();
        $sigHeader = $request->getHeaderLine('Stripe-Signature');
        $endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'];

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\UnexpectedValueException $e) {
            return $this->error($response, 'Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            return $this->error($response, 'Invalid signature', 400);
        }

        // Manejar el evento de pago completado
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            
            // Extraer metadata que enviamos al crear el link de pago
            $uid      = $session->metadata->uid      ?? null;
            $planType = $session->metadata->plan_type ?? 'premium';

            if ($uid) {
                $this->activateSubscription($uid, $planType);
            }
        }

        return $this->success($response, ['status' => 'received']);
    }

    /**
     * handleSuccess — Página a la que vuelve el usuario tras pagar.
     */
    public function handleSuccess(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write("<h1>✅ ¡Pago Exitoso!</h1><p>Tu plan ha sido activado. Ya puedes volver a Telegram y disfrutar de tus nuevas funciones.</p>");
        return $response;
    }

    // -------------------------------------------------------------------------
    // Lógica Interna
    // -------------------------------------------------------------------------

    private function activateSubscription(string $uid, string $planType): void
    {
        // 1. Actualizar usuario en DB
        $this->db->execute(
            "UPDATE users 
             SET subscription_type = $1, 
                 subscription_status = 'active',
                 expiry_date = NOW() + INTERVAL '30 days',
                 updated_at = NOW()
             WHERE uid = $2",
            [$planType, $uid]
        );

        // 2. Registrar el pago en el historial
        $this->db->execute(
            "INSERT INTO payments (uid, platform_ref, amount, plan_type, status)
             VALUES ($1, 'stripe_webhook', 0, $2, 'completed')",
            [$uid, $planType]
        );

        // 3. Notificar al usuario por Telegram (vía Identity)
        $identity = $this->db->queryOne(
            "SELECT platform_id FROM user_identities WHERE uid = $1 AND platform = 'telegram'",
            [$uid]
        );

        if ($identity) {
            $text = "🎉 *¡Suscripción Activada!*\n\nTu plan *" . ucfirst($planType) . "* ya está activo por 30 días. ¡Gracias por tu apoyo!";
            $this->telegram->sendMessage($_ENV['TELEGRAM_BOT_TOKEN'], $identity['platform_id'], $text);
        }
    }

    private function success(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    private function error(ResponseInterface $response, string $msg, int $code): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $msg]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($code);
    }
}
