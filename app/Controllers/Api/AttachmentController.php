<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\MemoService;
use Core\Request;
use Core\Response;
use RuntimeException;

class AttachmentController
{
    public function __construct(private MemoService $service)
    {
    }

    public function store(Request $request): void
    {
        $target = (string)$request->input('target', '');
        $targetIdRaw = $request->input('target_id');
        if (is_array($targetIdRaw)) {
            throw new RuntimeException('目标无效');
        }
        $targetId = $targetIdRaw !== null ? (int)$targetIdRaw : 0;
        if ($targetId <= 0) {
            throw new RuntimeException('目标无效');
        }

        $file = $request->files('file');
        if (!is_array($file)) {
            throw new RuntimeException('未上传文件');
        }

        $attachment = $this->service->uploadAttachment($file, $target, $targetId);

        Response::json([
            'ok' => 1,
            'attachment' => $attachment,
        ], 201);
    }

    public function destroy(int $id): void
    {
        $this->service->deleteAttachment($id);
        Response::json(['ok' => 1]);
    }

    public function download(int $id): void
    {
        $attachment = $this->service->getAttachment($id);
        if (!$attachment) {
            http_response_code(404);
            echo 'Not Found';
            return;
        }

        $path = $this->service->attachmentAbsolutePath($attachment);
        if (!is_file($path)) {
            http_response_code(404);
            echo 'File Missing';
            return;
        }

        $mime = $attachment['mime'] ?? 'application/octet-stream';
        $size = (int)($attachment['size'] ?? filesize($path));
        $name = (string)($attachment['orig_name'] ?? basename($path));
        $disposition = $this->service->shouldInlineAttachment($attachment) ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($name) . '"');

        readfile($path);
    }
}
