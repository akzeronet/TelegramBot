<?php
declare(strict_types=1);

use App\Services\TelegramService;
use App\Middleware\SecurityManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// 1. Inicialización
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$telegram = new TelegramService();

// 2. Webhook Omnichannel
$app->post('/webhook/{bot_type}', function (Request $request, Response $response, array $args) use ($telegram) {
    $data = $request->getParsedBody();
    $update = $data['message'] ?? $data['callback_query']['message'] ?? null;
    if (!$update) return $response->withStatus(200);

    // Seguridad
    $secret = $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token');
    if ($secret !== $_ENV['TELEGRAM_WEBHOOK_SECRET']) return $response->withStatus(401);

    $senderId = (string)$update['from']['id'];
    $botType = $args['bot_type']; // 'master' o un ID de bot personal

    // 3. Conexión Supabase
    $db = new PDO(
        "pgsql:host={$_ENV['SUPABASE_HOST']};dbname={$_ENV['SUPABASE_DB']}",
        $_ENV['SUPABASE_USER'],
        $_ENV['SUPABASE_PASS']
    );

    // 4. Lógica de Usuario e Inactividad
    $stmt = $db->prepare("SELECT uid, subscription_status FROM users WHERE platform_id = ?");
    $stmt->execute([$senderId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $stmt = $db->prepare("INSERT INTO users (platform_id) VALUES (?) RETURNING uid");
        $stmt->execute([$senderId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (($user['subscription_status'] ?? '') === 'inactive') {
        $telegram->sendMessage($_ENV['TELEGRAM_BOT_TOKEN'], $senderId, "🔒 Tu cuenta está inactiva. Renueva tu plan para continuar.");
        return $response->withStatus(200);
    }

    // 5. Envío a n8n Cloud
    $client = new \GuzzleHttp\Client();
    $client->post($_ENV['N8N_CLOUD_URL'], [
        'json' => [
            'uid' => $user['uid'],
            'sender_id' => $senderId,
            'text' => $update['text'] ?? '',
            'bot_type' => $botType
        ]
    ]);

    $response->getBody()->write("OK");
    return $response;
});

$app->run();
