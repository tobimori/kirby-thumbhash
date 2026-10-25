<?php

namespace tobimori\Tests;

use tobimori\Tests\Reference\Reference;

/**
 * ThumbHashes of the test images and random ThumbHashes
 */
class Hashes
{
	/**
	 * @return array<string, array{list<int>}>
	 */
	public static function cases(): array
	{
		static $hashes = null;
		if ($hashes !== null) {
			return $hashes;
		}

		$hashes = [];
		foreach (Images::cases() as $name => [$w, $h, $color, $alpha, $seed]) {
			$hashes[$name] = [Reference::encode($w, $h, Images::create($w, $h, $color, $alpha, $seed))];
		}

		mt_srand(2);
		for ($i = 0; $i < 300; $i++) {
			$hash = [];
			for ($j = 0; $j < 32; $j++) {
				$hash[] = mt_rand(0, 255);
			}

			// Valid hashes never have a luminance size of 0, which gives an aspect ratio of 0
			if (($hash[3] & 7) === 0) {
				$hash[3] |= mt_rand(1, 7);
			}

			$hashes["random {$i}"] = [$hash];
		}

		return $hashes;
	}
}
