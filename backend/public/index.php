<?php
declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\TelegramService;
use App\Middleware\SecurityManager;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// 1. Cargar Entorno
if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// 2. Instanciar Servicios (Directo y simple)
$db = new DatabaseService();
$telegram = new TelegramService();

// 3. Webhook Omnichannel (Master y Personales)
$app->post('/webhook/{type}', function ($request, $response, $args) use ($db, $telegram) {
    $data = (array)$request->getParsedBody();
    $update = $data['message'] ?? $data['callback_query']['message'] ?? null;
    
    if (!$update) return $response->withStatus(200);

    // Seguridad: Token de Webhook
    if ($request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token') !== $_ENV['TELEGRAM_WEBHOOK_SECRET']) {
        return $response->withStatus(401);
    }

    $senderId = (string)($update['from']['id'] ?? '');
    $user = $db->getOrCreateUser($senderId);

    // Bloqueo de Inactividad
    if ($user['status'] === 'inactive') {
        $telegram->sendMessage($_ENV['TELEGRAM_BOT_TOKEN'], $senderId, "🔒 Cuenta inactiva. Por favor, renueva tu suscripción.");
        return $response->withStatus(200);
    }

    // 4. Enviar a n8n Cloud (El cerebro)
    $client = new \GuzzleHttp\Client();
    $client->post($_ENV['N8N_WEBHOOK_URL'], [
        'json' => [
            'user' => $user,
            'telegram_data' => $data,
            'bot_type' => $args['type']
        ]
    ]);

    $response->getBody()->write("OK");
    return $response;
});

// Health Check
$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'online', 'db' => 'sqlite_wal']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
