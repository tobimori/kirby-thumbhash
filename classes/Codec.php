<?php

namespace tobimori;

use Exception;

/**
 * ThumbHash encoder and decoder
 *
 * Based on srwiez/thumbhash (https://github.com/SRWieZ/thumbhash), Copyright (c) 2024 Eser DENIZ,
 * a port of evanw/thumbhash (https://github.com/evanw/thumbhash), Copyright (c) 2023 Evan Wallace,
 * both licensed under the MIT License
 */
class Codec
{
	/**
	 * Encodes an RGBA image to a ThumbHash. RGB should not be premultiplied by A.
	 *
	 * @param list<int> $rgba pixels row by row, w * h * 4 values
	 * @return list<int>
	 */
	public static function encode(int $w, int $h, array $rgba): array
	{
		['lx' => $lx, 'ly' => $ly, 'l' => $l, 'p' => $p, 'q' => $q, 'a' => $a] = self::dct($w, $h, $rgba);
		[$l_dc, $l_ac, $l_scale] = $l;
		[$p_dc, $p_ac, $p_scale] = $p;
		[$q_dc, $q_ac, $q_scale] = $q;
		$hasAlpha = $a !== null;
		[$a_dc, $a_ac, $a_scale] = $a ?? [0, [], 0];

		$isLandscape = $w > $h;
		$header24 =
			(int)round(63 * $l_dc) |
			((int)round(31.5 + (31.5 * $p_dc)) << 6) |
			((int)round(31.5 + (31.5 * $q_dc)) << 12) |
			((int)round(31 * $l_scale) << 18) |
			((int)$hasAlpha << 23);
		$header16 = ($isLandscape ? $ly : $lx) | ((int)round(63 * $p_scale) << 3) | ((int)round(63 * $q_scale) << 9) | ((int)$isLandscape << 15);
		$hash = [$header24 & 255, ($header24 >> 8) & 255, $header24 >> 16, $header16 & 255, $header16 >> 8];
		$ac_start = $hasAlpha ? 6 : 5;
		$ac_index = 0;

		if ($hasAlpha) {
			$hash[] = (int)round(15 * $a_dc) | ((int)round(15 * $a_scale) << 4);
		}

		$acList = $hasAlpha ? [$l_ac, $p_ac, $q_ac, $a_ac] : [$l_ac, $p_ac, $q_ac];

		foreach ($acList as $ac) {
			foreach ($ac as $f) {
				$hash_key = $ac_start + ($ac_index >> 1);
				$hash[$hash_key] ??= 0;
				$hash[$hash_key] |= (int)round(15 * $f) << (($ac_index++ & 1) << 2);
			}
		}

		return $hash;
	}

	/**
	 * Transforms an RGBA image to the luminance, yellow-blue, red-green, and alpha DCT terms of a ThumbHash
	 *
	 * @internal
	 * @param list<int> $rgba pixels row by row, w * h * 4 values
	 * @return array{lx: int, ly: int, l: array{float, list<float>, float}, p: array{float, list<float>, float}, q: array{float, list<float>, float}, a: array{float, list<float>, float}|null}
	 */
	public static function dct(int $w, int $h, array $rgba): array
	{
		// Encoding an image larger than 100x100 is slow with no benefit
		if ($w > 100 || $h > 100) {
			throw new Exception("{$w}x{$h} doesn't fit in 100x100");
		}

		// Determine the average color
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
		$l_limit = $hasAlpha ? 5 : 7; // Use fewer luminance bits if there's alpha
		$lx = (int)max(1, round(($l_limit * $w) / max($w, $h)));
		$ly = (int)max(1, round(($l_limit * $h) / max($w, $h)));
		$l = []; // luminance
		$p = []; // yellow - blue
		$q = []; // red - green
		$a = []; // alpha

		// Convert the image from RGBA to LPQA (composite atop the average color)
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

		// Encode using the DCT into DC (constant) and normalized AC (varying) terms
		return [
			'lx' => $lx,
			'ly' => $ly,
			'l' => self::encodeChannel($l, max(3, $lx), max(3, $ly), $w, $h),
			'p' => self::encodeChannel($p, 3, 3, $w, $h),
			'q' => self::encodeChannel($q, 3, 3, $w, $h),
			'a' => $hasAlpha ? self::encodeChannel($a, 5, 5, $w, $h) : null
		];
	}

