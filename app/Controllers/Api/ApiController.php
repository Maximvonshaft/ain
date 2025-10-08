<?php

namespace App\Controllers\Api;

use App\Support\NotFoundException;
use App\Support\ValidationException;
use Core\Response;
use Throwable;

abstract class ApiController
{
    protected function respond(callable $callback, int $status = 200): void
    {
        try {
            $result = $callback();
            if ($result === null) {
                $result = [];
            }
            if (!is_array($result)) {
                $result = ['data' => $result];
            }
            if (!array_key_exists('ok', $result)) {
                $result = ['ok' => 1] + $result;
            }
            Response::json($result, $status);
        } catch (ValidationException $e) {
            Response::json([
                'ok' => 0,
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (NotFoundException $e) {
            Response::json([
                'ok' => 0,
                'error' => $e->getMessage(),
            ], 404);
        } catch (Throwable $e) {
            Response::json([
                'ok' => 0,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
