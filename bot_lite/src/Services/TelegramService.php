<?php
namespace App\Services;

use GuzzleHttp\Client;

class TelegramService {
    private Client $client;

    public function __construct() {
        $this->client = new Client(['base_uri' => 'https://api.telegram.org/bot']);
    }

    public function sendMessage(string $token, string $chatId, string $text, string $parseMode = 'Markdown', array $replyMarkup = []): void {
        $this->client->post($token . '/sendMessage', [
            'json' => [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'reply_markup' => $replyMarkup
            ]
        ]);
    }

    public function setWebhook(string $token, string $url, string $secret): bool {
        $res = $this->client->post($token . '/setWebhook', [
            'json' => ['url' => $url, 'secret_token' => $secret]
        ]);
        return $res->getStatusCode() === 200;
    }
}
