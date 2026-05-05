<?php
declare(strict_types=1);

namespace App\Cron;

use App\Services\DatabaseService;
use App\Services\TelegramService;

/**
 * BillingCron — Ejecutado diariamente por el sistema.
 * 
 * Tareas:
 * 1. Notificar 3 días antes del vencimiento.
 * 2. Marcar como Inactivo si el plan expiró.
 * 3. Notificar estado Inactivo.
 */
class BillingCron
{
    public function __construct(
        private readonly DatabaseService $db,
        private readonly TelegramService $telegram,
    ) {}

    public function run(): void
    {
        $botToken = $_ENV['TELEGRAM_BOT_TOKEN'];

        // --- 1. Usuarios que vencen en 3 días exactos ---
        $vencenPronto = $this->db->queryAll(
            "SELECT u.uid, i.platform_id 
             FROM users u 
             JOIN user_identities i ON u.uid = i.uid 
             WHERE u.expiry_date::date = (CURRENT_DATE + INTERVAL '3 days')::date
             AND u.subscription_status = 'active'"
        );

        foreach ($vencenPronto as $user) {
            $text = "⚠️ *Aviso de Vencimiento*\n\nTu suscripción vence en *3 días*. Renueva ahora para no perder el acceso a tus funciones Premium.";
            $this->telegram->sendMessage($botToken, $user['platform_id'], $text);
        }

        // --- 2. Usuarios que acaban de expirar hoy ---
        $expirados = $this->db->queryAll(
            "SELECT u.uid, i.platform_id 
             FROM users u 
             JOIN user_identities i ON u.uid = i.uid 
             WHERE u.expiry_date < NOW() 
             AND u.subscription_status = 'active'"
        );

        foreach ($expirados as $user) {
            $this->db->execute(
                "UPDATE users SET subscription_status = 'inactive' WHERE uid = $1",
                [$user['uid']]
            );

            $text = "❌ *Suscripción Expirada*\n\nTu cuenta ha pasado a estado *Inactivo*. \n\n🔒 El acceso a la IA y tus bots personales ha sido bloqueado. \n\nSolo puedes descargar tu historial o borrar tus datos desde el menú /start.";
            $this->telegram->sendMessage($botToken, $user['platform_id'], $text);
        }
    }
}
