<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\TelegramService;
use App\Features\Tier1\ExportChatFeature;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * MenuController — Navegación Visual (Botones y Comandos)
 *
 * Maneja el flujo de usuario que no es IA:
 * - /start (Bienvenida)
 * - /subscribe (Planes de pago)
 * - /settings (Ajustes de idioma y personalidad)
 * - Callback Queries (Clicks en botones inline)
 */
class MenuController
{
    public function __construct(
        private readonly DatabaseService $db,
        private readonly TelegramService $telegram,
        private readonly ExportChatFeature $exportFeature,
    ) {}

    /**
     * Punto de entrada para comandos de texto (/start, /subscribe, etc.)
     */
    public function handleCommand(string $command, array $user, string $chatId, string $botToken, string $args = ''): ResponseInterface
    {
        switch ($command) {
            case '/start':
                return $this->showWelcome($user, $chatId, $botToken);
            case '/subscribe':
            case '/plan':
                return $this->showSubscriptionMenu($user, $chatId, $botToken);
            case '/settings':
            case '/config':
                return $this->showSettingsMenu($user, $chatId, $botToken);
            case '/persona':
                return $this->handleCustomPersonaCommand($user, $chatId, $botToken, $args);
            case '/addbot':
            case '/token':
                return $this->handleAddBotCommand($user, $chatId, $botToken, $args);
            case '/export':
                return $this->handleExport($user, $chatId, $botToken, $args);
            case '/delete_data':
                return $this->handleDeleteData($user, $chatId, $botToken);
            default:
                return $this->ok();
        }
    }

    /**
     * Punto de entrada para clicks en botones (callback_query)
     */
    public function handleCallback(string $data, array $user, string $chatId, string $botToken): ResponseInterface
    {
        $parts  = explode(':', $data);
        $action = $parts[0];
        $value  = $parts[1] ?? null;

        switch ($action) {
            case 'view_plans':
                return $this->showSubscriptionMenu($user, $chatId, $botToken);
            case 'settings':
                return $this->showSettingsMenu($user, $chatId, $botToken);
            case 'persona_menu':
                return $this->showPersonaMenu($user, $chatId, $botToken);
            case 'set_persona':
                return $this->updatePersona($user, $value, $chatId, $botToken);
            case 'pay_stars_std':
                return $this->sendStarInvoice($user, 'standard', 925, $chatId, $botToken);
            case 'pay_stars_pre':
                return $this->sendStarInvoice($user, 'premium', 1540, $chatId, $botToken);
            case 'set_lang_menu':
                return $this->showLanguageMenu($user, $chatId, $botToken);
            case 'set_lang':
                return $this->updateLanguage($user, $value, $chatId, $botToken);
            case 'export_trigger':
                return $this->handleExport($user, $chatId, $botToken, '');
            case 'delete_trigger':
                return $this->handleDeleteData($user, $chatId, $botToken);
            case 'main_menu':
                return $this->showWelcome($user, $chatId, $botToken);
            default:
                return $this->ok();
        }
    }

    // -------------------------------------------------------------------------
    // Vistas (Mensajes con Botones)
    // -------------------------------------------------------------------------

