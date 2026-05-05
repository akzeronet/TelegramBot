<?php
declare(strict_types=1);

use App\Middleware\SecurityManager;
use App\Middleware\QuotaController;
use App\Middleware\FeatureDispatcher;
use App\Controllers\WebhookController;
use App\Controllers\ShortLinkController;
use App\Controllers\MenuController;
use App\Controllers\PaymentController;
use App\Controllers\CheckoutController;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// --- Cargar variables de entorno ---
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// --- Validar variables críticas de entorno ---
$dotenv->required([
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
    'MASTER_KEY', 'JWT_SECRET',
    'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET',
    'N8N_WEBHOOK_URL', 'N8N_WEBHOOK_SECRET',
    'APP_DOMAIN',
]);

// --- Contenedor de Inyección de Dependencias ---
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../src/dependencies.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

// --- Middlewares globales ---
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: $_ENV['APP_ENV'] === 'development',
    logErrors: true,
    logErrorDetails: true,
);

// --- Rutas ---

$app->post('/webhook/master', [WebhookController::class, 'handleMaster'])
    ->add(FeatureDispatcher::class)
    ->add(QuotaController::class)
    ->add(SecurityManager::class);

$app->post('/webhook/bot/{bot_id}', [WebhookController::class, 'handlePersonalBot'])
    ->add(FeatureDispatcher::class)
    ->add(QuotaController::class)
    ->add(SecurityManager::class);

$app->get('/s/{code}', [ShortLinkController::class, 'redirect']);

$app->post('/payment/webhook', [PaymentController::class, 'handleWebhook']);
$app->get('/payment/success', [PaymentController::class, 'handleSuccess']);
$app->get('/checkout', [CheckoutController::class, 'renderCheckout']);

$app->post('/internal/close', [WebhookController::class, 'handleClose']);

$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'ok', 'ts' => time()]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
