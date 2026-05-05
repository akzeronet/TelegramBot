<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * TelegramService — Envío de respuestas al usuario
 */
class TelegramService
{
    private const BASE_URL = 'https://api.telegram.org/bot';

    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout'         => 10.0,
            'connect_timeout' => 5.0,
        ]);
    }

    public function sendMessage(
        string  $botToken,
        string  $chatId,
        string  $text,
        ?string $parseMode    = 'Markdown',
        ?array  $replyMarkup  = null,
    ): bool {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => $parseMode,
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        $res = $this->post($botToken, 'sendMessage', $payload);
        return $res['ok'] ?? false;
    }

    public function sendInvoice(
        string $botToken,
        string $chatId,
        string $title,
        string $description,
        string $payload,
        string $currency,
        array  $prices,
        ?string $providerToken = null
    ): bool {
        $params = [
            'chat_id'             => $chatId,
            'title'               => $title,
            'description'         => $description,
            'payload'             => $payload,
            'provider_token'      => $providerToken ?? '', // Vacío para Stars
            'currency'            => $currency,
            'prices'              => json_encode($prices),
        ];

        $res = $this->post($botToken, 'sendInvoice', $params);
        return $res['ok'] ?? false;
    }

    public function answerPreCheckoutQuery(string $botToken, string $id, bool $ok, ?string $errorMessage = null): bool
    {
        $res = $this->post($botToken, 'answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $id,
            'ok'                    => $ok,
            'error_message'         => $errorMessage
        ]);
        return $res['ok'] ?? false;
    }

    public function sendDocument(
        string $botToken,
        string $chatId,
        string $filePath,
        string $caption = '',
    ): bool {
        return $this->post($botToken, 'sendDocument', [
            'chat_id' => $chatId,
            'caption' => $caption,
        ], [
            'document' => fopen($filePath, 'r'),
        ])['ok'] ?? false;
    }

    public function sendTyping(string $botToken, string $chatId): void
    {
        $this->post($botToken, 'sendChatAction', [
            'chat_id' => $chatId,
            'action'  => 'typing',
        ]);
    }

    public function setWebhook(string $botToken, string $webhookUrl): bool
    {
        $res = $this->post($botToken, 'setWebhook', [
            'url'             => $webhookUrl,
            'secret_token'    => $_ENV['TELEGRAM_WEBHOOK_SECRET'],
            'allowed_updates' => json_encode(['message', 'callback_query', 'pre_checkout_query']),
        ]);
        return $res['ok'] ?? false;
    }

    public function deleteWebhook(string $botToken): bool
    {
        $res = $this->post($botToken, 'deleteWebhook', []);
        return $res['ok'] ?? false;
    }

    private function post(
        string $botToken,
        string $method,
        array  $form,
        array  $multipart = [],
    ): array {
        try {
            $url = self::BASE_URL . $botToken . '/' . $method;

            if ($multipart) {
                $parts = [];
                foreach ($form as $name => $value) {
                    $parts[] = ['name' => $name, 'contents' => (string)$value];
                }
                foreach ($multipart as $name => $contents) {
                    $parts[] = ['name' => $name, 'contents' => $contents];
                }
                $response = $this->http->post($url, ['multipart' => $parts]);
            } else {
                $response = $this->http->post($url, ['json' => $form]);
            }

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            error_log("[TelegramService] {$method} failed: " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
