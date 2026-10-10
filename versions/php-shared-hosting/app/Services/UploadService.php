<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Id;
use RuntimeException;

/**
 * Private file uploads (spec section 14): validated type/size, a
 * randomized stored filename (never the original — prevents path
 * guessing and collisions), stored in storage/private-uploads/ which is
 * OUTSIDE public/ entirely — there is no static URL that serves these
 * files; UploadController::download() is the only path in, and it
 * authorizes every request before streaming bytes.
 */
final class UploadService
{
    private const MAX_BYTES = 8 * 1024 * 1024; // 8MB
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @param array $file one entry of $_FILES */
    public static function storePrivate(array $file, string $ownerType, string $ownerId, ?string $uploadedById): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (error code ' . $file['error'] . ').');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('File is too large (max 8MB).');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException('Unsupported file type. Please upload a JPG, PNG, WEBP, or PDF.');
        }

        $ext = self::ALLOWED_MIME[$mime];
        $storedName = Id::generate('file') . '.' . $ext;
        $destDir = APP_ROOT . '/storage/private-uploads/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ownerType);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        $destPath = $destDir . '/' . $storedName;

        // move_uploaded_file (not rename/copy) guarantees the source was
        // actually an upload from this request — defense against a
        // path-traversal/spoofed-upload attempt.
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        $id = Id::generate('upl');
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO uploaded_files (id, owner_type, owner_id, original_name, stored_name, mime_type, size_bytes, uploaded_by_id)
             VALUES (:id, :owner_type, :owner_id, :original_name, :stored_name, :mime_type, :size_bytes, :uploaded_by_id)'
        )->execute([
            'id' => $id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'original_name' => basename($file['name']),
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'size_bytes' => $file['size'],
            'uploaded_by_id' => $uploadedById,
        ]);

        return $id;
    }

    public static function privatePath(string $ownerType, string $storedName): string
    {
        return APP_ROOT . '/storage/private-uploads/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ownerType) . '/' . basename($storedName);
    }
}
