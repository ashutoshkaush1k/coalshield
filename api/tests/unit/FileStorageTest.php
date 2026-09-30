<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\components\ApiException;
use app\components\FileStorage;
use app\components\SupabaseBucket;
use app\models\File;
use app\tests\Support\Helper\Auth;
use Codeception\Test\Unit;
use Yii;
use yii\helpers\FileHelper;

class FileStorageTest extends Unit
{
    private string $dir;
    private FileStorage $storage;
    /** @var string[] */
    private array $temp = [];

    protected function _before(): void
    {
        $this->dir = Yii::getAlias('@runtime') . '/test-storage-' . bin2hex(random_bytes(4));
        $this->storage = new FileStorage(['dir' => $this->dir, 'maxBytes' => 2048]);
    }

    protected function _after(): void
    {
        FileHelper::removeDirectory($this->dir);
        array_map('unlink', array_filter($this->temp, 'is_file'));
    }

    public function testStoresAPdfWithChecksum(): void
    {
        $source = $this->tempFile("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n");
        $user = Auth::user(Auth::MINE_HEAD_MOONIDIH);
        $file = $this->storage->storeFile($source, 'contractor_compliance_doc', 42, $user->id);

        $this->assertNotNull($file->id);
        $this->assertSame('application/pdf', $file->mime);
        $this->assertSame(hash_file('sha256', $source), $file->sha256);
        $this->assertSame(filesize($source), (int) $file->size);
        $this->assertFileExists($this->storage->absolutePath($file->path));
        $this->assertTrue($this->storage->verify($file));
        $this->assertStringNotContainsString('web', $file->path);
    }

    public function testRejectsDisallowedType(): void
    {
        $source = $this->tempFile("<?php echo 'hi';\n");
        $this->assertFieldError(fn() => $this->storage->storeFile($source, 'x', 1, 1), 'FILE_TYPE_NOT_ALLOWED');
    }

    public function testRejectsOversizedFile(): void
    {
        $source = $this->tempFile("%PDF-1.4\n" . str_repeat('A', 4096));
        $this->assertFieldError(fn() => $this->storage->storeFile($source, 'x', 1, 1), 'FILE_TOO_LARGE');
    }

    public function testRejectsEmptyFile(): void
    {
        $source = $this->tempFile('');
        $this->assertFieldError(fn() => $this->storage->storeFile($source, 'x', 1, 1), 'FILE_EMPTY');
    }

    public function testDetectsChangedBytes(): void
    {
        $source = $this->tempFile("%PDF-1.4\nsome content\n%%EOF\n");
        $file = $this->storage->storeFile($source, 'x', 1, Auth::user(Auth::GOVERNMENT)->id);
        file_put_contents($this->storage->absolutePath($file->path), "%PDF-1.4\nchanged\n");
        $this->assertFalse($this->storage->verify($file));
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->absolutePath('../../.env');
    }

    /** Online (STORAGE_DRIVER=supabase): the bytes go to the bucket, never to the local disk. */
    public function testSupabaseDriverKeepsTheBytesInTheBucket(): void
    {
        $bucket = new class (['url' => 'https://example.supabase.co', 'key' => 'test-only']) extends SupabaseBucket {
            public array $objects = [];

            public function put(string $path, string $sourcePath, string $mime): void
            {
                $this->objects[$path] = [(string) file_get_contents($sourcePath), $mime];
            }

            public function get(string $path): ?string
            {
                return $this->objects[$path][0] ?? null;
            }
        };
        $storage = new FileStorage(['dir' => $this->dir, 'maxBytes' => 2048, 'driver' => 'supabase', 'bucket' => $bucket]);
        $bytes = "%PDF-1.4
online
%%EOF
";
        $file = $storage->storeFile($this->tempFile($bytes), 'x', 1, Auth::user(Auth::GOVERNMENT)->id);

        $this->assertTrue($storage->isRemote());
        $this->assertSame([$file->path => [$bytes, 'application/pdf']], $bucket->objects);
        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertTrue($storage->verify($file));
        $this->assertSame($bytes, $storage->read($file));
        // A seeded row (no bytes anywhere) gets no link, as on the laptop.
        $seeded = new File(['path' => 'uploads/contractor_docs/2/2026-07_wage_register.pdf', 'sha256' => str_repeat('a', 64)]);
        $this->assertFalse($storage->verify($seeded));
        $this->assertNull($storage->read($seeded));
    }

    public function testLocalIsTheDefaultDriver(): void
    {
        $this->assertFalse($this->storage->isRemote());
        $this->assertFalse(Yii::$app->fileStorage->isRemote());
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cs');
        file_put_contents($path, $content);
        return $this->temp[] = $path;
    }

    private function assertFieldError(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail("expected $code");
        } catch (ApiException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertSame(['file' => [$code]], $e->fields);
        }
    }
}
