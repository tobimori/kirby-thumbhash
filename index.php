<?php

// @mago-expect lint:no-error-control-operator
@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Filesystem\Asset;
use tobimori\ThumbHash;

$methods = [
	/** @kql-allowed */
	'thumbhash' => function (float|array|null $ratio = null, array $options = []) {
		/** @var Asset|File $this */
		return ThumbHash::encode($this, $ratio, $options);
	},
	/** @kql-allowed */
	'thumbhashUri' => function (float|array|null $ratio = null, float|null $blurRadius = null, array $options = []) {
		/** @var Asset|File $this */
		return ThumbHash::thumb($this, $ratio, $blurRadius, $options);
	},
	/** @kql-allowed */
	'averageColor' => function (string $format = 'hex', float|null $ratio = null) {
		/** @var Asset|File $this */
		return ThumbHash::averageColor($this, $format, $ratio);
	},
	/** @kql-allowed */
	'averageColorRgba' => function (float|null $ratio = null) {
		/** @var Asset|File $this */
		return ThumbHash::averageColorRgba($this, $ratio);
	},
	/** @kql-allowed */
	'hasTransparency' => function (float|null $ratio = null) {
		/** @var Asset|File $this */
		return ThumbHash::hasTransparency($this, $ratio);
	}
];
$methods['th'] = $methods['thumbhash'];
$methods['thUri'] = $methods['thumbhashUri'];

App::plugin('tobimori/thumbhash', [
	'fileMethods' => $methods,
	'assetMethods' => $methods,
	'options' => [
		'cache.encode' => true,
		'cache.decode' => true,
		'engine' => null, // Image processing driver: 'gd' or 'imagick', null uses Imagick if available
		'blurRadius' => 1, // Blur radius, larger values are smoother, but less accurate
		'sampleMaxSize' => 32 // Max width or height of the sample image that gets encoded
	],
	'hooks' => [
		'file.update:before' => fn (File $file) => ThumbHash::clearCache($file),
		'file.replace:before' => fn (File $file) => ThumbHash::clearCache($file)
	]
]);
