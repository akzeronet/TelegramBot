# Guía de Integración de Pagos — AI Wrapper SaaS

Este documento explica cómo añadir nuevas pasarelas de pago (Gateways) al sistema de forma modular y segura.

---

## 1. El Interruptor Maestro (.env)

Todos los métodos deben estar listados en la variable `PAYMENT_METHODS_ENABLED` del archivo `.env`. 
Si quieres añadir uno nuevo (ej. `azul_rd`), primero regístralo allí:

```bash
PAYMENT_METHODS_ENABLED=stripe,paypal,stars,uepapay,azul_rd
```

---

## 2. Añadir el Botón a la Mini App

Para que el nuevo método aparezca visualmente en la pantalla de selección, debes editar el archivo `backend/src/Controllers/CheckoutController.php`.

Busca el array `$allMethods` y añade tu nueva pasarela:

```php
'azul_rd' => [
    'name' => 'Azul (RD)',
    'icon' => '🇩🇴', // O una URL de imagen
    'url'  => "/pay/azul?uid={\$uid}&plan={\$plan}"
],
```

---

## 3. Crear el Endpoint de Pago

Debes crear una ruta que maneje la redirección hacia la pasarela externa. En `backend/public/index.php`, añade la ruta:

```php
\$app->get('/pay/azul', [AzulController::class, 'initPayment']);
```

Y en el controlador (`AzulController.php`), generas el link de pago siguiendo la documentación de la pasarela:

```php
public function initPayment(\$request, \$response) {
    // 1. Obtener UID y Plan
    // 2. Llamar a la API de Azul
    // 3. Redirigir al usuario al checkout de Azul
}
```

---

## 4. El Webhook de Confirmación (Crucial)

Cada pasarela te enviará un "Webhook" o "IPN" cuando el pago sea exitoso. 
**No reinventes la rueda:** para activar el plan, simplemente llama a la función `activateSubscription` del `PaymentController`.

```php
// Dentro de tu nuevo WebhookController para Azul:
\$this->payments->activateSubscription(\$uid, \$planType);
```

Esta función se encarga de:
1. Actualizar la tabla `users` (status active, expiry date +30 días).
2. Insertar el registro en la tabla `payments`.
3. Enviar el mensaje de "¡Suscripción Activada!" por Telegram automáticamente.

---

## 5. Telegram Stars (Caso Especial)

Las estrellas NO pasan por la Mini App. Se gestionan directamente en:
* `MenuController.php`: Envía la factura con `sendInvoice`.
* `WebhookController.php`: Valida con `pre_checkout_query` y confirma con `successful_payment`.

---

**Sugerencia Pro:** Si integras una pasarela que solo permite pagos únicos, la función `activateSubscription` seguirá funcionando igual, simplemente el usuario tendrá que renovar manualmente cada mes.
