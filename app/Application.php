<?php

namespace App;

use App\Http\Request;
use App\Routing\Router;
use App\Support\Config;

class Application
{
    private Router $router;

    public function __construct()
    {
        require_once __DIR__ . '/Support/helpers.php';
        Config::getInstance();
        date_default_timezone_set(config('app.timezone', 'UTC'));
        $this->router = new Router();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request)
    {
        return $this->router->dispatch($request);
    }
}

