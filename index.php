<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use tobimori\ThumbHash;

App::plugin('tobimori/thumbhash', [
  'fileMethods' => [
    /** @kql-allowed */
    'thumbhash' => fn (float|array|null $ratio = null, array $options = []) => ThumbHash::encode($this, $ratio, $options),
    /** @kql-allowed */
    'th' => fn (float|array|null $ratio = null, array $options = []) => $this->thumbhash($ratio, $options),
    /** @kql-allowed */
    'thumbhashUri' => fn (float|array|null $ratio = null, float|null $blurRadius = null, array $options = []) => ThumbHash::thumb($this, $ratio, $blurRadius, $options),
    /** @kql-allowed */
    'thUri' => fn (float|array|null $ratio = null, float|null $blurRadius = null, array $options = []) => $this->thumbhashUri($ratio, $blurRadius, $options),
    /** @kql-allowed */
    'averageColor' => fn (string $format = 'hex', float|null $ratio = null) => ThumbHash::averageColor($this, $format, $ratio),
    /** @kql-allowed */
    'averageColorRgba' => fn (float|null $ratio = null) => ThumbHash::averageColorRgba($this, $ratio),
  ],
  'assetMethods' => [
    /** @kql-allowed */
    'thumbhash' => fn (float|array|null $ratio = null, array $options = []) => ThumbHash::encode($this, $ratio, $options),
    /** @kql-allowed */
    'th' => fn (float|array|null $ratio = null, array $options = []) => $this->thumbhash($ratio, $options),
    /** @kql-allowed */
    'thumbhashUri' => fn (float|array|null $ratio = null, float|null $blurRadius = null, array $options = []) => ThumbHash::thumb($this, $ratio, $blurRadius, $options),
    /** @kql-allowed */
    'thUri' => fn (float|array|null $ratio = null, float|null $blurRadius = null, array $options = []) => $this->thumbhashUri($ratio, $blurRadius, $options),
    /** @kql-allowed */
    'averageColor' => fn (string $format = 'hex', float|null $ratio = null) => ThumbHash::averageColor($this, $format, $ratio),
    /** @kql-allowed */
    'averageColorRgba' => fn (float|null $ratio = null) => ThumbHash::averageColorRgba($this, $ratio),
  ],
  'options' => [
    'cache.encode' => true,
    'cache.decode' => true,
    'engine' => 'gd', // Image processing driver: 'gd' or 'imagick'
    'blurRadius' => 1, // Blur radius, larger values are smoother, but less accurate
    'sampleMaxSize' => 100, // Max width or height for smaller image that gets encoded (Memory constraints)
  ],
  'hooks' => [
    'file.update:before' => fn ($file) => ThumbHash::clearCache($file),
    'file.replace:before' => fn ($file) => ThumbHash::clearCache($file),
  ]
]);
