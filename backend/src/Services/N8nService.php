<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * N8nService — Dispatcher hacia el Orquestador de IA
 *
 * Envía el payload del usuario a n8n de forma fire & forget.
 * n8n se encarga de: recuperar contexto + RAG + llamar a OmniRoute + responder al usuario.
 *
 * El secreto compartido N8N_WEBHOOK_SECRET autentica las peticiones
 * entre PHP y n8n para que ningún tercero pueda invocar los workflows.
 */
class N8nService
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri'        => $_ENV['N8N_WEBHOOK_URL'],
            'timeout'         => 5.0,   // Fire & forget: timeout corto
            'connect_timeout' => 3.0,
            'headers'         => [
                'Content-Type' => 'application/json',
                'X-N8N-Secret' => $_ENV['N8N_WEBHOOK_SECRET'],
            ],
        ]);
    }

    /**
     * Despacha el payload del mensaje a n8n.
     *
     * Payload esperado por n8n:
     *   uid, chat_id, sender_id, bot_id,
     *   text, has_photo, photo, has_voice, voice,
     *   language, features_active, persona_prompt, subscription,
     *   quota_warning, model
     */
    public function dispatch(array $payload): void
    {
        try {
            // async_request no existe en Guzzle estándar pero con curl_exec en background
            // se simula "fire & forget" cerrando la conexión inmediatamente.
            // Para producción real usar un queue (Redis/RabbitMQ) en Fase futura.
            $this->http->postAsync('', ['json' => $payload])->then(
                fn() => null,    // éxito: ignorar
                fn() => null,    // error: ignorar (n8n tiene su propio reintento)
            )->wait(false);      // false = no bloquear el hilo de PHP

        } catch (RequestException $e) {
            error_log('[N8nService] dispatch failed: ' . $e->getMessage());
            // No relanzar — la respuesta al usuario vendrá de n8n directamente
        }
    }
}
