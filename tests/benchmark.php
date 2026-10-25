<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Asset;
use Kirby\Filesystem\F;
use tobimori\Codec;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;
use tobimori\Sampler;
use tobimori\Tests\Fixtures;
use tobimori\ThumbHash;

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';

/**
 * Returns the best mean time per call in microseconds
 */
$measure = static function (Closure $run): float {
	$best = INF;
	for ($round = 0; $round < 10; $round++) {
		$iterations = 0;
		$start = hrtime(true);
		do {
			$run();
			$iterations++;
			$elapsed = hrtime(true) - $start;
		} while ($elapsed < 3e7);
		$best = min($best, ($elapsed / $iterations) / 1e3);
	}

	return $best;
};

$cases = [];
foreach (['earthrise-32x21', 'wizard-24x32', 'earthrise-100x67', 'wizard-75x100'] as $name) {
	['width' => $w, 'height' => $h, 'pixels' => $path] = Fixtures::expected()['images'][$name];
	$rgba = Fixtures::pixels($path);
	$hash = Codec::encode($w, $h, $rgba);
	$image = ThumbHash::decode($hash);
	$cases["encode {$name}"] = static fn () => Codec::encode($w, $h, $rgba);
	$cases["decode {$name}"] = static function () use ($hash): string {
		['w' => $w, 'h' => $h, 'rgba' => $rgba] = Codec::decode($hash);

		return Codec::pngDataUrl($w, $h, $rgba);
	};
	$cases["uri {$name}"] = static fn () => ThumbHash::uri($image, 1.0);
}

$root = App::instance()->root('index');
F::copy(Fixtures::ROOT . '/earthrise.jpg', "{$root}/assets/earthrise.jpg", true);
$asset = new Asset('assets/earthrise.jpg');
$cases['sample earthrise.jpg gd'] = static fn () => Sampler::sample($asset, GdDriver::class, 32, 21);
if (ImagickDriver::isAvailable()) {
	$cases['sample earthrise.jpg imagick'] = static fn () => Sampler::sample($asset, ImagickDriver::class, 32, 21);
}

foreach ($cases as $name => $run) {
	if (str_contains($name, $filter)) {
		printf("%-32s %10.1fµs\n", $name, $measure($run));
	}
}
