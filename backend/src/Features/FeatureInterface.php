<?php
declare(strict_types=1);

namespace App\Features;

use Psr\Http\Message\ResponseInterface;

/**
 * Contrato base para todas las Features del sistema.
 *
 * Tier 1: PHP responde directamente (sin IA, sin n8n).
 * Tier 2: PHP llama a API externa y responde.
 * Tier 3: Pasa al WebhookController → n8n → OmniRoute.
 */
interface FeatureInterface
{
    /**
     * Ejecuta la feature y devuelve una respuesta HTTP.
     *
     * @param string      $uid       UID del usuario propietario
     * @param string      $chatId    Chat de Telegram al que responder
     * @param string      $botToken  Token del bot (maestro o personal)
     * @param string      $text      Texto completo del mensaje (/short https://...)
     * @param string      $language  Idioma del usuario (para mensajes de error/éxito)
     */
    public function handle(
        string $uid,
        string $chatId,
        string $botToken,
        string $text,
        string $language,
    ): ResponseInterface;
}
