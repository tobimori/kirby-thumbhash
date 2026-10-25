<?php

namespace tobimori;

use Kirby\Cms\File;
use Kirby\Content\Field;
use Kirby\Filesystem\Asset;
use Kirby\Image\Focus;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;

use function floor;
use function getimagesize;
use function is_string;
use function max;
use function min;

/**
 * Samples an image in memory like a cropped Kirby thumb, without writing a file
 */
class Sampler
{
	/**
	 * Crops the image to the ratio of the size around its focus point and scales it down to the size at most
	 *
	 * @param class-string<GdDriver>|class-string<ImagickDriver> $driver
	 * @return array{int, int, list<int>}|null width, height, and RGBA pixel data, or null if the driver can't read the image
	 */
	public static function sample(Asset|File $file, string $driver, int $width, int $height): array|null
	{
		$root = $file->root();
		$size = $root === null ? false : getimagesize($root);
		if ($root === null || $size === false) {
			return null;
		}
		[$sourceWidth, $sourceHeight] = $size;

		$orientation = $file->exif()->orientation();
		$rotated = $orientation >= 5 && $orientation <= 8;
		if ($rotated) {
			[$width, $height] = [$height, $width];
		}

		// The focus point is set on the upright image, the source is read without its orientation
		$focus = $file instanceof File ? $file->content()->get('focus') : null;
		$focus = $focus instanceof Field ? $focus->value() : null;
		/** @var array{float, float} $point */
		$point = Focus::parse(Focus::normalize(is_string($focus) && $focus !== '' ? $focus : 'center'));
		[$x, $y] = $point;
		[$x, $y] = match ($orientation) {
			2 => [1 - $x, $y],
			3 => [1 - $x, 1 - $y],
			4 => [$x, 1 - $y],
			5 => [$y, $x],
			6 => [$y, 1 - $x],
			7 => [1 - $y, 1 - $x],
			8 => [1 - $y, $x],
			default => [$x, $y]
		};

		$cropWidth = $sourceWidth;
		$cropHeight = $sourceHeight;
		if (($width * $sourceHeight) > ($height * $sourceWidth)) {
			$cropHeight = ($sourceWidth * $height) / $width;
		} else {
			$cropWidth = ($sourceHeight * $width) / $height;
		}
		$left = min(max(0, ($sourceWidth * $x) - ($cropWidth / 2)), $sourceWidth - $cropWidth);
		$top = min(max(0, ($sourceHeight * $y) - ($cropHeight / 2)), $sourceHeight - $cropHeight);

		// Thumbs don't scale up
		$width = (int)max(1, min($width, floor($cropWidth)));
		$height = (int)max(1, min($height, floor($cropHeight)));

		$pixels = $driver::sample(
			$root,
			(int)floor($left),
			(int)floor($top),
			(int)max(1, floor($cropWidth)),
			(int)max(1, floor($cropHeight)),
			$width,
			$height
		);
		if ($pixels === null) {
			return null;
		}

		if ($orientation < 2 || $orientation > 8) {
			return [$width, $height, $pixels];
		}

		return self::orient($pixels, $width, $height, $orientation);
	}

	/**
	 * Turns the pixels of an image upright by its EXIF orientation
	 *
	 * @param list<int> $pixels
	 * @return array{int, int, list<int>}
	 */
	private static function orient(array $pixels, int $width, int $height, int $orientation): array
	{
		$rotated = $orientation >= 5;
		$uprightWidth = $rotated ? $height : $width;
		$uprightHeight = $rotated ? $width : $height;

		$upright = [];
		for ($y = 0; $y < $uprightHeight; $y++) {
			for ($x = 0; $x < $uprightWidth; $x++) {
				[$sourceX, $sourceY] = match ($orientation) {
					2 => [$width - 1 - $x, $y],
					3 => [$width - 1 - $x, $height - 1 - $y],
					4 => [$x, $height - 1 - $y],
					5 => [$y, $x],
					6 => [$y, $height - 1 - $x],
					7 => [$width - 1 - $y, $height - 1 - $x],
					default => [$width - 1 - $y, $x]
				};
				$i = (($sourceY * $width) + $sourceX) * 4;
				$upright[] = $pixels[$i];
				$upright[] = $pixels[$i + 1];
				$upright[] = $pixels[$i + 2];
				$upright[] = $pixels[$i + 3];
			}
		}

		return [$uprightWidth, $uprightHeight, $upright];
	}
}
