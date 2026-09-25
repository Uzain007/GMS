<?php

namespace Tests\Unit;

use App\Services\ReceiptFileProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptFileProcessorTest extends TestCase
{
    #[Test]
    public function it_compresses_an_image_to_the_configured_target_without_crossing_the_readability_floor(): void
    {
        $upload = $this->noisyJpeg(1200, 900);
        $processor = new ReceiptFileProcessor(targetBytes: 100_000, maximumLongEdge: 1000, minimumReadableLongEdge: 600);
        $prepared = $processor->prepare($upload);

        try {
            $dimensions = getimagesize($prepared['path']);
            $this->assertTrue($prepared['temporary']);
            $this->assertLessThanOrEqual(100_000, $prepared['size_bytes']);
            $this->assertLessThan($upload->getSize(), $prepared['size_bytes']);
            $this->assertGreaterThanOrEqual(600, max($dimensions[0], $dimensions[1]));
            $this->assertSame(500 * 1024, ReceiptFileProcessor::TARGET_BYTES);
        } finally {
            $processor->cleanup($prepared);
            @unlink($upload->getRealPath());
        }
    }

    #[Test]
    public function it_keeps_the_smallest_readable_image_when_the_target_is_impossible(): void
    {
        $upload = $this->noisyJpeg(800, 600);
        $processor = new ReceiptFileProcessor(targetBytes: 1, maximumLongEdge: 800, minimumReadableLongEdge: 600);
        $prepared = $processor->prepare($upload);

        try {
            $dimensions = getimagesize($prepared['path']);
            $this->assertGreaterThan(1, $prepared['size_bytes']);
            $this->assertGreaterThanOrEqual(600, max($dimensions[0], $dimensions[1]));
        } finally {
            $processor->cleanup($prepared);
            @unlink($upload->getRealPath());
        }
    }

    #[Test]
    public function it_does_not_transform_pdf_receipts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt-pdf-');
        file_put_contents($path, "%PDF-1.4\n".str_repeat('0', 700 * 1024));
        $upload = new UploadedFile($path, 'receipt.pdf', 'application/pdf', null, true);
        $processor = new ReceiptFileProcessor;
        $prepared = $processor->prepare($upload);

        $this->assertFalse($prepared['temporary']);
        $this->assertSame($upload->getRealPath(), $prepared['path']);
        $this->assertSame('application/pdf', $prepared['mime_type']);
        $this->assertSame($upload->getSize(), $prepared['size_bytes']);
        $processor->cleanup($prepared);
        $this->assertFileExists($upload->getRealPath());
        @unlink($upload->getRealPath());
    }

    #[Test]
    public function it_rejects_unusually_large_images_with_a_simple_message(): void
    {
        $upload = $this->pngHeaderUpload(5000, 3000);

        try {
            (new ReceiptFileProcessor)->prepare($upload);
            $this->fail('The oversized image should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['This image is unusually large. Please upload a screenshot or a smaller copy.'],
                $exception->errors()['receipt'],
            );
        } finally {
            @unlink($upload->getRealPath());
        }
    }

    #[Test]
    public function it_accepts_an_ordinary_phone_screenshot(): void
    {
        $upload = $this->screenshotPng(1440, 3000);
        $processor = new ReceiptFileProcessor;
        $prepared = $processor->prepare($upload);

        try {
            $this->assertSame('image/png', $prepared['mime_type']);
            $this->assertLessThanOrEqual(ReceiptFileProcessor::TARGET_BYTES, $prepared['size_bytes']);
            $this->assertNotFalse(getimagesize($prepared['path']));
        } finally {
            $processor->cleanup($prepared);
            @unlink($upload->getRealPath());
        }
    }

    #[Test]
    public function it_removes_all_temporary_candidates_after_a_processing_failure(): void
    {
        $before = $this->temporaryCandidateFiles();
        $upload = $this->noisyJpeg(800, 600);
        $processor = new class(targetBytes: 1, maximumLongEdge: 800, minimumReadableLongEdge: 600) extends ReceiptFileProcessor
        {
            private int $temporaryMetadataCalls = 0;

            protected function metadata(string $path, string $extension, string $mimeType, bool $temporary): array
            {
                if ($temporary && ++$this->temporaryMetadataCalls === 2) {
                    throw new \RuntimeException('Forced processing failure.');
                }

                return parent::metadata($path, $extension, $mimeType, $temporary);
            }
        };
        $failed = false;

        try {
            $processor->prepare($upload);
        } catch (\Throwable) {
            $failed = true;
        } finally {
            @unlink($upload->getRealPath());
        }

        $this->assertTrue($failed, 'The forced image-processing failure did not occur.');
        $this->assertSame($before, $this->temporaryCandidateFiles());
    }

    private function noisyJpeg(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 8) {
            for ($x = 0; $x < $width; $x += 8) {
                $colour = imagecolorallocate($image, ($x * 13 + $y) % 256, ($y * 17 + $x) % 256, ($x + $y * 7) % 256);
                imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, $colour);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'receipt-source-');
        imagejpeg($image, $path, 100);
        imagedestroy($image);

        return new UploadedFile($path, 'receipt.jpg', 'image/jpeg', null, true);
    }

    private function screenshotPng(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 248, 250, 252);
        $ink = imagecolorallocate($image, 30, 41, 59);
        $accent = imagecolorallocate($image, 37, 99, 235);
        imagefill($image, 0, 0, $background);
        for ($y = 120; $y < $height - 120; $y += 180) {
            imagefilledrectangle($image, 90, $y, $width - 90, $y + 18, $ink);
            imagefilledrectangle($image, 90, $y + 36, (int) ($width * 0.65), $y + 48, $accent);
        }
        $path = tempnam(sys_get_temp_dir(), 'receipt-screenshot-');
        imagepng($image, $path, 6);
        imagedestroy($image);

        return new UploadedFile($path, 'screenshot.png', 'image/png', null, true);
    }

    private function pngHeaderUpload(int $width, int $height): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt-oversized-');
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $chunk = 'IHDR'.$ihdr;
        file_put_contents(
            $path,
            "\x89PNG\r\n\x1a\n".pack('N', strlen($ihdr)).$chunk.pack('N', crc32($chunk)),
        );

        return new UploadedFile($path, 'oversized.png', 'image/png', null, true);
    }

    /** @return list<string> */
    private function temporaryCandidateFiles(): array
    {
        $files = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ironcore-receipt-*') ?: [];
        sort($files);

        return array_values($files);
    }
}