    private function showWelcome(array $user, string $chatId, string $botToken): ResponseInterface
    {
        $lang = $user['language'];
        $isInactive = ($user['subscription_status'] === 'inactive');

        if ($isInactive) {
            $text = match ($lang) {
                'en' => "🔒 *Account Inactive*\n\nYour history is preserved for 6 months. Please renew your plan to continue using the AI.",
                default => "🔒 *Cuenta Inactiva*\n\nTu historial se conservará por 6 meses. Por favor, renueva tu plan para seguir usando la IA.",
            };

            $buttons = [
                'inline_keyboard' => [
                    [['text' => '💎 ' . ($lang === 'en' ? 'Reactivate Plan' : 'Reactivar Plan'), 'callback_data' => 'view_plans']],
                    [
                        ['text' => '📥 ' . ($lang === 'en' ? 'Export History' : 'Descargar Historial'), 'callback_data' => 'export_trigger'],
                        ['text' => '🗑️ ' . ($lang === 'en' ? 'Delete History' : 'Borrar Historial'), 'callback_data' => 'delete_trigger']
                    ]
                ]
            ];
        } else {
            $text = match ($lang) {
                'en' => "👋 *Welcome to AI Wrapper!*\n\nI am your omnichannel AI assistant with long-term memory. How can I help you today?",
                'pt' => "👋 *Bem-vindo ao AI Wrapper!*\n\nSou seu assistente de IA omnicanal com memória de longo prazo. Como posso ajudar você hoje?",
                default => "👋 *¡Bienvenido a AI Wrapper!*\n\nSoy tu asistente de IA omnicanal con memoria de largo plazo. ¿En qué puedo ayudarte hoy?",
            };

            $buttons = [
                'inline_keyboard' => [
                    [
                        ['text' => '💎 ' . ($lang === 'en' ? 'Upgrade Plan' : 'Ver Planes'), 'callback_data' => 'view_plans'],
                        ['text' => '⚙️ ' . ($lang === 'en' ? 'Settings' : 'Ajustes'), 'callback_data' => 'settings']
                    ],
                    [
                        ['text' => '📖 ' . ($lang === 'en' ? 'Help' : 'Ayuda'), 'url' => 'https://tudominio.com/docs']
                    ]
                ]
            ];
        }

        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown', $buttons);
        return $this->ok();
    }

    private function showSubscriptionMenu(array $user, string $chatId, string $botToken): ResponseInterface
    {
        $lang    = $user['language'];
        $enabled = explode(',', $_ENV['PAYMENT_METHODS_ENABLED'] ?? 'stripe,stars');
        $enabled = array_map('trim', $enabled);

        $text = match ($lang) {
            'en' => "💎 *Choose your plan:*\n\n• *Standard* ($12/mo): 300 msgs/day.\n• *Premium* ($20/mo): 500 msgs/day, Personal Bot, Long Memory.",
            default => "💎 *Elige tu plan:*\n\n• *Estándar* ($12/mes): 300 msgs/día.\n• *Premium* ($20/mes): 500 msgs/día, Bot Personal, Memoria Larga.",
        };

        $rows = [];

        // Fila Estándar
        $rowStd = [];
        if (in_array('stars', $enabled)) {
            $rowStd[] = ['text' => '⭐ Standard (925 Stars)', 'callback_data' => 'pay_stars_std'];
        }
        $rowStd[] = ['text' => '💳 Card/PayPal', 'url' => $_ENV['APP_DOMAIN'] . '/checkout?uid=' . $user['uid'] . '&plan=standard'];
        $rows[] = $rowStd;

        // Fila Premium
        $rowPre = [];
        if (in_array('stars', $enabled)) {
            $rowPre[] = ['text' => '⭐ Premium (1540 Stars)', 'callback_data' => 'pay_stars_pre'];
        }
        $rowPre[] = ['text' => '💳 Card/PayPal', 'url' => $_ENV['APP_DOMAIN'] . '/checkout?uid=' . $user['uid'] . '&plan=premium'];
        $rows[] = $rowPre;

        // Fila Volver
        $rows[] = [['text' => '⬅️ ' . ($lang === 'en' ? 'Back' : 'Volver'), 'callback_data' => 'main_menu']];

        $buttons = ['inline_keyboard' => $rows];

        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown', $buttons);
        return $this->ok();
    }

    private function sendStarInvoice(array $user, string $plan, int $stars, string $chatId, string $botToken): ResponseInterface
    {
        $title = "Plan " . ucfirst($plan);
        $desc  = ($plan === 'premium') ? "Acceso Premium + Bot Personal + Memoria" : "Acceso Estándar";
        $prices = [['label' => 'Total', 'amount' => $stars]];
        
        $this->telegram->sendInvoice(
            $botToken,
            $chatId,
            $title,
            $desc,
            "pay_{$plan}_{$user['uid']}",
            "XTR",
            $prices
        );

        return $this->ok();
    }

