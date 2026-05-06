<?php
namespace App\Services;

use GuzzleHttp\Client;

class PocketBaseService {
    private Client $client;
    private string $baseUrl;

    public function __construct() {
        $this->baseUrl = $_ENV['POCKETBASE_URL'] . '/api/collections';
        $this->client = new Client();
    }

    public function getOrCreateUser(string $platformId): array {
        // 1. Buscar si existe
        $response = $this->client->get($this->baseUrl . '/users/records', [
            'query' => ['filter' => "(platform_id='{$platformId}')"],
            'headers' => ['Authorization' => $_ENV['POCKETBASE_ADMIN_TOKEN'] ?? '']
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        
        if (!empty($data['items'])) {
            return $data['items'][0];
        }

        // 2. Si no existe, crearlo
        $response = $this->client->post($this->baseUrl . '/users/records', [
            'json' => [
                'platform_id' => $platformId,
                'subscription' => 'free',
                'status' => 'active',
                'language' => 'es'
            ]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function getBotToken(string $botId): ?string {
        $response = $this->client->get($this->baseUrl . '/user_bots/records', [
            'query' => ['filter' => "(bot_id='{$botId}' && is_active=true)"]
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['items'][0]['token'] ?? null;
    }
}
