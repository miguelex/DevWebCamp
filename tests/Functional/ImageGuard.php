<?php

declare(strict_types=1);

namespace Intervention\Image;

use RuntimeException;

// Test-only substitute: host PHP does not provide GD/Imagick. Never loaded by production.
if (getenv('APP_ENV_MODE') !== 'isolated-test') {
    throw new RuntimeException('Synthetic image guard requires isolated-test mode.');
}

final class ImageManagerStatic
{
    private const INPUT_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
    private const OUTPUT_WEBP = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';
    private string $format = '';

    public static function make(string $path): self
    {
        if (!is_uploaded_file($path) || file_get_contents($path) !== base64_decode(self::INPUT_PNG, true)) {
            throw new RuntimeException('Unexpected synthetic image upload.');
        }
        return new self();
    }

    public function fit(int $width, int $height): self
    {
        if ($width !== 800 || $height !== 800) {
            throw new RuntimeException('Unexpected synthetic image dimensions.');
        }
        return $this;
    }

    public function encode(string $format, int $quality): self
    {
        if (!in_array($format, ['png', 'webp'], true) || $quality !== 80) {
            throw new RuntimeException('Unexpected synthetic image format.');
        }
        $this->format = $format;
        return $this;
    }

    public function save(string $path): void
    {
        $expected = getenv('PORT9_SPEAKER_DIRECTORY');
        if ($this->format === '' || !is_string($expected) || $expected === ''
            || realpath(dirname($path)) !== $expected
            || pathinfo($path, PATHINFO_EXTENSION) !== $this->format) {
            throw new RuntimeException('Image write escaped isolated speaker directory.');
        }
        $bytes = base64_decode($this->format === 'png' ? self::INPUT_PNG : self::OUTPUT_WEBP, true);
        if ($bytes === false || file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Cannot save synthetic image.');
        }
    }
}

header('X-Port9-Image-Guard: active');
