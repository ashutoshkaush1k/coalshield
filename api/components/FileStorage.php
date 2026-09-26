<?php

declare(strict_types=1);

namespace app\components;

use app\models\File;
use Yii;
use yii\base\Component;
use yii\web\UploadedFile;

/**
 * Local-disk file storage outside the web root. Checks size and the real MIME type (finfo, not the
 * client's claim) against the whitelist in params, stores the bytes under <dir>/YYYY/MM/<sha256>.<ext>
 * and records a `file` row (audited like every model).
 */
class FileStorage extends Component
{
    private const EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public ?string $dir = null;
    public ?int $maxBytes = null;
    /** @var string[]|null */
    public ?array $mimeTypes = null;

    public function init(): void
    {
        parent::init();
        $params = Yii::$app->params;
        $this->dir = Yii::getAlias($this->dir ?? $params['fileStorage.dir']);
        $this->maxBytes ??= $params['fileStorage.maxBytes'];
        $this->mimeTypes ??= $params['fileStorage.mimeTypes'];
    }

    public function storeUpload(UploadedFile $upload, string $entity, int $entityId, int $userId): File
    {
        if ($upload->hasError) {
            throw ApiException::fields(['file' => ['UPLOAD_FAILED']]);
        }
        return $this->storeFile($upload->tempName, $entity, $entityId, $userId);
    }

    /** Store a local file (the source is copied, not moved). */
    public function storeFile(string $sourcePath, string $entity, int $entityId, int $userId): File
    {
        $size = @filesize($sourcePath);
        if ($size === false || $size === 0) {
            throw ApiException::fields(['file' => ['FILE_EMPTY']]);
        }
        if ($size > $this->maxBytes) {
            throw new ApiException(422, 'VALIDATION_FAILED', ['max_bytes' => $this->maxBytes], ['file' => ['FILE_TOO_LARGE']]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: 'application/octet-stream';
        if (!in_array($mime, $this->mimeTypes, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', ['mime' => $mime], ['file' => ['FILE_TYPE_NOT_ALLOWED']]);
        }

        $sha256 = hash_file('sha256', $sourcePath);
        $relative = gmdate('Y/m') . '/' . $sha256 . '.' . (self::EXTENSIONS[$mime] ?? 'bin');
        $target = $this->absolutePath($relative);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Cannot create storage directory');
        }
        if (!is_file($target) && !copy($sourcePath, $target)) {
            throw new \RuntimeException('Cannot write stored file');
        }

        $file = new File([
            'path' => $relative,
            'mime' => $mime,
            'size' => $size,
            'sha256' => $sha256,
            'uploaded_by' => $userId,
            'entity' => $entity,
            'entity_id' => $entityId,
        ]);
        if (!$file->save()) {
            throw ApiException::validation($file);
        }
        return $file;
    }

    public function absolutePath(string $relative): string
    {
        if (str_contains($relative, '..')) {
            throw new \InvalidArgumentException('Invalid stored path');
        }
        return rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** True when the stored bytes still match the recorded checksum. */
    public function verify(File $file): bool
    {
        $path = $this->absolutePath($file->path);
        return is_file($path) && hash_file('sha256', $path) === $file->sha256;
    }
}
