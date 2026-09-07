<?php

declare(strict_types=1);

namespace SysAdmin;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): void
    {
        $view = APP_ROOT . '/views/' . ltrim($template, '/') . '.php';
        if (!is_file($view)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $view;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }

        require APP_ROOT . '/views/' . $layout . '.php';
    }
}
