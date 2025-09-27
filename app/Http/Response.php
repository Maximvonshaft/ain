<?php

namespace App\Http;

class Response
{
    public static function json(array $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json']);
    }

    public static function view(string $view, array $data = [], int $status = 200): self
    {
        $viewPath = base_path('resources/views/' . $view . '.php');
        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View {$view} not found");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        $content = ob_get_clean();

        return new self($content ?: '', $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function __construct(
        private string $content,
        private int $status,
        private array $headers = []
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->content;
    }
}

