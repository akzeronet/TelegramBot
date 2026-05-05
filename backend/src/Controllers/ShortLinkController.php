<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DatabaseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ShortLinkController
 *
 * Maneja la redirección de los enlaces cortos generados por LinkShortenerFeature.
 * Incrementa el contador de clics y redirige mediante un status 302.
 */
class ShortLinkController
{
    public function __construct(
        private readonly DatabaseService $db,
    ) {}

    public function redirect(
        ServerRequestInterface $request,
        ResponseInterface      $response,
        array                  $args,
    ): ResponseInterface {
        $code = $args['code'] ?? '';

        if (!$code) {
            return $response->withStatus(404);
        }

        // Buscar el enlace corto
        $link = $this->db->queryOne(
            'SELECT original_url FROM short_links WHERE short_code = $1',
            [$code]
        );

        if (!$link) {
            $response->getBody()->write('Link not found or expired.');
            return $response->withStatus(404);
        }

        // Incrementar clics de forma asíncrona (fire & forget SQL)
        $this->db->execute(
            'UPDATE short_links SET clicks = clicks + 1 WHERE short_code = $1',
            [$code]
        );

        return $response
            ->withHeader('Location', $link['original_url'])
            ->withStatus(302);
    }
}
