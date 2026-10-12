<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Services\AuthService;
use App\Services\UploadService;

/**
 * Private document download. Files live in storage/private-uploads/ (never
 * web-served); every request is authorized for the kind of record the file
 * belongs to before any byte is sent.
 */
final class UploadController
{
    /** owner_type => ability needed to read files attached to that kind of record */
    private const READ_ABILITY = [
        'payment' => 'payments.view',
    ];

    public function download(array $params): void
    {
        AuthService::requireLogin();
        $file = View::findOr404('SELECT * FROM uploaded_files WHERE id = :id', ['id' => $params['id']], 'File');
        $ability = self::READ_ABILITY[$file['owner_type']] ?? null;
        if ($ability === null || !AuthService::can($ability)) {
            // Same response as a missing file: don't confirm the file exists.
            View::notFound('File');
        }
        $path = UploadService::privatePath($file['owner_type'], $file['stored_name']);
        if (!is_file($path)) {
            View::notFound('File');
        }

        $inline = in_array($file['mime_type'], ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']) ?: 'download';
        header('Content-Type: ' . ($inline ? $file['mime_type'] : 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }
}
