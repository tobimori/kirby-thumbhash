<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use tobimori\ThumbHash;

App::plugin('tobimori/thumbhash', [
  'fileMethods' => [
    /** @kql-allowed */
    'thumbhash' => fn (array $options = []) => ThumbHash::encode($this, $options),
    /** @kql-allowed */
    'th' => fn (array $options = []) => $this->thumbhash($options),
    /** @kql-allowed */
    'thumbhashUri' => fn (array $options = []) => ThumbHash::thumb($this, $options),
    /** @kql-allowed */
    'thUri' => fn (array $options = []) => $this->thumbhashUri($options),
  ],
  'assetMethods' => [
    /** @kql-allowed */
    'thumbhash' => fn (array $options = []) => ThumbHash::encode($this, $options),
    /** @kql-allowed */
    'th' => fn (array $options = []) => $this->thumbhash($options),
    /** @kql-allowed */
    'thumbhashUri' => fn (array $options = []) => ThumbHash::thumb($this, $options),
    /** @kql-allowed */
    'thUri' => fn (array $options = []) => $this->thumbhashUri($options),
  ],
  'options' => [
    'cache.encode' => true,
    'cache.decode' => true,
    //'engine' => 'gd', // `gd` or `imagick` - TODO
    'blurRadius' => 1, // Blur radius, larger values are smoother, but less accurate
    'sampleMaxSize' => 100, // Max width or height for smaller image that gets encoded (Memory constraints)
  ],
  'hooks' => [
    'file.update:before' => fn ($file) => ThumbHash::clearCache($file),
    'file.replace:before' => fn ($file) => ThumbHash::clearCache($file),
  ]
]);
