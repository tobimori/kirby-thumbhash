<?php

namespace tobimori\Driver;

use Exception;
use Imagick;

use function ceil;
use function count;
use function extension_loaded;
use function getimagesize;
use function max;
use function round;

class ImagickDriver implements ImageDriver
{
	public static function sample(string $file, int $x, int $y, int $cropWidth, int $cropHeight, int $width, int $height): array
	{
		$size = getimagesize($file) ?: throw new Exception('Unable to read the image size');

		$image = new Imagick();
		try {
			// With the size hint, libjpeg decodes at 1/2, 1/4, or 1/8 of the size. Below 16 decoded pixels per sample pixel on
			// each axis, its scaling drifts from the area average.
			$hintScale = max((16 * $width) / $cropWidth, (16 * $height) / $cropHeight);
			if ($hintScale < 1) {
				$image->setOption('jpeg:size', (int)ceil($size[0] * $hintScale) . 'x' . (int)ceil($size[1] * $hintScale));
			}
			// [0] reads only the first frame of animated images
			$image->readImage("{$file}[0]");

			if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
				$image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
			}

			$scale = $image->getImageWidth() / $size[0];
			$image->cropImage(
				max(1, (int)round($cropWidth * $scale)),
				max(1, (int)round($cropHeight * $scale)),
				(int)round($x * $scale),
				(int)round($y * $scale)
			);
			$image->setImagePage(0, 0, 0, 0);
			$image->scaleImage($width, $height);

			if ($image->getImageAlphaChannel()) {
				/** @var list<int> */
				return $image->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);
			}

			// Without an alpha channel, ImageMagick 6 exports undefined alpha values
			/** @var list<int> $rgb */
			$rgb = $image->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);
			$pixels = [];
			for ($i = 0, $count = count($rgb); $i < $count; $i += 3) {
				$pixels[] = $rgb[$i];
				$pixels[] = $rgb[$i + 1];
				$pixels[] = $rgb[$i + 2];
				$pixels[] = 255;
			}

			return $pixels;
		} finally {
			$image->clear();
		}
	}

	public static function isAvailable(): bool
	{
		return extension_loaded('imagick');
	}
}
