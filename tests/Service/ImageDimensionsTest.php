<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\ImageDimensions;
use PHPUnit\Framework\TestCase;

/**
 * Share-card image sizes read from Omeka's local file store, and only from it:
 * a URL that does not name a file there is unknown, never fetched or guessed.
 */
final class ImageDimensionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/iwac-seo-dimensions-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/large', 0777, true);
        mkdir($this->root . '/asset', 0777, true);
        $this->png('large/scan.jpg', 1200, 800);
        $this->png('asset/logo du site.png', 1200, 630);
        file_put_contents($this->root . '/large/notes.txt', 'not an image');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->root . '/*') ?: [] as $dir) {
            rmdir($dir);
        }
        rmdir($this->root);
    }

    public function testReadsTheSizeOfADerivativeOrAnAsset(): void
    {
        $dimensions = new ImageDimensions($this->root);

        $this->assertSame([1200, 800], $dimensions->of('https://islam.zmo.de/files/large/scan.jpg'));
        $this->assertSame([1200, 630], $dimensions->of('https://islam.zmo.de/files/asset/logo%20du%20site.png'));
    }

    public function testAnythingThatIsNotALocalImageIsUnknown(): void
    {
        $dimensions = new ImageDimensions($this->root);

        $this->assertNull($dimensions->of('https://i.ytimg.com/vi/UENBMWutb-w/hqdefault.jpg'), 'not the file store');
        $this->assertNull($dimensions->of('https://islam.zmo.de/files/large/missing.jpg'), 'no such file');
        $this->assertNull($dimensions->of('https://islam.zmo.de/files/large/notes.txt'), 'not an image');
        $this->assertNull($dimensions->of('https://islam.zmo.de/files/thumbs/scan.jpg'), 'not a store directory');
        $this->assertNull($dimensions->of('https://islam.zmo.de/files/large/..%2F..%2Fsecret.png'), 'cannot climb out');
        $this->assertNull($dimensions->of('https://islam.zmo.de/files/large/.htaccess'));
        $this->assertNull((new ImageDimensions(null))->of('https://islam.zmo.de/files/large/scan.jpg'), 'no local store');
        $this->assertNull((new ImageDimensions($this->root . '/nowhere'))->of('https://islam.zmo.de/files/large/scan.jpg'));
    }

    /** Head metadata asks for the same image more than once a request; the file is read once. */
    public function testEachUrlIsMeasuredOncePerRequest(): void
    {
        $dimensions = new ImageDimensions($this->root);
        $url = 'https://islam.zmo.de/files/large/scan.jpg';

        $first = $dimensions->of($url);
        unlink($this->root . '/large/scan.jpg');
        $second = $dimensions->of($url);

        $this->assertSame([1200, 800], $first);
        $this->assertSame($first, $second, 'answered from memory, not from the deleted file');
    }

    /** A PNG signature and IHDR chunk: all getimagesize() reads. */
    private function png(string $path, int $width, int $height): void
    {
        file_put_contents(
            $this->root . '/' . $path,
            "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00" . pack('N', 0)
        );
    }
}
