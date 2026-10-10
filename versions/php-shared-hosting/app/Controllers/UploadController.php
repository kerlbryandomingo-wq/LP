<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Database;
use App\Services\AuthService;
use App\Services\UploadService;

/**
 * Protected-document download (spec section 14): a private file is
 * NEVER reachable by a static/guessable URL — every request here is
 * authorized first (role check today; a full "does this customer own
 * the related order" object-level check is the natural next step once
 * the customer portal is ported — see FEATURE-PARITY.md).
 */
final class UploadController
{
    public function download(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM uploaded_files WHERE id = :id');
        $stmt->execute(['id' => $params['id']]);
        $file = $stmt->fetch();
        if (!$file) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }

        $path = UploadService::privatePath($file['owner_type'], $file['stored_name']);
        if (!is_file($path)) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }

        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: inline; filename="' . basename($file['original_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }
}