    private function showSettingsMenu(array $user, string $chatId, string $botToken): ResponseInterface
    {
        $lang = $user['language'];
        $text = match ($lang) {
            'en' => "⚙️ *Settings*\n\nYour current personality: *" . ($user['persona_name'] ?? 'Default') . "*\nLanguage: *" . strtoupper($lang) . "*",
            default => "⚙️ *Ajustes*\n\nPersonalidad actual: *" . ($user['persona_name'] ?? 'Asistente') . "*\nIdioma: *" . strtoupper($lang) . "*",
        };

        $buttons = [
            'inline_keyboard' => [
                [
                    ['text' => '🎭 ' . ($lang === 'en' ? 'Personality' : 'Personalidad'), 'callback_data' => 'persona_menu'],
                    ['text' => '🌐 ' . ($lang === 'en' ? 'Language' : 'Idioma'), 'callback_data' => 'set_lang_menu']
                ],
                [['text' => '⬅️ ' . ($lang === 'en' ? 'Back' : 'Volver'), 'callback_data' => 'main_menu']]
            ]
        ];

        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown', $buttons);
        return $this->ok();
    }

    private function showLanguageMenu(array $user, string $chatId, string $botToken): ResponseInterface
    {
        $lang = $user['language'];
        $text = ($lang === 'en') ? "🌐 *Choose your language:*" : "🌐 *Elige tu idioma:*";

        $buttons = [
            'inline_keyboard' => [
                [
                    ['text' => '🇪🇸 Español', 'callback_data' => 'set_lang:es'],
                    ['text' => '🇺🇸 English', 'callback_data' => 'set_lang:en']
                ],
                [['text' => '⬅️ Volver', 'callback_data' => 'settings']]
            ]
        ];

        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown', $buttons);
        return $this->ok();
    }

    private function showPersonaMenu(array $user, string $chatId, string $botToken): ResponseInterface
    {
        $lang = $user['language'];
        $text = match ($lang) {
            'en' => "🎭 *Choose a Personality:*\n\nDefine how the AI should talk to you.",
            default => "🎭 *Elige una Personalidad:*\n\nDefine cómo quieres que la IA te responda.",
        };

        $buttons = [
            'inline_keyboard' => [
                [
                    ['text' => '🤖 Asistente', 'callback_data' => 'set_persona:asistente'],
                    ['text' => '💻 Programador', 'callback_data' => 'set_persona:dev']
                ],
                [
                    ['text' => '✍️ Escritor', 'callback_data' => 'set_persona:writer'],
                    ['text' => '🧘 Coach', 'callback_data' => 'set_persona:coach']
                ],
                ($user['subscription_type'] === 'premium') 
                    ? [['text' => '✨ Personalizado (Custom)', 'callback_data' => 'set_persona:custom']]
                    : [['text' => '💎 Desbloquear Custom', 'callback_data' => 'view_plans']],
                [['text' => '⬅️ Volver', 'callback_data' => 'settings']]
            ]
        ];

        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown', $buttons);
        return $this->ok();
    }

    private function updatePersona(array $user, string $type, string $chatId, string $botToken): ResponseInterface
    {
        $personas = [
            'asistente' => ['name' => 'Asistente', 'prompt' => 'Eres un asistente útil y profesional.'],
            'dev'       => ['name' => 'Programador', 'prompt' => 'Eres un experto programador senior. Respondes con código limpio y explicaciones técnicas precisas.'],
            'writer'    => ['name' => 'Escritor', 'prompt' => 'Eres un escritor creativo experto en storytelling y redacción persuasiva.'],
            'coach'     => ['name' => 'Coach', 'prompt' => 'Eres un coach de vida y productividad. Tu tono es motivador pero disciplinado.'],
        ];

        if ($type === 'custom') {
            $text = "✨ *Personalidad Custom*\n\nPara definir tu propio prompt, simplemente escribe lo que quieras que sea la IA. \n\n*Ejemplo:* \"Eres un pirata que habla sobre tecnología\".";
            $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');
            return $this->ok();
        }

        if (isset($personas[$type])) {
            $p = $personas[$type];
            $this->db->execute(
                "UPDATE users SET persona_name = $1, persona_prompt = $2 WHERE uid = $3",
                [$p['name'], $p['prompt'], $user['uid']]
            );

            $text = "✅ Personalidad actualizada a: *" . $p['name'] . "*";
            $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');
        }

        return $this->showSettingsMenu($user, $chatId, $botToken);
    }

