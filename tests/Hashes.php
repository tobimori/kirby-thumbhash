<?php

namespace tobimori\Tests;

use tobimori\Tests\Reference\Reference;

/**
 * ThumbHashes of the test images and random ThumbHashes
 */
class Hashes
{
	/**
	 * @return array<string, list<int>>
	 */
	public static function all(): array
	{
		static $hashes = null;
		if ($hashes !== null) {
			return $hashes;
		}

		$hashes = [];
		foreach (Images::cases() as $name => [$w, $h, $color, $alpha, $seed]) {
			$hashes[$name] = Reference::encode($w, $h, Images::create($w, $h, $color, $alpha, $seed));
		}

		mt_srand(2);
		for ($i = 0; $i < 300; $i++) {
			$hash = [];
			for ($j = 0; $j < 32; $j++) {
				$hash[] = mt_rand(0, 255);
			}

			// the ratio of valid hashes is never 0
			if (($hash[3] & 7) === 0) {
				$hash[3] |= mt_rand(1, 7);
			}

			$hashes["random {$i}"] = $hash;
		}

		return $hashes;
	}
}
