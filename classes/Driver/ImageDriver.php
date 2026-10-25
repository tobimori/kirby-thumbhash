<?php

namespace tobimori\Driver;

use Exception;

interface ImageDriver
{
	/**
	 * Crops an image file and scales the crop to $width x $height, ignoring the EXIF orientation
	 *
	 * @return list<int> RGBA pixel data
	 * @throws Exception if the driver can't read the image
	 */
	public static function sample(string $file, int $x, int $y, int $cropWidth, int $cropHeight, int $width, int $height): array;

	public static function isAvailable(): bool;
}
