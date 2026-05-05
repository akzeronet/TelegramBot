<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\DatabaseService;
use App\Services\TelegramService;
use App\Features\Tier1\LinkShortenerFeature;
use App\Features\Tier1\QrGeneratorFeature;
use App\Features\Tier1\ExportChatFeature;
use App\Features\Tier1\FileConverterFeature;
use App\Features\Tier2\WeatherFeature;
use App\Features\Tier2\CurrencyRatesFeature;
use App\Features\Tier2\UrlPreviewFeature;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * FeatureDispatcher — El Enrutador de Funcionalidades
 *
 * Detecta si el mensaje del usuario activa una Feature específica
 * y la enruta al Tier correcto ANTES de llegar al WebhookController.
 *
 * Tier 1 — PHP Puro: resuelto internamente, sin tocar n8n ni OmniRoute.
 * Tier 2 — Mixto:    PHP llama a una API externa, sin consumir modelo LLM.
 * Tier 3 — IA:       Pasa al WebhookController para ir a n8n → OmniRoute.
 *
 * Si una feature Tier 1 o Tier 2 maneja el request, retorna directamente
 * y el WebhookController nunca se ejecuta.
 */
class FeatureDispatcher implements MiddlewareInterface
{
    /**
     * Mapa de comandos/triggers a sus features Tier 1 y Tier 2.
     * Los mensajes que NO coincidan aquí son Tier 3 (IA por defecto).
     *
     * Formato: 'trigger_prefix' => [FeatureClass, 'feature_flag']
     */
    private const TIER1_TRIGGERS = [
        '/short'   => [LinkShortenerFeature::class, 'link_shortener'],
        '/qr'      => [QrGeneratorFeature::class,   'qr_generator'],
        '/export'  => [ExportChatFeature::class,     'export_chat'],
        '/convert' => [FileConverterFeature::class,  'file_converter'],
    ];

    private const TIER2_TRIGGERS = [
        '/weather'   => [WeatherFeature::class,       'weather'],
        '/currency'  => [CurrencyRatesFeature::class, 'currency_rates'],
        '/preview'   => [UrlPreviewFeature::class,    'url_preview'],
    ];

    public function __construct(
        private readonly DatabaseService $db,
        private readonly TelegramService $telegram,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {

        $update  = $request->getAttribute('update');
        $user    = $request->getAttribute('user');
        $chatId  = $request->getAttribute('chat_id');
        $botId   = $request->getAttribute('bot_id');
        $text    = trim($update['text'] ?? '');

        $botToken = $botId
            ? $this->db->getBotToken((int) $botId)
            : $_ENV['TELEGRAM_BOT_TOKEN'];

        // --- Detectar trigger de Tier 1 ---
        foreach (self::TIER1_TRIGGERS as $prefix => [$featureClass, $flag]) {
            if (str_starts_with($text, $prefix)) {
                return $this->dispatchFeature(
                    request:      $request,
                    handler:      $handler,
                    featureClass: $featureClass,
                    flag:         $flag,
                    user:         $user,
                    chatId:       $chatId,
                    botToken:     $botToken,
                    text:         $text,
                    tier:         'tier1_php',
                );
            }
        }

        // --- Detectar trigger de Tier 2 ---
        foreach (self::TIER2_TRIGGERS as $prefix => [$featureClass, $flag]) {
            if (str_starts_with($text, $prefix)) {
                return $this->dispatchFeature(
                    request:      $request,
                    handler:      $handler,
                    featureClass: $featureClass,
                    flag:         $flag,
                    user:         $user,
                    chatId:       $chatId,
                    botToken:     $botToken,
                    text:         $text,
                    tier:         'tier2_mixed',
                );
            }
        }

        // --- Sin trigger específico: es Tier 3 (IA) ---
        // El WebhookController enviará a n8n → OmniRoute.
        $request = $request->withAttribute('feature_tier', 'tier3_ai');
        return $handler->handle($request);
    }

    // -------------------------------------------------------------------------

    private function dispatchFeature(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler,
        string $featureClass,
        string $flag,
        array  $user,
        string $chatId,
        string $botToken,
        string $text,
        string $tier,
    ): ResponseInterface {

        $features = json_decode($user['features_active'], true);

        // --- Verificar que la feature está activada ---
        if (empty($features[$flag])) {
            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->getFeatureDisabledMessage($user['language'], $flag),
            );
            return $this->ok();
        }

        // --- Verificar sub-límite de la feature (Tier 2 y algunos Tier 3) ---
        if (!$this->checkFeatureQuota($user['uid'], $flag, $tier)) {
            $this->telegram->sendMessage(
                botToken: $botToken,
                chatId:   $chatId,
                text:     $this->getFeatureQuotaMessage($user['language'], $flag),
            );
            return $this->ok();
        }

        // --- Ejecutar la feature ---
        $feature = new $featureClass($this->db, $this->telegram);

        return $feature->handle(
            uid:      $user['uid'],
            chatId:   $chatId,
            botToken: $botToken,
            text:     $text,
            language: $user['language'],
        );
    }

    private function checkFeatureQuota(string $uid, string $flag, string $tier): bool
    {
        // Límites diarios por feature (null = sin límite propio)
        $featureLimits = [
            'vision'        => 20,
            'web_search'    => 30,
            'image_gen'     => 10,
            'voice_reply'   => 20,
            'link_shortener'=> 50,
            'qr_generator'  => 50,
            'weather'       => 20,
            'currency_rates'=> 30,
        ];

        $limit = $featureLimits[$flag] ?? null;
        if ($limit === null) {
            return true; // Sin sub-límite
        }

        $count = $this->db->queryScalar(
            "SELECT used_count FROM feature_usage
             WHERE uid = $1 AND feature_key = $2 AND period_start = CURRENT_DATE",
            [$uid, $flag],
        ) ?? 0;

        return (int) $count < $limit;
    }

    private function getFeatureDisabledMessage(string $language, string $flag): string
    {
        return match ($language) {
            'en' => "⚙️ The feature *{$flag}* is not active. Enable it in /settings → Features.",
            'pt' => "⚙️ A funcionalidade *{$flag}* não está ativa. Ative em /settings → Funcionalidades.",
            default => "⚙️ La funcionalidad *{$flag}* no está activa. Actívala en /settings → Funcionalidades.",
        };
    }

    private function getFeatureQuotaMessage(string $language, string $flag): string
    {
        return match ($language) {
            'en' => "⚠️ You've reached the daily limit for *{$flag}*. It resets at midnight.",
            'pt' => "⚠️ Você atingiu o limite diário de *{$flag}*. Reinicia à meia-noite.",
            default => "⚠️ Alcanzaste el límite diario de *{$flag}*. Se reinicia a medianoche.",
        };
    }

    private function ok(): \Slim\Psr7\Response
    {
        $response = new \Slim\Psr7\Response(200);
        $response->getBody()->write('OK');
        return $response;
    }
}
