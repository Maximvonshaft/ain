<?php

namespace Core;

class View
{
    public function __construct(private string $basePath)
    {
    }

    public function render(string $template, array $data = []): string
    {
        $file = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $template . '.phtml';
        if (!is_file($file)) {
            throw new \RuntimeException("View {$template} not found");
        }
        extract($data, EXTR_OVERWRITE);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
