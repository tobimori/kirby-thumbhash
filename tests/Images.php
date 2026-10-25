<?php

namespace tobimori\Tests;

use GdImage;
use Imagick;

/**
 * Deterministic test images
 */
class Images
{
	private const array SIZES = [
		[1, 1],
		[1, 100],
		[100, 1],
		[2, 2],
		[3, 7],
		[17, 13],
		[33, 100],
		[56, 100],
		[75, 100],
		[99, 100],
		[100, 56],
		[100, 75],
		[100, 99],
		[100, 100]
	];

	private const array COLORS = ['photo', 'noise', 'flat'];
	private const array ALPHAS = ['opaque', 'shape', 'gradient', 'noise', 'transparent', 'single'];

	/**
	 * Parameters of `create()` for all size, color, and alpha combinations, and random ones
	 *
	 * @return array<string, array{int, int, string, string, int}>
	 */
	public static function cases(): array
	{
		$cases = [];
		foreach (self::SIZES as [$w, $h]) {
			foreach (self::COLORS as $color) {
				foreach (self::ALPHAS as $alpha) {
					$cases["{$w}x{$h} {$color} {$alpha}"] = [$w, $h, $color, $alpha, crc32("{$w}{$h}{$color}{$alpha}")];
				}
			}
		}

		mt_srand(1);
		for ($i = 0; $i < 150; $i++) {
			$w = mt_rand(1, 100);
			$h = mt_rand(1, 100);
			$color = self::COLORS[mt_rand(0, 1)];
			$alpha = self::ALPHAS[mt_rand(0, 3)];
			$cases["random {$i} {$w}x{$h} {$color} {$alpha}"] = [$w, $h, $color, $alpha, $i];
		}

		return $cases;
	}

	/**
	 * The test images as PNG, JPEG, WebP, palette PNG, and GIF files, and with Imagick as 16-bit, grayscale, and CMYK files
	 *
	 * @return array<string, string>
	 */
	public static function files(): array
	{
		$files = [];
		foreach ([[100, 75], [64, 100], [100, 100], [1, 1], [100, 3]] as [$w, $h]) {
			foreach (['opaque', 'shape', 'gradient'] as $alpha) {
				$pixels = self::create($w, $h, 'photo', $alpha, crc32("file{$w}{$h}{$alpha}"));
				foreach (self::gdFiles($w, $h, $pixels) as $format => $content) {
					$files["{$w}x{$h} {$alpha} {$format}"] = $content;
				}

				if (extension_loaded('imagick')) {
					foreach (self::imagickFiles($w, $h, $pixels) as $format => $content) {
						$files["{$w}x{$h} {$alpha} imagick {$format}"] = $content;
					}
				}
			}
		}

		return $files;
	}

	/**
	 * @return list<int>
	 */
	public static function create(int $w, int $h, string $color, string $alpha, int $seed): array
	{
		mt_srand($seed);
		$base = [mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)];
		$waves = [];
		for ($c = 0; $c < 3; $c++) {
			for ($k = 0; $k < 3; $k++) {
				$waves[$c][] = [mt_rand(10, 80), mt_rand(1, 40) / 10, mt_rand(1, 40) / 10, mt_rand(0, 628) / 100];
			}
		}
		$cx = mt_rand(0, $w);
		$cy = mt_rand(0, $h);
		$radius = mt_rand(1, max($w, $h));
		$single = mt_rand(0, ($w * $h) - 1);

		$pixels = [];
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				for ($c = 0; $c < 3; $c++) {
					$pixels[] = match ($color) {
						'flat' => $base[$c],
						'noise' => mt_rand(0, 255),
						default => self::clamp(
							$base[$c] +
								array_sum(array_map(
									static fn (array $wave) => $wave[0] * sin((($wave[1] * $x) / max($w, 1)) + (($wave[2] * $y) / max($h, 1)) + $wave[3]),
									$waves[$c]
								)) +
								mt_rand(-8, 8)
						)
					};
				}

				$pixels[] = match ($alpha) {
					'opaque' => 255,
					'shape' => (($x - $cx) ** 2 + ($y - $cy) ** 2) <= ($radius ** 2) ? 255 : 0,
					'gradient' => self::clamp((255 * ($x + $y)) / max(1, $w + $h - 2)),
					'noise' => mt_rand(0, 255),
					'transparent' => 0,
					default => (($y * $w) + $x) === $single ? 254 : 255
				};
			}
		}

		return $pixels;
	}

	private static function clamp(float|int $value): int
	{
		return (int)max(0, min(255, round($value)));
	}

	/**
	 * @param list<int> $pixels
	 * @return array<string, string>
	 */
	private static function gdFiles(int $w, int $h, array $pixels): array
	{
		$image = imagecreatetruecolor($w, $h);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		for ($y = 0, $i = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++, $i += 4) {
				$alpha = 127 - ($pixels[$i + 3] >> 1);
				imagesetpixel($image, $x, $y, ($alpha << 24) | ($pixels[$i] << 16) | ($pixels[$i + 1] << 8) | $pixels[$i + 2]);
			}
		}

		$files = [];
		foreach ([
			'png' => imagepng(...),
			'jpeg' => static fn (GdImage $image) => imagejpeg($image, null, 70),
			'webp' => static fn (GdImage $image) => imagewebp($image, null, 80)
		] as $format => $write) {
			ob_start();
			$write($image);
			$files[$format] = (string)ob_get_clean();
		}

		$palette = imagecreatetruecolor($w, $h);
		imagealphablending($palette, false);
		imagesavealpha($palette, true);
		imagecopy($palette, $image, 0, 0, 0, 0, $w, $h);
		imagetruecolortopalette($palette, true, 64);
		foreach (['palette png' => imagepng(...), 'gif' => imagegif(...)] as $format => $write) {
			ob_start();
			$write($palette);
			$files[$format] = (string)ob_get_clean();
		}

		return $files;
	}

	/**
	 * @param list<int> $pixels
	 * @return array<string, string>
	 */
	private static function imagickFiles(int $w, int $h, array $pixels): array
	{
		$files = [];
		$variants = [
			'png16' => static fn (Imagick $image) => $image->setImageDepth(16),
			'png16 blurred' => static function (Imagick $image) {
				$image->setImageDepth(16);
				$image->blurImage(2, 0.7);
			},
			'gray png' => static fn (Imagick $image) => $image->transformImageColorspace(Imagick::COLORSPACE_GRAY),
			'cmyk jpeg' => static function (Imagick $image) {
				$image->transformImageColorspace(Imagick::COLORSPACE_CMYK);
				$image->setImageFormat('jpeg');
			},
			'gray jpeg' => static function (Imagick $image) {
				$image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
				$image->setImageFormat('jpeg');
			}
		];

		foreach ($variants as $format => $modify) {
			$image = new Imagick();
			$image->newImage($w, $h, 'transparent');
			$image->importImagePixels(0, 0, $w, $h, 'RGBA', Imagick::PIXEL_CHAR, $pixels);
			$image->setImageFormat('png');
			$modify($image);
			$files[$format] = $image->getImageBlob();
		}

		return $files;
	}
}
