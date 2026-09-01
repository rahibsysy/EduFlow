<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function pdf(string $content, string $filename, bool $inline = false): void
    {
        http_response_code(200);
        header('Content-Type: application/pdf');
        $disposition = $inline ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($filename) . '"');
        header('Content-Length: ' . (string)strlen($content));
        echo $content;
        exit;
    }
}