	/**
	 * Encodes a channel with the Discrete Cosine Transform (DCT) into DC (constant) and normalized AC (varying) terms
	 *
	 * @param array<int, float|int> $channel
	 * @return array{float, list<float>, float}
	 */
	private static function encodeChannel(array $channel, int $nx, int $ny, int $w, int $h): array
	{
		$dc = 0;
		$ac = [];
		$scale = 0;

		$rows = [];
		for ($cx = 0; $cx < $nx; $cx++) {
			$fx = [];
			for ($x = 0; $x < $w; $x++) {
				$fx[$x] = cos((pi() / $w) * $cx * ($x + 0.5));
			}
			for ($y = 0, $i = 0; $y < $h; $y++) {
				$sum = 0;
				for ($x = 0; $x < $w; $x++, $i++) {
					$sum += $channel[$i] * $fx[$x];
				}
				$rows[$cx][$y] = $sum;
			}
		}

		for ($cy = 0; $cy < $ny; $cy++) {
			$fy = [];
			for ($y = 0; $y < $h; $y++) {
				$fy[$y] = cos((pi() / $h) * $cy * ($y + 0.5));
			}
			for ($cx = 0; ($cx * $ny) < ($nx * ($ny - $cy)); $cx++) {
				$f = 0;
				for ($y = 0; $y < $h; $y++) {
					$f += $rows[$cx][$y] * $fy[$y];
				}
				$f /= $w * $h;
				if ($cx || $cy) {
					$ac[] = $f;
					$scale = max($scale, abs($f));
				} else {
					$dc = $f;
				}
			}
		}
		if ($scale) {
			for ($i = 0, $n = count($ac); $i < $n; $i++) {
				$ac[$i] = 0.5 + ((0.5 / $scale) * $ac[$i]);
			}
		}

		return [$dc, $ac, $scale];
	}

	/**
	 * Decodes a ThumbHash to an RGBA image. RGB is not premultiplied by A.
	 *
	 * @param list<int> $hash
	 * @return array{w: int, h: int, rgba: list<float>}
	 */
	public static function decode(array $hash): array
	{
		// Read the constants
		$header24 = $hash[0] | ($hash[1] << 8) | ($hash[2] << 16);
		$header16 = $hash[3] | ($hash[4] << 8);
		$l_dc = ($header24 & 63) / 63;
		$p_dc = ((($header24 >> 6) & 63) / 31.5) - 1;
		$q_dc = ((($header24 >> 12) & 63) / 31.5) - 1;
		$l_scale = (($header24 >> 18) & 31) / 31;
		$hasAlpha = $header24 >> 23;
		$p_scale = (($header16 >> 3) & 63) / 63;
		$q_scale = (($header16 >> 9) & 63) / 63;
		$isLandscape = $header16 >> 15;
		$l_limit = $hasAlpha ? 5 : 7;
		$lx = max(3, $isLandscape ? $l_limit : $header16 & 7);
		$ly = max(3, $isLandscape ? $header16 & 7 : $l_limit);
		$a_dc = $hasAlpha ? ($hash[5] & 15) / 15 : 1;

		// Read the varying factors (boost saturation by 1.25x to compensate for quantization)
		$ac_start = $hasAlpha ? 6 : 5;
		$ac_index = 0;
		$decodeChannel = static function (int $nx, int $ny, float $scale) use ($hash, $ac_start, &$ac_index): array {
			$ac = [];
			for ($cy = 0; $cy < $ny; $cy++) {
				for ($cx = $cy ? 0 : 1; ($cx * $ny) < ($nx * ($ny - $cy)); $cx++) {
					$ac[] = (((($hash[$ac_start + ($ac_index >> 1)] >> (($ac_index++ & 1) << 2)) & 15) / 7.5) - 1) * $scale;
				}
			}

			return $ac;
		};
		$l_ac = $decodeChannel($lx, $ly, $l_scale);
		$p_ac = $decodeChannel(3, 3, $p_scale * 1.25);
		$q_ac = $decodeChannel(3, 3, $q_scale * 1.25);
		$a_ac = $hasAlpha ? $decodeChannel(5, 5, ($hash[5] >> 4) / 15) : [];

		// Decode using the DCT into RGB
		$ratio = self::aspectRatio($hash);
		$w = (int)round($ratio > 1 ? 32 : 32 * $ratio);
		$h = (int)round($ratio > 1 ? 32 / $ratio : 32);
		$rgba = [];

		// Precompute the coefficients: they only depend on either x or y
		$fxs = [];
		for ($x = 0, $n = max($lx, $hasAlpha ? 5 : 3); $x < $w; $x++) {
			for ($cx = 0; $cx < $n; $cx++) {
				$fxs[$x][$cx] = cos((M_PI / $w) * ($x + 0.5) * $cx);
			}
		}
		$fys = [];
		for ($y = 0, $n = max($ly, $hasAlpha ? 5 : 3); $y < $h; $y++) {
			for ($cy = 0; $cy < $n; $cy++) {
				$fys[$y][$cy] = cos((M_PI / $h) * ($y + 0.5) * $cy);
			}
		}

		for ($y = 0, $i = 0; $y < $h; $y++) {
			$fy = $fys[$y];
			for ($x = 0; $x < $w; $x++, $i += 4) {
				$fx = $fxs[$x];
				$l = $l_dc;
				$p = $p_dc;
				$q = $q_dc;
				$a = $a_dc;

				// Decode L
				for ($cy = 0, $j = 0; $cy < $ly; $cy++) {
					for ($cx = $cy ? 0 : 1, $fy2 = $fy[$cy] * 2; ($cx * $ly) < ($lx * ($ly - $cy)); $cx++, $j++) {
						$l += $l_ac[$j] * $fx[$cx] * $fy2;
					}
				}

				// Decode P and Q
				for ($cy = 0, $j = 0; $cy < 3; $cy++) {
					for ($cx = $cy ? 0 : 1, $fy2 = $fy[$cy] * 2; $cx < (3 - $cy); $cx++, $j++) {
						$f = $fx[$cx] * $fy2;
						$p += $p_ac[$j] * $f;
						$q += $q_ac[$j] * $f;
					}
				}

				// Decode A
				if ($hasAlpha) {
					for ($cy = 0, $j = 0; $cy < 5; $cy++) {
						for ($cx = $cy ? 0 : 1, $fy2 = $fy[$cy] * 2; $cx < (5 - $cy); $cx++, $j++) {
							$a += $a_ac[$j] * $fx[$cx] * $fy2;
						}
					}
				}

				// Convert to RGB
				$b = $l - ((2 / 3) * $p);
				$r = ((3 * $l) - $b + $q) / 2;
				$g = $r - $q;
				$rgba[$i] = max(0, 255 * min(1, $r));
				$rgba[$i + 1] = max(0, 255 * min(1, $g));
				$rgba[$i + 2] = max(0, 255 * min(1, $b));
				$rgba[$i + 3] = max(0, 255 * min(1, $a));
			}
		}

		return ['w' => $w, 'h' => $h, 'rgba' => $rgba];
	}

