<?php

@include_once __DIR__ . '/vendor/autoload.php';

use Kirby\Cms\App;
use tobimori\ThumbHash;

App::plugin('tobimori/thumbhash', [
  'fileMethods' => [
    /** @kql-allowed */
    'thumbhash' => fn (float|null $ratio = null) => ThumbHash::encode($this, $ratio),
    /** @kql-allowed */
    'th' => fn (float|null $ratio = null) => $this->thumbhash($ratio),
    /** @kql-allowed */
    'thumbhashUri' => fn (float|null $ratio = null) => ThumbHash::thumb($this, $ratio),
    /** @kql-allowed */
    'thUri' => fn (float|null $ratio = null) => $this->thumbhashUri($ratio),
  ],
  'assetMethods' => [
    /** @kql-allowed */
    'thumbhash' => fn (float|null $ratio = null) => ThumbHash::encode($this, $ratio),
    /** @kql-allowed */
    'th' => fn (float|null $ratio = null) => $this->thumbhash($ratio),
    /** @kql-allowed */
    'thumbhashUri' => fn (float|null $ratio = null) => ThumbHash::thumb($this, $ratio),
    /** @kql-allowed */
    'thUri' => fn (float|null $ratio = null) => $this->thumbhashUri($ratio),
  ],
  'options' => [
    'cache.encode' => true,
    'cache.decode' => true,
    //'engine' => 'gd', // `gd` or `imagick` - TODO
    'decodeTarget' => 100, // Pixel Target (width * height = ~P) for decoding
  ],
  'hooks' => [
    'file.update:before' => fn ($file) => ThumbHash::clearCache($file),
    'file.replace:before' => fn ($file) => ThumbHash::clearCache($file),
  ]
]);
