<?php

use App\Controllers\Api\AttachmentController;
use App\Controllers\Api\CategoryController;
use App\Controllers\Api\ItemController;
use App\Controllers\Api\StepController;
use Core\Request;

/** @var \Core\Router $router */
/** @var \App\Services\MemoService $memoService */

$categoryController = new CategoryController($memoService);
$itemController = new ItemController($memoService);
$stepController = new StepController($memoService);
$attachmentController = new AttachmentController($memoService);

$router->get('/api/categories', function (Request $request) use ($categoryController): void {
    $categoryController->index();
});

$router->post('/api/categories', function (Request $request) use ($categoryController): void {
    $categoryController->store($request);
});

$router->put('/api/categories/{id}', function (Request $request, int $id) use ($categoryController): void {
    $categoryController->update($request, $id);
});

$router->delete('/api/categories/{id}', function (Request $request, int $id) use ($categoryController): void {
    $categoryController->destroy($id);
});

$router->get('/api/items', function (Request $request) use ($itemController): void {
    $itemController->index($request);
});

$router->post('/api/items', function (Request $request) use ($itemController): void {
    $itemController->store($request);
});

$router->get('/api/items/{id}', function (Request $request, int $id) use ($itemController): void {
    $itemController->show($id, $request);
});

$router->put('/api/items/{id}', function (Request $request, int $id) use ($itemController): void {
    $itemController->update($request, $id);
});

$router->patch('/api/items/{id}/done', function (Request $request, int $id) use ($itemController): void {
    $itemController->toggleDone($request, $id);
});

$router->delete('/api/items/{id}', function (Request $request, int $id) use ($itemController): void {
    $itemController->destroy($id);
});

$router->post('/api/items/reorder', function (Request $request) use ($itemController): void {
    $itemController->reorder($request);
});

$router->post('/api/items/{itemId}/steps', function (Request $request, int $itemId) use ($stepController): void {
    $stepController->store($request, $itemId);
});

$router->post('/api/items/{itemId}/steps/reorder', function (Request $request, int $itemId) use ($stepController): void {
    $stepController->reorder($request, $itemId);
});

$router->patch('/api/steps/{id}', function (Request $request, int $id) use ($stepController): void {
    $stepController->update($request, $id);
});

$router->patch('/api/steps/{id}/done', function (Request $request, int $id) use ($stepController): void {
    $stepController->toggle($request, $id);
});

$router->delete('/api/steps/{id}', function (Request $request, int $id) use ($stepController): void {
    $stepController->destroy($id);
});

$router->post('/api/attachments', function (Request $request) use ($attachmentController): void {
    $attachmentController->store($request);
});

$router->delete('/api/attachments/{id}', function (Request $request, int $id) use ($attachmentController): void {
    $attachmentController->destroy($id);
});

$router->get('/api/attachments/{id}/download', function (Request $request, int $id) use ($attachmentController): void {
    $attachmentController->download($id);
});
