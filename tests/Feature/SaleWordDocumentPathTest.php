<?php

namespace Tests\Feature;

use App\Http\Controllers\SharwaliTarifa;
use Tests\TestCase;

class SaleWordDocumentPathTest extends TestCase
{
    public function test_word_files_are_unique_and_photos_stay_inside_public_storage(): void
    {
        $probe = new class extends SharwaliTarifa
        {
            public function wordPath(): string
            {
                return $this->wordDocumentPath();
            }

            public function storedFile(?string $path): ?string
            {
                return $this->publicStorageFile($path);
            }
        };

        $first = $probe->wordPath();
        $second = $probe->wordPath();

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith(storage_path('app'.DIRECTORY_SEPARATOR.'tmp'), $first);
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR, $first);

        $name = 'word-path-probe-'.bin2hex(random_bytes(4)).'.txt';
        $directory = storage_path('app/public/Customers_images');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $file = $directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($file, 'probe');

        try {
            $this->assertSame(realpath($file), $probe->storedFile('Customers_images/'.$name));
            $this->assertNull($probe->storedFile('../.env'));
            $this->assertNull($probe->storedFile('..\\..\\.env'));
            $this->assertNull($probe->storedFile('Unknown'));
            $this->assertNull($probe->storedFile(null));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
