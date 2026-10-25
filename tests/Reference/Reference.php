<?php

namespace tobimori\Tests\Reference;

use Exception;
use Thumbhash\Thumbhash;

/**
 * Behavior of the released plugin (srwiez/thumbhash 1.6.0 and the plugin drivers), the tests compare the optimized code against it
 */
class Reference
{
	/**
	 * @return array{int, int, list<int>}
	 */
	public static function gdPixels(string $content): array
	{
		$image = imagecreatefromstring($content);
		if ($image === false) {
			throw new Exception('Unable to read image data with GD');
		}

		$width = imagesx($image);
		$height = imagesy($image);

		$pixels = [];
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$color_index = imagecolorat($image, $x, $y);
				if ($color_index === false) {
					throw new Exception('Unable to read pixel data with GD');
				}

				/** @var array{red: int, green: int, blue: int, alpha: int} $color */
				$color = imagecolorsforindex($image, $color_index);
				$alpha = 255 - (int)ceil(($color['alpha'] * 255) / 127);
				$pixels[] = $color['red'];
				$pixels[] = $color['green'];
				$pixels[] = $color['blue'];
				$pixels[] = $alpha;
			}
		}

		return [$width, $height, $pixels];
	}

	/**
	 * @param list<int> $rgba
	 * @return list<int>
	 */
	public static function encode(int $w, int $h, array $rgba): array
	{
		/** @var list<int> */
		return Thumbhash::RGBAToHash($w, $h, $rgba);
	}

	/**
	 * The DCT terms of `Thumbhash::RGBAToHash()`, before quantization
	 *
	 * @param list<int> $rgba
	 * @return array<string, mixed>
	 */
	public static function dct(int $w, int $h, array $rgba): array
	{
		$avg_r = 0;
		$avg_g = 0;
		$avg_b = 0;
		$avg_a = 0;

		for ($i = 0, $j = 0; $i < ($w * $h); $i++, $j += 4) {
			$alpha = $rgba[$j + 3] / 255;
			$avg_r += ($alpha / 255) * $rgba[$j];
			$avg_g += ($alpha / 255) * $rgba[$j + 1];
			$avg_b += ($alpha / 255) * $rgba[$j + 2];
			$avg_a += $alpha;
		}
		if ($avg_a) {
			$avg_r /= $avg_a;
			$avg_g /= $avg_a;
			$avg_b /= $avg_a;
		}

		$hasAlpha = $avg_a < ($w * $h);
		$l_limit = $hasAlpha ? 5 : 7;
		$lx = max(1, round(($l_limit * $w) / max($w, $h)));
		$ly = max(1, round(($l_limit * $h) / max($w, $h)));
		$l = [];
		$p = [];
		$q = [];
		$a = [];

		for ($i = 0, $j = 0; $i < ($w * $h); $i++, $j += 4) {
			$alpha = $rgba[$j + 3] / 255;
			$r = ($avg_r * (1 - $alpha)) + (($alpha / 255) * $rgba[$j]);
			$g = ($avg_g * (1 - $alpha)) + (($alpha / 255) * $rgba[$j + 1]);
			$b = ($avg_b * (1 - $alpha)) + (($alpha / 255) * $rgba[$j + 2]);
			$l[$i] = ($r + $g + $b) / 3;
			$p[$i] = (($r + $g) / 2) - $b;
			$q[$i] = $r - $g;
			$a[$i] = $alpha;
		}

		return [
			'lx' => (int)$lx,
			'ly' => (int)$ly,
			'l' => Thumbhash::encodeChannel($l, max(3, $lx), max(3, $ly), $w, $h),
			'p' => Thumbhash::encodeChannel($p, 3, 3, $w, $h),
			'q' => Thumbhash::encodeChannel($q, 3, 3, $w, $h),
			'a' => $hasAlpha ? Thumbhash::encodeChannel($a, 5, 5, $w, $h) : null
		];
	}

	/**
	 * @param list<int> $hash
	 * @return array{w: int, h: int, rgba: list<float|int>}
	 */
	public static function rgba(array $hash): array
	{
		/** @var array{w: float, h: float, rgba: list<float|int>} $image */
		$image = Thumbhash::hashToRGBA($hash);

		return ['w' => (int)$image['w'], 'h' => (int)$image['h'], 'rgba' => $image['rgba']];
	}

	/**
	 * @param list<int> $hash
	 * @return array{uri: string, width: int, height: int, transparent: bool}
	 */
	public static function decode(array $hash): array
	{
		/** @var array{w: int, h: int, rgba: non-empty-list<float|int>} $image */
		$image = Thumbhash::hashToRGBA($hash);

		/** @var string $uri */
		$uri = Thumbhash::rgbaToDataURL($image['w'], $image['h'], $image['rgba']);

		// the released plugin returned the size as floats with integer values
		return [
			'uri' => $uri,
			'width' => (int)$image['w'],
			'height' => (int)$image['h'],
			'transparent' => min(array_column(array_chunk($image['rgba'], 4), 3)) < 255
		];
	}

	/**
	 * @param list<int> $hash
	 * @return array<string, mixed>
	 */
	public static function averageRgba(array $hash): array
	{
		/** @var array<string, mixed> */
		return (new Thumbhash())->toAverageRGBA($hash);
	}

	/**
	 * @param list<int> $hash
	 */
	public static function aspectRatio(array $hash): float
	{
		return Thumbhash::toApproximateAspectRatio($hash);
	}

	/**
	 * @param list<int> $hash
	 */
	public static function toString(array $hash): string
	{
		return Thumbhash::convertHashToString($hash);
	}

	/**
	 * @return list<int>
	 */
	public static function fromString(string $hash): array
	{
		/** @var list<int> */
		return Thumbhash::convertStringToHash($hash);
	}

	/**
	 * @param array{uri: string, width: int, height: int, transparent: bool} $image
	 */
	public static function uri(array $image, float $blurRadius): string
	{
		if ($blurRadius === 0.0) {
			return $image['uri'];
		}

		$svgHeight = number_format($image['height'], 2, '.', '');
		$svgWidth = number_format($image['width'], 2, '.', '');

		$alphaFilter = '';
		if (!$image['transparent']) {
			$alphaFilter = <<<EOD
				<feComponentTransfer>
				    <feFuncA type="discrete" tableValues="1 1"></feFuncA>
				</feComponentTransfer>
				EOD;
		}

		$data = <<<EOD
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$svgWidth} {$svgHeight}">
			  <filter id="b" color-interpolation-filters="sRGB">
			    <feGaussianBlur stdDeviation="{$blurRadius}"></feGaussianBlur>
			    {$alphaFilter}
			  </filter>
			  <image filter="url(#b)" x="0" y="0" width="100%" height="100%" href="{$image['uri']}"></image>
			</svg>
			EOD;

		$data = preg_replace(['/\s+/', '/> </'], [' ', '><'], $data) ?? $data;
		$data = str_replace(['%2F', '%3A', '%3D'], ['/', ':', '='], rawurlencode($data));

		return "data:image/svg+xml;charset=utf-8,{$data}";
	}
}
