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

    /**
     * @param array $file one entry of $_FILES
     * @return array{0:string,1:string} [uploaded_files id, absolute stored path] — the caller deletes the path if its transaction fails
     */
    public static function storePrivate(array $file, string $ownerType, string $ownerId, ?string $uploadedById): array
    {
        if (is_array($file['error'] ?? null) || is_array($file['name'] ?? null)) {
            throw new RuntimeException('Upload one file at a time.');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new RuntimeException('File is too large (max 8MB).');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The file could not be uploaded. Please try again.');
        }
        if ((int) $file['size'] <= 0) {
            throw new RuntimeException('The uploaded file is empty.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('File is too large (max 8MB).');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('The file could not be uploaded. Please try again.');
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
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new RuntimeException('The upload folder is not writable. Ask your administrator to check storage/private-uploads permissions.');
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
            // Display name only (never used as a path): strip directories and control characters.
            'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '_', basename(str_replace('\\', '/', (string) $file['name']))) ?: 'file', 0, 200),
            'stored_name' => $storedName,
            'mime_type' => $mime,
            'size_bytes' => $file['size'],
            'uploaded_by_id' => $uploadedById,
        ]);

        return [$id, $destPath];
    }

    public static function privatePath(string $ownerType, string $storedName): string
    {
        return APP_ROOT . '/storage/private-uploads/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $ownerType) . '/' . basename($storedName);
    }
}
