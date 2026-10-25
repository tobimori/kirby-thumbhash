<?php

namespace tobimori\Driver;

use Imagick;

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

	public static function isAvailable(): bool
	{
		return extension_loaded('imagick');
	}
}
