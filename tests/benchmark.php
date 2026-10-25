<?php

use tobimori\Codec;
use tobimori\Tests\Images;
use tobimori\Tests\Reference\Reference;
use tobimori\ThumbHash;

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';

/**
 * Returns the fastest mean times of the calls in microseconds, the rounds alternate between the calls to even out the load of the machine
 *
 * @return list<float>
 */
$measure = static function (Closure ...$runs): array {
	$best = array_fill(0, count($runs), INF);
	for ($round = 0; $round < 10; $round++) {
		foreach ($runs as $index => $run) {
			$iterations = 0;
			$start = hrtime(true);
			do {
				$run();
				$iterations++;
				$elapsed = hrtime(true) - $start;
			} while ($elapsed < 3e7);
			$best[$index] = min($best[$index], ($elapsed / $iterations) / 1e3);
		}
	}

	return $best;
};

$photo = Images::create(100, 75, 'photo', 'opaque', 1);
$alpha = Images::create(100, 100, 'photo', 'shape', 2);
$opaqueHash = Codec::encode(100, 75, $photo);
$alphaHash = Codec::encode(100, 100, $alpha);
$opaqueImage = ThumbHash::decode($opaqueHash);
$alphaImage = ThumbHash::decode($alphaHash);

/** @param list<int> $hash */
function decode_png(array $hash): string
{
	['w' => $w, 'h' => $h, 'rgba' => $rgba] = Codec::decode($hash);

	return Codec::pngDataUrl($w, $h, $rgba);
}

$cases = [
	'encode opaque' => [static fn () => Reference::encode(100, 75, $photo), static fn () => Codec::encode(100, 75, $photo)],
	'encode alpha' => [static fn () => Reference::encode(100, 100, $alpha), static fn () => Codec::encode(100, 100, $alpha)],
	'decode opaque' => [
		static fn () => Reference::decode($opaqueHash),
		static fn () => decode_png($opaqueHash)
	],
	'decode alpha' => [
		static fn () => Reference::decode($alphaHash),
		static fn () => decode_png($alphaHash)
	],
	'uri opaque' => [static fn () => Reference::uri($opaqueImage, 1.0), static fn () => ThumbHash::uri($opaqueImage, 1.0)],
	'uri alpha' => [static fn () => Reference::uri($alphaImage, 1.0), static fn () => ThumbHash::uri($alphaImage, 1.0)],
	'average color' => [
		static fn () => Reference::averageRgba(Reference::fromString('SwgGDQAHp3Z5WJioV3S5eAhhmQPY')),
		static fn () => Codec::averageRgba(Codec::fromString('SwgGDQAHp3Z5WJioV3S5eAhhmQPY'))
	]
];

printf("%-20s %12s %12s %9s\n", 'case', 'reference', 'current', 'speedup');
foreach ($cases as $name => [$reference, $current]) {
	if (!str_contains($name, $filter)) {
		continue;
	}

	[$referenceTime, $currentTime] = $measure($reference, $current);
	printf("%-20s %10.1fµs %10.1fµs %8.2fx\n", $name, $referenceTime, $currentTime, $referenceTime / $currentTime);
}
