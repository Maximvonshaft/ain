<?php

namespace App\Controllers;

use App\Middlewares\CsrfMiddleware;
use Core\Config;
use Core\Request;
use RuntimeException;

class LegacyMemoController
{
    public function __construct(
        private Config $config,
        private CsrfMiddleware $csrf
    ) {
    }

    public function handle(Request $request): void
    {
        $csrfToken = $this->csrf->token($request);

        if ($request->isPost()) {
            try {
                $this->csrf->verify($request);
            } catch (RuntimeException $exception) {
                http_response_code(419);
                if ($request->ajax()) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
                } else {
                    echo 'CSRF validation failed';
                }
                return;
            }
        }

        $dbPath = $this->config->get('app.database.path');
        $uploadPath = $this->config->get('app.uploads.path');
        $maxUploadBytes = $this->config->get('app.uploads.max_bytes');
        $allowedMimes = $this->config->get('app.uploads.allowed_mimes', []);

        if (is_string($dbPath) && !defined('DB_FILE')) {
            define('DB_FILE', $dbPath);
        }
        if (is_string($uploadPath) && !defined('UPLOAD_DIR')) {
            define('UPLOAD_DIR', $uploadPath);
        }
        if (is_int($maxUploadBytes) && !defined('MAX_UPLOAD_BYTES')) {
            define('MAX_UPLOAD_BYTES', $maxUploadBytes);
        }
        if (is_array($allowedMimes) && !defined('ALLOWED_UPLOAD_MIME_MAP')) {
            define('ALLOWED_UPLOAD_MIME_MAP', $allowedMimes);
        }

        $GLOBALS['__memo_context'] = [
            'csrf_token' => $csrfToken,
        ];

        require __DIR__ . '/../../memo.php';
    }
}
