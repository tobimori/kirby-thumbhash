<?php

namespace tobimori\Driver;

interface ImageDriver
{
	/**
	 * Extracts width, height, and RGBA pixel data from image content
	 *
	 * @param string $content Binary image data
	 * @return array{int, int, list<int>} [$width, $height, $pixels]
	 */
	public static function extractPixels(string $content): array;

	/**
	 * Crops an image file and scales the crop to the given size, without the EXIF orientation
	 *
	 * @return list<int>|null RGBA pixel data, or null if the driver can't read the image
	 */
	public static function sample(string $file, int $x, int $y, int $cropWidth, int $cropHeight, int $width, int $height): array|null;

	/**
	 * Checks if this driver is available on the system
	 */
	public static function isAvailable(): bool;
}
