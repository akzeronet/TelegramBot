<?php
declare(strict_types=1);

use App\Services\PocketBaseService;
use App\Services\TelegramService;
use App\Middleware\SecurityManager;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Cargar .env
if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// Instanciar el servicio de PocketBase
$db = new PocketBaseService();
$telegram = new TelegramService();

// Ruta Maestra para todos los bots
$app->post('/webhook/{bot_type}', function ($request, $response, $args) use ($db, $telegram) {
    $data = (array)$request->getParsedBody();
    $update = $data['message'] ?? $data['callback_query']['message'] ?? null;

    if (!$update) return $response->withStatus(200);

    // Validación de Seguridad
    if ($request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token') !== $_ENV['TELEGRAM_WEBHOOK_SECRET']) {
        return $response->withStatus(401);
    }

    $senderId = (string)($update['from']['id'] ?? '');
    $user = $db->getOrCreateUser($senderId);

    // Lógica de Bloqueo por Suscripción
    if ($user['status'] === 'inactive') {
        $telegram->sendMessage($_ENV['TELEGRAM_BOT_TOKEN'], $senderId, "🔒 Tu cuenta está inactiva. Por favor renueva tu plan.");
        return $response->withStatus(200);
    }

    // DISPATCHER A n8n CLOUD
    $client = new \GuzzleHttp\Client();
    $client->post($_ENV['N8N_WEBHOOK_URL'], [
        'json' => [
            'user_id' => $user['id'], // ID interno de PocketBase
            'sender_id' => $senderId,
            'message' => $update['text'] ?? '',
            'bot_type' => $args['bot_type'],
            'subscription' => $user['subscription']
        ]
    ]);

    $response->getBody()->write("OK TBOTV1");
    return $response;
});

// Ruta de salud
$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'active', 'engine' => 'tbotv1-pb']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
