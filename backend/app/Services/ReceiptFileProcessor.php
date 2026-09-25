<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReceiptFileProcessor
{
    public const TARGET_BYTES = 500 * 1024;

    private const MAX_PIXELS = 13_000_000;

    private const OVERSIZED_MESSAGE = 'This image is unusually large. Please upload a screenshot or a smaller copy.';

    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly int $targetBytes = self::TARGET_BYTES,
        private readonly int $maximumLongEdge = 2400,
        private readonly int $minimumReadableLongEdge = 1600,
        private readonly int $minimumQuality = 70,
    ) {}

    /** @return array{path:string,extension:string,mime_type:string,size_bytes:int,content_sha256:string,temporary:bool} */
    public function prepare(UploadedFile $receipt): array
    {
        $sourcePath = $receipt->getRealPath();
        $mimeType = (string) $receipt->getMimeType();
        if ($mimeType === 'application/pdf') {
            return $this->metadata($sourcePath, 'pdf', $mimeType, false);
        }
        if (! isset(self::IMAGE_EXTENSIONS[$mimeType])) {
            throw ValidationException::withMessages(['receipt' => ['The receipt image format could not be processed safely.']]);
        }
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Receipt image processing requires the GD extension.');
        }

        $dimensions = @getimagesize($sourcePath);
        if (! is_array($dimensions) || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw ValidationException::withMessages(['receipt' => ['The receipt image could not be decoded.']]);
        }
        if (($dimensions[0] * $dimensions[1]) > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['receipt' => [self::OVERSIZED_MESSAGE]]);
        }
        $source = $this->decodeImage($sourcePath, $mimeType);
        if ($source === false) {
            throw ValidationException::withMessages(['receipt' => ['The receipt image could not be decoded.']]);
        }

        try {
            // Rotate only the bounded candidate, not the full-resolution source,
            // so normal phone photos do not require two large GD buffers.
            return $this->compress($source, $mimeType, $this->jpegRotationAngle($sourcePath, $mimeType));
        } finally {
            imagedestroy($source);
        }
    }

    /** @param array{path:string,temporary:bool} $prepared */
    public function cleanup(array $prepared): void
    {
        if ($prepared['temporary'] && is_file($prepared['path'])) {
            @unlink($prepared['path']);
        }
    }

    /**
     * Keep a readable lower bound. If the target cannot be reached at that
     * bound, retain the smallest readable candidate instead of degrading it.
     *
     * @return array{path:string,extension:string,mime_type:string,size_bytes:int,content_sha256:string,temporary:bool}
     */
    private function compress(\GdImage $source, string $mimeType, int $rotationAngle): array
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $sourceLongEdge = max($sourceWidth, $sourceHeight);
        $maximum = min($sourceLongEdge, $this->maximumLongEdge);
        $minimum = min($sourceLongEdge, $this->minimumReadableLongEdge);
        $edges = array_values(array_unique(array_filter([
            $maximum,
            min($maximum, 2200),
            min($maximum, 2000),
            min($maximum, 1800),
            $minimum,
        ], fn (int $edge): bool => $edge >= $minimum)));
        $qualities = $mimeType === 'image/png'
            ? [6, 8, 9]
            : array_values(array_unique([82, 76, $this->minimumQuality]));
        $best = null;
        $selectedPath = null;

        try {
            foreach ($edges as $edge) {
                $scale = min(1, $edge / $sourceLongEdge);
                $width = max(1, (int) round($sourceWidth * $scale));
                $height = max(1, (int) round($sourceHeight * $scale));
                $image = imagecreatetruecolor($width, $height);
                if ($image === false) {
                    throw ValidationException::withMessages(['receipt' => [self::OVERSIZED_MESSAGE]]);
                }

                try {
                    $this->prepareCanvas($image, $mimeType);
                    imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
                    $image = $this->rotateCandidate($image, $rotationAngle);

                    foreach ($qualities as $quality) {
                        $candidate = $this->writeCandidate($image, $mimeType, $quality);
                        if ($candidate['size_bytes'] <= $this->targetBytes) {
                            if ($best !== null) {
                                @unlink($best['path']);
                                $best = null;
                            }
                            $selectedPath = $candidate['path'];

                            return $candidate;
                        }
                        if ($best === null || $candidate['size_bytes'] < $best['size_bytes']) {
                            if ($best !== null) {
                                @unlink($best['path']);
                            }
                            $best = $candidate;
                        } else {
                            @unlink($candidate['path']);
                        }
                    }
                } finally {
                    imagedestroy($image);
                }
            }
            if ($best === null) {
                throw new RuntimeException('The receipt image could not be processed.');
            }
            $selectedPath = $best['path'];

            return $best;
        } finally {
            if ($best !== null && $best['path'] !== $selectedPath && is_file($best['path'])) {
                @unlink($best['path']);
            }
        }
    }

    private function prepareCanvas(\GdImage $image, string $mimeType): void
    {
        if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, $transparent);
        } else {
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $white);
        }
    }

    /** @return array{path:string,extension:string,mime_type:string,size_bytes:int,content_sha256:string,temporary:bool} */
    private function writeCandidate(\GdImage $image, string $mimeType, int $quality): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ironcore-receipt-');
        if ($path === false) {
            throw new RuntimeException('A temporary receipt image could not be created.');
        }
        $keep = false;

        try {
            $written = match ($mimeType) {
                'image/jpeg' => imagejpeg($image, $path, $quality),
                'image/png' => imagepng($image, $path, $quality),
                'image/webp' => imagewebp($image, $path, $quality),
                default => false,
            };
            if (! $written) {
                throw new RuntimeException('The receipt image could not be processed.');
            }
            $metadata = $this->metadata($path, self::IMAGE_EXTENSIONS[$mimeType], $mimeType, true);
            $keep = true;

            return $metadata;
        } finally {
            if (! $keep && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function decodeImage(string $path, string $mimeType): \GdImage|false
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
    }

    private function jpegRotationAngle(string $path, string $mimeType): int
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 0;
        }
        $exif = @exif_read_data($path);

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    private function rotateCandidate(\GdImage $image, int $angle): \GdImage
    {
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            throw ValidationException::withMessages(['receipt' => [self::OVERSIZED_MESSAGE]]);
        }
        imagedestroy($image);

        return $rotated;
    }

    /** @return array{path:string,extension:string,mime_type:string,size_bytes:int,content_sha256:string,temporary:bool} */
    protected function metadata(string $path, string $extension, string $mimeType, bool $temporary): array
    {
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size === false || $hash === false) {
            throw new RuntimeException('Receipt file metadata could not be calculated.');
        }

        return [
            'path' => $path,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'content_sha256' => $hash,
            'temporary' => $temporary,
        ];
    }
}
