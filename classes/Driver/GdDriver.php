<?php

namespace tobimori\Driver;

use Exception;
use GdImage;
use Kirby\Toolkit\Str;

use function ceil;
use function extension_loaded;
use function file_get_contents;
use function gd_info;
use function getimagesize;
use function imagealphablending;
use function imagecolorat;
use function imagecopyresampled;
use function imagecopyresized;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagefill;
use function imagesavealpha;
use function imagetypes;
use function ini_get;
use function memory_get_usage;
use function min;
use function str_contains;

class GdDriver implements ImageDriver
{
	public static function sample(string $file, int $x, int $y, int $cropWidth, int $cropHeight, int $width, int $height): array
	{
		$size = getimagesize($file) ?: throw new Exception('Unable to read the image size');
		$type = match ($size[2]) {
			IMAGETYPE_JPEG => IMG_JPG,
			IMAGETYPE_PNG => IMG_PNG,
			IMAGETYPE_GIF => IMG_GIF,
			IMAGETYPE_WEBP => IMG_WEBP,
			IMAGETYPE_AVIF => IMG_AVIF,
			IMAGETYPE_BMP => IMG_BMP,
			default => 0
		};
		if (($type & imagetypes()) === 0) {
			throw new Exception("GD can't read {$size['mime']} images");
		}

		// The GD bundled with PHP allocates its images in the PHP memory limit, and running out of memory is a fatal error that
		// can't be caught. GD needs up to about 6 bytes per pixel. A system libgd allocates outside of the PHP memory limit.
		$limit = str_contains((string)gd_info()['GD Version'], 'bundled') ? Str::toBytes((string)ini_get('memory_limit')) : 0;
		if ($limit > 0 && (memory_get_usage() + (6 * $size[0] * $size[1])) > $limit) {
			throw new Exception("Not enough memory to read a {$size[0]}x{$size[1]} image with GD");
		}

		$content = file_get_contents($file);
		$image = $content === false ? false : imagecreatefromstring($content);
		unset($content);
		if ($image === false) {
			throw new Exception('Unable to read the image with GD');
		}

		// imagecopyresampled() reads every covered pixel, which is slow for large images. Point samples on a grid of at most
		// 16 x 16 per sample pixel give almost the same average.
		$stepWidth = min($cropWidth, 16 * $width);
		$stepHeight = min($cropHeight, 16 * $height);
		if ($stepWidth < $cropWidth || $stepHeight < $cropHeight) {
			$step = self::canvas($stepWidth, $stepHeight);
			imagecopyresized($step, $image, 0, 0, $x, $y, $stepWidth, $stepHeight, $cropWidth, $cropHeight);
			$image = $step;
			$x = 0;
			$y = 0;
			$cropWidth = $stepWidth;
			$cropHeight = $stepHeight;
		}

		// imagescale() shifts the content at large reduction factors, imagecopyresampled() averages the covered pixels
		$sample = self::canvas($width, $height);
		imagecopyresampled($sample, $image, 0, 0, $x, $y, $width, $height, $cropWidth, $cropHeight);

		// GD alpha goes from 0 (opaque) to 127 (transparent)
		$alphas = [];
		for ($alpha = 0; $alpha < 128; $alpha++) {
			$alphas[$alpha] = 255 - (int)ceil(($alpha * 255) / 127);
		}

		$pixels = [];
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$color = (int)imagecolorat($sample, $x, $y);
				$pixels[] = ($color >> 16) & 255;
				$pixels[] = ($color >> 8) & 255;
				$pixels[] = $color & 255;
				$pixels[] = $alphas[$color >> 24];
			}
		}

		return $pixels;
	}

	/**
	 * Creates a transparent truecolor image that keeps the alpha of copied pixels
	 */
	private static function canvas(int $width, int $height): GdImage
	{
		$image = imagecreatetruecolor($width, $height);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		imagefill($image, 0, 0, 127 << 24);

		return $image;
	}

	public static function isAvailable(): bool
	{
		return extension_loaded('gd');
	}
}