	/**
	 * Extracts the average color from a ThumbHash. RGB is not premultiplied by A.
	 *
	 * @param list<int> $hash
	 * @return array{r: float, g: float, b: float, a: float}
	 */
	public static function averageRgba(array $hash): array
	{
		$header = $hash[0] | ($hash[1] << 8) | ($hash[2] << 16);
		$l = ($header & 63) / 63;
		$p = ((($header >> 6) & 63) / 31.5) - 1;
		$q = ((($header >> 12) & 63) / 31.5) - 1;
		$hasAlpha = $header >> 23;
		$a = $hasAlpha ? ($hash[5] & 15) / 15 : 1;
		$b = $l - ((2 / 3) * $p);
		$r = ((3 * $l) - $b + $q) / 2;
		$g = $r - $q;

		return [
			'r' => max(0, min(1, $r)),
			'g' => max(0, min(1, $g)),
			'b' => max(0, min(1, $b)),
			'a' => $a
		];
	}

	/**
	 * Extracts the approximate aspect ratio (width / height) of the original image
	 *
	 * @param list<int> $hash
	 */
	public static function aspectRatio(array $hash): float
	{
		$header = $hash[3];
		$hasAlpha = $hash[2] & 0x80;
		$isLandscape = $hash[4] & 0x80;
		$l_limit = $hasAlpha ? 5 : 7;
		$lx = $isLandscape ? $l_limit : $header & 7;
		$ly = $isLandscape ? $header & 7 : $l_limit;

		return $lx / $ly;
	}

	/**
	 * Encodes an RGBA image to an uncompressed PNG data URL. RGB should not be premultiplied by A.
	 *
	 * @param list<int|float> $rgba pixels row by row, w * h * 4 values
	 */
	public static function pngDataUrl(int $w, int $h, array $rgba): string
	{
		$row = ($w * 4) + 1;
		$raw = '';
		$deflate = "\x78\x01"; // zlib header

		// One uncompressed ("stored") deflate block per scanline
		for ($y = 0; $y < $h; $y++) {
			$line = "\0" . pack('C*', ...array_map(intval(...), array_slice($rgba, $y * $w * 4, $w * 4)));
			$deflate .= pack('Cvv', ($y + 1) < $h ? 0 : 1, $row, ~$row & 0xFFFF) . $line;
			$raw .= $line;
		}
		$deflate .= pack('N', hexdec(hash('adler32', $raw)));

		$png =
			"\x89PNG\r\n\x1a\n" .
			self::pngChunk('IHDR', pack('NNC5', $w, $h, 8, 6, 0, 0, 0)) .
			self::pngChunk('IDAT', $deflate) .
			self::pngChunk('IEND', '');

		return 'data:image/png;base64,' . base64_encode($png);
	}

	private static function pngChunk(string $type, string $data): string
	{
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	/**
	 * @param list<int> $hash
	 */
	public static function toString(array $hash): string
	{
		return rtrim(base64_encode(implode(array_map(chr(...), $hash))), '=');
	}

	/**
	 * @return list<int>
	 */
	public static function fromString(string $hash): array
	{
		// Strict decoding rejects the padding of some lengths
		// @mago-expect lint:strict-behavior
		return array_map(ord(...), str_split(base64_decode($hash . '=')));
	}
}
