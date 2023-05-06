<?php

namespace tobimori;

use Kirby\Cms\File;
use Kirby\Filesystem\Asset;
use Thumbhash\Thumbhash as THEncoder;

class ThumbHash
{
  /**
   * Creates thumb for an image based on the ThumbHash algorithm, returns a data URI with an SVG filter.
   */
  public static function thumb(Asset|File $file, float|null $ratio = null): string
  {
    $ratio ??= $file->ratio();

    $hash = self::encode($file, $ratio); // Encode image with ThumbHash Algorithm
    [$width, $height] = self::calcWidthHeight(option('tobimori.thumbhash.decodeTarget'), $ratio); // Get target width and height for decoding
    $image = self::decode($hash, $width, $height); // Decode ThumbHash to image

    return self::uri($image, $width, $height); // Output image as data URI with SVG blur
  }

  /**
   * Returns the ThumbHash for a Kirby file object.
   */
  public static function encode(Asset|File $file, float|null $ratio = null): string
  {
    $kirby = kirby();

    $id = self::getId($file);
    $ratio ??= $file->ratio();
    $cache = $kirby->cache('tobimori.thumbhash.encode');

    if (($cacheData = $cache->get($id)) !== null) {
      return $cacheData;
    }

    // Generate a sample image for encode to avoid memory issues.
    $max = $kirby->option('tobimori.thumbhash.sampleMaxSize'); // Max width or height

    $height = round($file->height() > $file->width() ? $max : $max * $ratio);
    $width = round($file->width() > $file->height() ? $max : $max * $ratio);
    $options = [
      'width' => $width,
      'height' => $height,
      'crop'  => true,
      'quality' => 70,
    ];

    // Create a GD image from the file.
    $image = imagecreatefromstring($file->thumb($options)->read()); // TODO: allow Imagick encoder
    $height = imagesy($image);
    $width = imagesx($image);
    $pixels = [];

    for ($y = 0; $y < $height; $y++) {
      for ($x = 0; $x < $width; $x++) {
        $color_index = imagecolorat($image, $x, $y);
        $color = imagecolorsforindex($image, $color_index);
        $alpha = 255 - ceil($color['alpha'] * (255 / 127)); // GD only supports 7-bit alpha channel
        $pixels[] = $color['red'];
        $pixels[] = $color['green'];
        $pixels[] = $color['blue'];
        $pixels[] = $alpha;
      }
    }

    $hashArray = THEncoder::RGBAToHash($pixels, $x, $y);
    $thumbhash = THEncoder::convertHashToString($hashArray);
    $cache->set($id, $thumbhash);

    return $thumbhash;
  }

  /**
   * Decodes a ThumbHash string or array to a binary image string.
   */
  public static function decode(string|array $thumbhash): string
  {
    $kirby = kirby();
    $cache = $kirby->cache('tobimori.thumbhash.decode');

    $id = is_array($thumbhash) ? THEncoder::convertHashToString($thumbhash) : $thumbhash;
    $thumbhash = is_string($thumbhash) ? THEncoder::convertStringToHash($thumbhash) : $thumbhash;

    if (($cacheData = $cache->get($id)) !== null) {
      return $cacheData;
    }

    $data = THEncoder::toDataURL($thumbhash);
    $cache->set($id, $data);
    return $data;
  }

  /**
   * Clears encoding cache for a file.
   */
  public static function clearCache(Asset|File $file)
  {
    $cache = kirby()->cache('tobimori.thumbhash.encode');
    $id = self::getId($file);
    $cache->remove($id);
  }

  /**
   * Returns an optimized URI-encoded string of an SVG for using in a src attribute.
   * Based on https://github.com/johannschopplich/kirby-blurry-placeholder/blob/main/BlurryPlaceholder.php#L65
   */
  private static function svgToUri(string $data): string
  {
    // Optimizes the data URI length by deleting line breaks and
    // removing unnecessary spaces
    $data = preg_replace('/\s+/', ' ', $data);
    $data = preg_replace('/> </', '><', $data);

    $data = rawurlencode($data);

    // Back-decode certain characters to improve compression
    // except '%20' to be compliant with W3C guidelines
    $data = str_replace(
      ['%2F', '%3A', '%3D'],
      ['/', ':', '='],
      $data
    );

    return 'data:image/svg+xml;charset=utf-8,' . $data;
  }

  /**
   * Applies SVG filter and base64-encoding to binary image.
   * Based on https://github.com/johannschopplich/kirby-blurry-placeholder/blob/main/BlurryPlaceholder.php#L10
   */
  private static function svgFilter(string $image, int $width, int $height): string
  {
    $svgHeight = number_format($height, 2, '.', '');
    $svgWidth = number_format($width, 2, '.', '');

    // Wrap the blurred image in a SVG to avoid rasterizing the filter
    $svg = <<<EOD
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$svgWidth} {$svgHeight}">
          <filter id="a" color-interpolation-filters="sRGB">
            <feGaussianBlur stdDeviation=".2"></feGaussianBlur>
            <feComponentTransfer>
              <feFuncA type="discrete" tableValues="1 1"></feFuncA>
            </feComponentTransfer>
          </filter>
          <image filter="url(#a)" x="0" y="0" width="100%" height="100%" href="{$image}"></image>
        </svg>
        EOD;

    return $svg;
  }

  /**
   * Returns a decoded ThumbHash as a URI-encoded SVG with blur filter applied.
   */
  public static function uri(string $image, int $width, int $height): string
  {
    $svg = self::svgFilter($image, $width, $height);
    $uri = self::svgToUri($svg);

    return $uri;
  }

  /**
   * Returns the width and height for a given ratio, based on a target entity count.
   * Aims for a size of ~x entities (width * height = ~x)
   */
  private static function calcWidthHeight(int $target, float $ratio): array
  {
    $height = round(sqrt($target / $ratio));
    $width = round($target / $height);

    return [$width, $height];
  }

  /**
   * Returns the uuid for a File, or its mediaHash for Assets.
   */
  private static function getId(Asset|File $file): string
  {
    if ($file instanceof Asset) {
      return $file->mediaHash();
    }

    return $file->uuid()->id() ?? $file->id();
  }
}
