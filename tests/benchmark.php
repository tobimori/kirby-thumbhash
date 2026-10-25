<?php

use tobimori\Codec;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;
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

$files = Images::files();
$photo = Images::create(100, 75, 'photo', 'opaque', 1);
$alpha = Images::create(100, 100, 'photo', 'shape', 2);
$opaqueHash = Codec::encode(100, 75, $photo);
$alphaHash = Codec::encode(100, 100, $alpha);
$opaqueImage = ThumbHash::decode($opaqueHash);
$alphaImage = ThumbHash::decode($alphaHash);

$cases = [
	'gd jpeg' => [static fn () => Reference::gdPixels($files['100x75 opaque jpeg']), static fn () => GdDriver::extractPixels($files['100x75 opaque jpeg'])],
	'gd png alpha' => [static fn () => Reference::gdPixels($files['100x100 shape png']), static fn () => GdDriver::extractPixels($files['100x100 shape png'])],
	'gd gif' => [static fn () => Reference::gdPixels($files['100x100 shape gif']), static fn () => GdDriver::extractPixels($files['100x100 shape gif'])],
	'imagick jpeg' => [
		static fn () => Reference::imagickPixels($files['100x75 opaque jpeg']),
		static fn () => ImagickDriver::extractPixels($files['100x75 opaque jpeg'])
	],
	'imagick png alpha' => [
		static fn () => Reference::imagickPixels($files['100x100 shape png']),
		static fn () => ImagickDriver::extractPixels($files['100x100 shape png'])
	],
	'encode opaque' => [static fn () => Reference::encode(100, 75, $photo), static fn () => Codec::encode(100, 75, $photo)],
	'encode alpha' => [static fn () => Reference::encode(100, 100, $alpha), static fn () => Codec::encode(100, 100, $alpha)],
	'decode opaque' => [static fn () => Reference::decode($opaqueHash), static fn () => ThumbHash::decode($opaqueHash)],
	'decode alpha' => [static fn () => Reference::decode($alphaHash), static fn () => ThumbHash::decode($alphaHash)],
	'uri opaque' => [static fn () => Reference::uri($opaqueImage, 1.0), static fn () => ThumbHash::uri($opaqueImage, 1.0)],
	'uri alpha' => [static fn () => Reference::uri($alphaImage, 1.0), static fn () => ThumbHash::uri($alphaImage, 1.0)],
	'average color' => [
		static fn () => Reference::averageRgba(Reference::fromString('SwgGDQAHp3Z5WJioV3S5eAhhmQPY')),
		static fn () => Codec::averageRgba(Codec::fromString('SwgGDQAHp3Z5WJioV3S5eAhhmQPY'))
	]
];

printf("%-20s %12s %12s %9s\n", 'case', 'reference', 'current', 'speedup');
foreach ($cases as $name => [$reference, $current]) {
	if (!str_contains($name, $filter) || str_starts_with($name, 'imagick') && !ImagickDriver::isAvailable()) {
		continue;
	}

	[$referenceTime, $currentTime] = $measure($reference, $current);
	printf("%-20s %10.1fµs %10.1fµs %8.2fx\n", $name, $referenceTime, $currentTime, $referenceTime / $currentTime);
}
