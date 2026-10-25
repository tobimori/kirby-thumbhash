<?php

namespace tobimori\Driver;

use Exception;

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

			return [$width, $height, $pixels];
		}

		$reds = $greens = $blues = $paletteAlphas = [];
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

		return [$width, $height, $pixels];
	}

	public static function isAvailable(): bool
	{
		return extension_loaded('gd');
	}
}
