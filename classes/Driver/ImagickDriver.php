<?php

namespace tobimori\Driver;

use Exception;
use Imagick;

use function ceil;
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
			// libjpeg decodes at a fraction of the size, its scaled transform drifts from the area average below 16 pixels per sample
			$scale = max((16 * $width) / $cropWidth, (16 * $height) / $cropHeight);
			if ($scale < 1) {
				$image->setOption('jpeg:size', (int)ceil($size[0] * $scale) . 'x' . (int)ceil($size[1] * $scale));
			}
			$image->readImage($file);
			$image->setFirstIterator();

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

			/** @var list<int> */
			return $image->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);
		} finally {
			$image->clear();
		}
	}

	public static function isAvailable(): bool
	{
		return extension_loaded('imagick');
	}
}
