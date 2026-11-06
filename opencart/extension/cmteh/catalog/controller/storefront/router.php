<?php

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Cmteh\Storefront;

use Cmteh\Storefront;
use Opencart\System\Engine\Controller;

final class Router extends Controller
{
    public function route(string &$route, array &$args): void
    {
        if ($route === 'common/home') {
            $route = 'extension/cmteh/storefront/router';
        }
    }

    public function index(): void
    {
        $system = DIR_EXTENSION . 'cmteh/system/';
        require_once $system . 'Database.php';
        require_once $system . 'Storefront.php';

        Storefront::dispatch($this->registry);
    }
}
