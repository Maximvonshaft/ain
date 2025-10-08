<?php

use App\Controllers\Api\CategoryController;
use App\Controllers\Api\ItemController;
use App\Controllers\Api\StepController;
use App\Middlewares\CorsMiddleware;
use App\Repositories\CategoryRepository;
use App\Repositories\ItemRepository;
use App\Repositories\MindmapRepository;
use App\Repositories\StepRepository;
use App\Services\ItemService;
use App\Services\StepService;
use Core\Request;
use Core\Response;
use Core\Router;

/** @var Router $router */
/** @var Request $request */

$categoryRepository = new CategoryRepository();
$itemRepository = new ItemRepository();
$stepRepository = new StepRepository();
$mindmapRepository = new MindmapRepository();

$itemService = new ItemService($itemRepository, $stepRepository, $categoryRepository);
$stepService = new StepService($stepRepository, $itemRepository);

$categoryController = new CategoryController($categoryRepository, $itemRepository, $mindmapRepository);
$itemController = new ItemController($itemService);
$stepController = new StepController($stepService);

$cors = new CorsMiddleware();
$path = $request->path();
if ($path === '/' || str_starts_with($path, '/api')) {
    $cors->handle($request);
}

$router->get('/', function () {
    Response::json([
        'ok' => 1,
        'message' => 'Memo API',
        'docs' => '请访问前端 SPA 获取完整体验。',
    ]);
});

$router->options('/api/categories', fn() => null);
$router->options('/api/categories/{id}', fn() => null);
$router->get('/api/categories', function (Request $request) use ($categoryController) {
    $categoryController->index($request);
});
$router->post('/api/categories', function (Request $request) use ($categoryController) {
    $categoryController->store($request);
});
$router->put('/api/categories/{id}', function (Request $request, int $id) use ($categoryController) {
    $categoryController->update($request, $id);
});
$router->delete('/api/categories/{id}', function (Request $request, int $id) use ($categoryController) {
    $categoryController->destroy($request, $id);
});

$router->options('/api/items', fn() => null);
$router->options('/api/items/{id}', fn() => null);
$router->options('/api/items/{id}/done', fn() => null);
$router->options('/api/items/{id}/steps', fn() => null);
$router->options('/api/steps/{id}', fn() => null);

$router->get('/api/items', function (Request $request) use ($itemController) {
    $itemController->index($request);
});
$router->post('/api/items', function (Request $request) use ($itemController) {
    $itemController->store($request);
});
$router->get('/api/items/{id}', function (Request $request, int $id) use ($itemController) {
    $itemController->show($request, $id);
});
$router->put('/api/items/{id}', function (Request $request, int $id) use ($itemController) {
    $itemController->update($request, $id);
});
$router->patch('/api/items/{id}/done', function (Request $request, int $id) use ($itemController) {
    $itemController->toggle($request, $id);
});
$router->delete('/api/items/{id}', function (Request $request, int $id) use ($itemController) {
    $itemController->destroy($request, $id);
});

$router->post('/api/items/{id}/steps', function (Request $request, int $id) use ($stepController) {
    $stepController->store($request, $id);
});
$router->put('/api/steps/{id}', function (Request $request, int $id) use ($stepController) {
    $stepController->update($request, $id);
});
$router->delete('/api/steps/{id}', function (Request $request, int $id) use ($stepController) {
    $stepController->destroy($request, $id);
});
