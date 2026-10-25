<?php

namespace tobimori\Driver;

use Imagick;
use ImagickException;

use function ceil;
use function extension_loaded;
use function getimagesize;
use function max;
use function round;

class ImagickDriver implements ImageDriver
{
	/**
	 * Extract image data using Imagick extension
	 *
	 * @return array{int, int, list<int>}
	 */
	public static function extractPixels(string $content): array
	{
		$image = new Imagick();
		$image->readImageBlob($content);

		$width = $image->getImageWidth();
		$height = $image->getImageHeight();

		/** @var list<int> $pixels */
		$pixels = $image->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);

		$image->clear();
		$image->destroy();

		return [$width, $height, $pixels];
	}

	public static function sample(string $file, int $x, int $y, int $cropWidth, int $cropHeight, int $width, int $height): array|null
	{
		$size = getimagesize($file);
		if ($size === false) {
			return null;
		}

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
		} catch (ImagickException) {
			return null;
		} finally {
			$image->clear();
		}
	}

	public static function isAvailable(): bool
	{
		return extension_loaded('imagick');
	}
}
