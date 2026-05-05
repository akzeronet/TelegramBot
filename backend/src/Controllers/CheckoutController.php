<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * CheckoutController — Maneja la Mini App de Selección de Pagos
 */
class CheckoutController
{
    public function renderCheckout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $uid    = $params['uid']  ?? '';
        $plan   = $params['plan'] ?? 'premium';

        $enabledMethods = explode(',', $_ENV['PAYMENT_METHODS_ENABLED'] ?? 'stripe,stars');
        $price = ($plan === 'premium') ? '20.00' : '12.00';
        
        $html = $this->getTemplate($uid, $plan, $price, $enabledMethods);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function getTemplate(string $uid, string $plan, string $price, array $enabled): string
    {
        $methodsHtml = '';
        $allMethods = [
            'stripe' => [
                'name' => 'Stripe<br>(Tarjeta/Apple/Google)',
                'icon' => '💳',
                'url'  => "/pay/stripe?uid={$uid}&plan={$plan}"
            ],
            'paypal' => [
                'name' => 'PayPal',
                'icon' => '🅿️',
                'url'  => "/pay/paypal?uid={$uid}&plan={$plan}"
            ],
            'mercadopago' => [
                'name' => 'Mercado Pago<br>(LATAM)',
                'icon' => '🤝',
                'url'  => "/pay/mercadopago?uid={$uid}&plan={$plan}"
            ],
            'uepapay' => [
                'name' => 'UepaPay<br>(RD)',
                'icon' => '🇩🇴',
                'url'  => "/pay/uepapay?uid={$uid}&plan={$plan}"
            ],
        ];

        foreach ($enabled as $key) {
            $key = trim($key);
            if (isset($allMethods[$key])) {
                $m = $allMethods[$key];
                $methodsHtml .= "
                <a href='{$m['url']}' class='payment-card'>
                    <div style='font-size: 24px; margin-bottom: 10px;'>{$m['icon']}</div>
                    <span>{$m['name']}</span>
                </a>";
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Checkout Premium</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --accent: #3b82f6;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }
        .header { text-align: center; margin-bottom: 30px; margin-top: 20px; }
        .header h1 { font-size: 24px; margin: 0; font-weight: 700; }
        .header p { color: var(--text-muted); margin-top: 8px; font-size: 14px; }
        .plan-badge {
            background: rgba(59, 130, 246, 0.2);
            color: var(--accent);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 10px;
            display: inline-block;
        }
        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            width: 100%;
            max-width: 400px;
        }
        .payment-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
        }
        .payment-card:active {
            transform: scale(0.95);
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--accent);
        }
        .payment-card span {
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }
        .footer {
            margin-top: auto;
            padding-top: 40px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="plan-badge">Plan {$plan}</div>
        <h1>Escoge tu método</h1>
        <p>Total a pagar: <strong>\${$price} USD</strong></p>
    </div>

    <div class="payment-grid">
        {$methodsHtml}
    </div>

    <div class="footer">
        Pago seguro encriptado vía SSL.<br>
        AI Wrapper SaaS &copy; 2024
    </div>

    <script>
        const tele = window.Telegram.WebApp;
        tele.expand();
        tele.ready();
        tele.setHeaderColor('#0f172a');
        tele.setBackgroundColor('#0f172a');
    </script>
</body>
</html>
HTML;
    }
}