    private function updateLanguage(array $user, ?string $newLang, string $chatId, string $botToken): ResponseInterface
    {
        if ($newLang) {
            $this->db->execute('UPDATE users SET language = $1 WHERE uid = $2', [$newLang, $user['uid']]);
            $user['language'] = $newLang;
            
            $text = match ($newLang) {
                'en' => "✅ Language updated to *English*.",
                default => "✅ Idioma actualizado a *Español*.",
            };
            $this->telegram->sendMessage($botToken, $chatId, $text);
        }

        return $this->showSettingsMenu($user, $chatId, $botToken);
    }

    private function handleCustomPersonaCommand(array $user, string $chatId, string $botToken, string $args): ResponseInterface
    {
        if ($user['subscription_type'] !== 'premium') {
            $text = "❌ Esta función es exclusiva para usuarios *Premium*.\n\nUsa /subscribe para subir de nivel.";
            $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');
            return $this->ok();
        }

        if (empty($args)) {
            $text = "✨ *Personalidad Custom*\n\nPara definir tu personalidad usa el comando seguido de tu prompt.\n\n*Ejemplo:* `/persona Eres un chef experto en comida caribeña.`";
            $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');
            return $this->ok();
        }

        $this->db->execute(
            "UPDATE users SET persona_name = 'Custom', persona_prompt = $1 WHERE uid = $2",
            [$args, $user['uid']]
        );

        $text = "✅ ¡Personalidad actualizada!\n\nAhora la IA actuará como: _" . $args . "_";
        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');

        return $this->ok();
    }

    private function handleAddBotCommand(array $user, string $chatId, string $botToken, string $args): ResponseInterface
    {
        if ($user['subscription_type'] !== 'premium') {
            $text = "❌ El uso de *Bots Personales* es una función exclusiva de **Premium**.\n\nUsa /subscribe para activarlo.";
            $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');
            return $this->ok();
        }

        $newToken = trim($args);
        if (empty($newToken)) {
            $text = "🤖 *Cómo conectar tu Bot Personal:*\n\n1. Ve a @BotFather y crea un nuevo bot.\n2. Copia el **API Token** que te darán.\n3. Escribe aquí: `/addbot TU_TOKEN`";
            $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');
            return $this->ok();
        }

        $webhookUrl = $_ENV['APP_DOMAIN'] . "/webhook/bot/" . $user['uid'];
        $success = $this->telegram->setWebhook($newToken, $webhookUrl);

        if (!$success) {
            $text = "❌ *Token Inválido.*\n\nPor favor, asegúrate de que el token sea correcto y que el bot no tenga otro webhook activo.";
            $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');
            return $this->ok();
        }

        $botId = $this->db->saveBotToken($user['uid'], $newToken, 'Personal Bot');
        $finalWebhookUrl = $_ENV['APP_DOMAIN'] . "/webhook/bot/" . $botId;
        $this->telegram->setWebhook($newToken, $finalWebhookUrl);

        $text = "✅ *¡Bot Personal Conectado!*\n\nA partir de ahora, puedes usar tu propio bot y yo responderé desde allí con tu personalidad y memoria.";
        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');

        return $this->ok();
    }

    private function handleExport(array $user, string $chatId, string $botToken, string $args): ResponseInterface
    {
        return $this->exportFeature->handle($user['uid'], $chatId, $botToken, $args, $user['language']);
    }

    private function handleDeleteData(array $user, string $chatId, string $botToken): ResponseInterface
    {
        $this->db->execute("DELETE FROM chat_messages WHERE uid = $1", [$user['uid']]);
        $this->db->execute("DELETE FROM user_memories WHERE uid = $1", [$user['uid']]);

        $text = "🗑️ *Datos eliminados*\n\nTu historial de chat y memorias han sido borrados por completo. Tu cuenta permanece inactiva hasta que renueves tu plan.";
        $this->telegram->sendMessage($botToken, $chatId, $text, 'Markdown');

        return $this->showWelcome($user, $chatId, $botToken);
    }

    private function ok(): Response
    {
        return new Response(200);
    }
}
