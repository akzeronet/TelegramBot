<?php
declare(strict_types=1);

use App\Services\DatabaseService;
use App\Services\TelegramService;
use App\Services\N8nService;
use App\Middleware\SecurityManager;
use App\Middleware\QuotaController;
use App\Middleware\FeatureDispatcher;
use App\Controllers\WebhookController;
use App\Controllers\ShortLinkController;
use App\Controllers\MenuController;
use App\Controllers\PaymentController;
use App\Controllers\CheckoutController;
use App\Features\Tier1\ExportChatFeature;
use App\Cron\BillingCron;

/**
 * Contenedor de Inyección de Dependencias (PHP-DI)
 *
 * Define cómo construir cada clase del sistema.
 * Los servicios son singletons dentro del mismo request.
 */
return [

    // --- Servicios base ---
    DatabaseService::class => DI\create(DatabaseService::class),
    TelegramService::class => DI\create(TelegramService::class),
    N8nService::class      => DI\create(N8nService::class),

    // --- Middlewares ---
    SecurityManager::class => DI\autowire(SecurityManager::class),
    QuotaController::class => DI\autowire(QuotaController::class),
    FeatureDispatcher::class => DI\autowire(FeatureDispatcher::class),

    // --- Controladores ---
    WebhookController::class => DI\autowire(WebhookController::class),
    ShortLinkController::class => DI\autowire(ShortLinkController::class),
    MenuController::class => DI\autowire(MenuController::class),
    PaymentController::class => DI\autowire(PaymentController::class),
    CheckoutController::class => DI\autowire(CheckoutController::class),
];
