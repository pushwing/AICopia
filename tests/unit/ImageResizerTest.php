<?php

namespace Tests\Unit;

use App\Libraries\ImageUploader;
use App\Libraries\MediaUploader;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ImageUploader / MediaUploader 리사이즈 로직 단위 테스트
 * resizeIfNeeded() 는 private — ReflectionMethod 로 직접 호출
 */
final class ImageResizerTest extends CIUnitTestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) unlink($path);
        }
        parent::tearDown();
    }

    // ── 헬퍼 ──────────────────────────────────────────────────────────────────

    private function makeTempImage(int $width, int $height, string $ext = 'jpg'): string
    {
        $img  = imagecreatetruecolor($width, $height);
        $path = sys_get_temp_dir() . '/resize_test_' . uniqid() . '.' . $ext;

        match ($ext) {
            'jpg', 'jpeg' => imagejpeg($img, $path),
            'png'         => imagepng($img, $path),
            default       => imagejpeg($img, $path),
        };

        imagedestroy($img);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function makeTempGif(): string
    {
        // 최소한의 GIF89a 바이너리 (1×1 투명 GIF)
        $gif  = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $path = sys_get_temp_dir() . '/resize_test_' . uniqid() . '.gif';
        file_put_contents($path, $gif);
        $this->tempFiles[] = $path;
        return $path;
    }

    /** ImageUploader::resizeIfNeeded 호출 */
    private function callImageUploaderResize(string $path, string $ext): void
    {
        $obj    = new ImageUploader('test');
        $method = new \ReflectionMethod($obj, 'resizeIfNeeded');
        $method->invoke($obj, $path, $ext);
    }

    /** MediaUploader::resizeIfNeeded 호출 */
    private function callMediaUploaderResize(string $path, string $ext): void
    {
        $obj    = new MediaUploader();
        $method = new \ReflectionMethod($obj, 'resizeIfNeeded');
        $method->invoke($obj, $path, $ext);
    }

    // ── ImageUploader ─────────────────────────────────────────────────────────

    public function testImageUploader_landscapeOver1200_resizesToWidth1200(): void
    {
        $path = $this->makeTempImage(2400, 1200, 'jpg');

        $this->callImageUploaderResize($path, 'jpg');

        [$w, $h] = getimagesize($path);
        $this->assertSame(1200, $w);
        $this->assertSame(600, $h);
    }

    public function testImageUploader_portraitOver1200_resizesToHeight1200(): void
    {
        $path = $this->makeTempImage(800, 1600, 'png');

        $this->callImageUploaderResize($path, 'png');

        [$w, $h] = getimagesize($path);
        $this->assertSame(600, $w);
        $this->assertSame(1200, $h);
    }

    public function testImageUploader_squareOver1200_resizesToMax1200(): void
    {
        $path = $this->makeTempImage(2000, 2000, 'jpg');

        $this->callImageUploaderResize($path, 'jpg');

        [$w, $h] = getimagesize($path);
        $this->assertSame(1200, $w);
        $this->assertSame(1200, $h);
    }

    public function testImageUploader_imageWithin1200_notResized(): void
    {
        $path = $this->makeTempImage(800, 600, 'jpg');
        $sizeBefore = filesize($path);

        $this->callImageUploaderResize($path, 'jpg');

        [$w, $h] = getimagesize($path);
        $this->assertSame(800, $w);
        $this->assertSame(600, $h);
    }

    public function testImageUploader_exactly1200_notResized(): void
    {
        $path = $this->makeTempImage(1200, 900, 'jpg');

        $this->callImageUploaderResize($path, 'jpg');

        [$w, $h] = getimagesize($path);
        $this->assertSame(1200, $w);
        $this->assertSame(900, $h);
    }

    public function testImageUploader_gif_skipped(): void
    {
        $path       = $this->makeTempGif();
        $sizeBefore = filesize($path);

        $this->callImageUploaderResize($path, 'gif');

        // GIF는 리사이즈 없으므로 파일 크기 동일
        $this->assertSame($sizeBefore, filesize($path));
    }

    // ── MediaUploader ─────────────────────────────────────────────────────────

    public function testMediaUploader_landscapeOver1200_resizesToWidth1200(): void
    {
        $path = $this->makeTempImage(3000, 1500, 'jpg');

        $this->callMediaUploaderResize($path, 'jpg');

        [$w, $h] = getimagesize($path);
        $this->assertSame(1200, $w);
        $this->assertSame(600, $h);
    }

    public function testMediaUploader_pngOver1200_resized(): void
    {
        $path = $this->makeTempImage(1500, 2000, 'png');

        $this->callMediaUploaderResize($path, 'png');

        [$w, $h] = getimagesize($path);
        $this->assertSame(900, $w);
        $this->assertSame(1200, $h);
    }

    public function testMediaUploader_imageWithin1200_notResized(): void
    {
        $path = $this->makeTempImage(1000, 800, 'jpg');

        $this->callMediaUploaderResize($path, 'jpg');

        [$w, $h] = getimagesize($path);
        $this->assertSame(1000, $w);
        $this->assertSame(800, $h);
    }

    public function testMediaUploader_gif_skipped(): void
    {
        $path       = $this->makeTempGif();
        $sizeBefore = filesize($path);

        $this->callMediaUploaderResize($path, 'gif');

        $this->assertSame($sizeBefore, filesize($path));
    }

    public function testMediaUploader_svg_skipped(): void
    {
        // SVG 파일은 getimagesize 가 false 를 반환하므로 리사이즈 없음
        $path = sys_get_temp_dir() . '/resize_test_' . uniqid() . '.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="2000" height="2000"></svg>');
        $this->tempFiles[] = $path;

        $contentBefore = file_get_contents($path);

        $this->callMediaUploaderResize($path, 'svg');

        $this->assertSame($contentBefore, file_get_contents($path));
    }
}
