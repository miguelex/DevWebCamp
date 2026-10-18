<?php

declare(strict_types=1);

namespace Intervention\Image;

final class ImageManagerStatic
{
    public static int $makeCalls = 0;
    public static array $saved = [];

    public static function make(string $path): ImageDouble
    {
        if (!is_file($path)) {
            throw new \RuntimeException('The synthetic upload file is missing.');
        }

        self::$makeCalls++;
        return new ImageDouble();
    }
}

final class ImageDouble
{
    private string $format = '';

    public function fit(int $width, int $height): self
    {
        return $this;
    }

    public function encode(string $format, int $quality): self
    {
        $this->format = $format;
        return $this;
    }

    public function save(string $path): void
    {
        ImageManagerStatic::$saved[] = [$path, $this->format];
    }
}
