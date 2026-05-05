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
$app->addBodyParsingMiddleware();   // Parsea JSON/form automáticamente
$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: $_ENV['APP_ENV'] === 'development',
    logErrors: true,
    logErrorDetails: true,
);

// --- Rutas ---

/**
 * Bot Maestro: webhook único en /webhook/master
 * Atiende a usuarios del Plan Estándar y mensajes del bot principal.
 * Middleware chain (ejecuta de abajo hacia arriba):
 *   SecurityManager → QuotaController → FeatureDispatcher → WebhookController
 */
$app->post('/webhook/master', [WebhookController::class, 'handleMaster'])
    ->add(FeatureDispatcher::class)
    ->add(QuotaController::class)
    ->add(SecurityManager::class);

/**
 * Bots Personales: webhook dinámico en /webhook/bot/{bot_id}
 * Cada usuario del Plan Premium tiene su propio bot_id registrado.
 * El SecurityManager valida que sender_id == dueño del bot.
 */
$app->post('/webhook/bot/{bot_id}', [WebhookController::class, 'handlePersonalBot'])
    ->add(FeatureDispatcher::class)
    ->add(QuotaController::class)
    ->add(SecurityManager::class);

/**
 * Redirección de short links: GET /s/{code}
 * PHP busca el código en short_links, incrementa clicks y redirige.
 * Ejemplo: https://tudominio.com/s/aB3xY7k → URL original
 */
$app->get('/s/{code}', [ShortLinkController::class, 'redirect']);

/**
 * Pagos: Webhook de Stripe y retorno
 */
$app->post('/payment/webhook', [PaymentController::class, 'handleWebhook']);
$app->get('/payment/success', [PaymentController::class, 'handleSuccess']);
$app->get('/checkout', [CheckoutController::class, 'renderCheckout']);

/**
 * Webhook de cierre desde n8n:
 * Registra tokens consumidos y guarda la respuesta de la IA en DB.
 */
$app->post('/internal/close', [WebhookController::class, 'handleClose']);

/**
 * Healthcheck para Docker y monitoreo externo.
 */
$app->get('/health', function ($request, $response) {
    $response->getBody()->write(json_encode(['status' => 'ok', 'ts' => time()]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

