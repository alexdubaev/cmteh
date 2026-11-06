<?php

declare(strict_types=1);

namespace Cmteh;

use Opencart\System\Engine\Registry;

final class Storefront
{
    public static function dispatch(Registry $registry): void
    {
        $request = $registry->get('request');
        $response = $registry->get('response');

        if (getenv('CMTEH_LOCAL_SMOKE') === '1'
            && $request->server['REQUEST_METHOD'] === 'GET'
            && $request->server['REQUEST_URI'] === '/_cmteh_platform') {
            Database::privateDir();
            Database::connectFromOpenCart()->query('SELECT 1')->fetchColumn();
            $response->addHeader('Content-Type: application/json; charset=utf-8');
            $response->addHeader('Cache-Control: no-store');
            $response->setOutput('{"opencart":true,"mysql":true,"private_storage":true}');

            return;
        }

        http_response_code(503);
        $response->addHeader('Content-Type: text/plain; charset=utf-8');
        $response->addHeader('Cache-Control: no-store');
        $response->setOutput('Витрина временно недоступна.');
    }
}
