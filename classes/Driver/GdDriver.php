<?php

namespace tobimori\Driver;

use Exception;
use GdImage;
use Kirby\Toolkit\Str;

use function ceil;
use function extension_loaded;
use function file_get_contents;
use function getimagesize;
use function imagealphablending;
use function imagecolorat;
use function imagecolorsforindex;
use function imagecolorstotal;
use function imagecopyresampled;
use function imagecopyresized;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagefill;
use function imageistruecolor;
use function imagesavealpha;
use function imagesx;
use function imagesy;
use function imagetypes;
use function ini_get;
use function memory_get_usage;
use function min;

class GdDriver implements ImageDriver
{
	/**
	 * Extract image data using the GD extension.
	 * GD only provides 127-bit alpha data, so this up-scales it to 255 bits.
	 *
	 * @return array{int, int, list<int>}
	 */
	public static function extractPixels(string $content): array
	{
		$image = imagecreatefromstring($content);

		if ($image === false) {
			throw new Exception('Unable to read image data with GD');
		}

		return [imagesx($image), imagesy($image), self::pixels($image)];
	}

	public static function sample(string $file, int $x, int $y, int $cropWidth, int $cropHeight, int $width, int $height): array|null
	{
		$size = getimagesize($file);
		$type = match ($size[2] ?? null) {
			IMAGETYPE_JPEG => IMG_JPG,
			IMAGETYPE_PNG => IMG_PNG,
			IMAGETYPE_GIF => IMG_GIF,
			IMAGETYPE_WEBP => IMG_WEBP,
			IMAGETYPE_AVIF => IMG_AVIF,
			IMAGETYPE_BMP => IMG_BMP,
			default => 0
		};
		if ($size === false || ($type & imagetypes()) === 0) {
			return null;
		}

		// GD holds the whole image in memory, other thumb drivers may not
		$limit = Str::toBytes((string)ini_get('memory_limit'));
		if ($limit > 0 && (memory_get_usage() + (6 * $size[0] * $size[1])) > $limit) {
			return null;
		}

		$content = file_get_contents($file);
		$image = $content === false ? false : imagecreatefromstring($content);
		unset($content);
		if ($image === false) {
			return null;
		}

		// imagecopyresampled() reads every covered pixel, the average of 16 × 16 point samples per sample pixel is almost the same
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

		// imagescale() shifts the content when it scales down a lot, imagecopyresampled() averages the covered pixels
		$sample = self::canvas($width, $height);
		imagecopyresampled($sample, $image, 0, 0, $x, $y, $width, $height, $cropWidth, $cropHeight);

		return self::pixels($sample);
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

	/**
	 * @return list<int>
	 */
	private static function pixels(GdImage $image): array
	{
		$width = imagesx($image);
		$height = imagesy($image);

		// GD only supports 7-bit alpha channel
		$alphas = [];
		for ($alpha = 0; $alpha < 128; $alpha++) {
			$alphas[$alpha] = 255 - (int)ceil(($alpha * 255) / 127);
		}

		$pixels = [];
		if (imageistruecolor($image)) {
			for ($y = 0; $y < $height; $y++) {
				for ($x = 0; $x < $width; $x++) {
					$color = (int)imagecolorat($image, $x, $y);
					$pixels[] = ($color >> 16) & 255;
					$pixels[] = ($color >> 8) & 255;
					$pixels[] = $color & 255;
					$pixels[] = $alphas[$color >> 24];
				}
			}

			return $pixels;
		}

		$reds = [];
		$greens = [];
		$blues = [];
		$paletteAlphas = [];
		for ($index = 0, $total = imagecolorstotal($image); $index < $total; $index++) {
			/** @var array{red: int, green: int, blue: int, alpha: int} $color */
			$color = imagecolorsforindex($image, $index);
			$reds[$index] = $color['red'];
			$greens[$index] = $color['green'];
			$blues[$index] = $color['blue'];
			$paletteAlphas[$index] = $alphas[$color['alpha']];
		}

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$index = (int)imagecolorat($image, $x, $y);
				$pixels[] = $reds[$index];
				$pixels[] = $greens[$index];
				$pixels[] = $blues[$index];
				$pixels[] = $paletteAlphas[$index];
			}
		}

		return $pixels;
	}

	public static function isAvailable(): bool
	{
		return extension_loaded('gd');
	}
}
