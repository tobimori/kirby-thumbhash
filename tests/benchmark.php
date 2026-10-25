<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Asset;
use Kirby\Filesystem\F;
use tobimori\Codec;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;
use tobimori\Sampler;
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

$fixtures = __DIR__ . '/fixtures/images';
$root = App::instance()->root('index');
$cases = [];
foreach (['earthrise.jpg', 'wizard.png'] as $image) {
	F::copy("{$fixtures}/{$image}", "{$root}/assets/{$image}", true);
	$asset = new Asset("assets/{$image}");
	[$width, $height] = $asset->ratio() >= 1 ? [32, (int)round(32 / $asset->ratio())] : [(int)round(32 * $asset->ratio()), 32];

	$cases["sample {$image} gd"] = static fn () => Sampler::sample($asset, GdDriver::class, $width, $height);
	if (ImagickDriver::isAvailable()) {
		$cases["sample {$image} imagick"] = static fn () => Sampler::sample($asset, ImagickDriver::class, $width, $height);
	}

	foreach ([1, 3] as $scale) {
		[$w, $h, $rgba] = Sampler::sample($asset, GdDriver::class, $width * $scale, $height * $scale);
		$hash = Codec::encode($w, $h, $rgba);
		$placeholder = ThumbHash::decode($hash);
		$cases["encode {$image} {$w}x{$h}"] = static fn () => Codec::encode($w, $h, $rgba);
		$cases["decode {$image} {$w}x{$h}"] = static function () use ($hash): string {
			['w' => $w, 'h' => $h, 'rgba' => $rgba] = Codec::decode($hash);

			return Codec::pngDataUrl($w, $h, $rgba);
		};
		$cases["uri {$image} {$w}x{$h}"] = static fn () => ThumbHash::uri($placeholder, 1.0);
	}
}

foreach ($cases as $name => $run) {
	if (str_contains($name, $filter)) {
		printf("%-32s %10.1fµs\n", $name, $measure($run));
	}
}
