<?php

namespace tobimori;

use Kirby\Cms\File;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Asset;
use Thumbhash\Thumbhash as THEncoder;

class ThumbHash
{
  /**
   * Creates thumb for an image based on the ThumbHash algorithm, returns a data URI with an SVG filter.
   */
  public static function thumb(Asset|File $file, array $options = []): ?string
  {
    $hash = self::encode($file, $options);
    if ($hash === null) {
      return null;
    }

    $rgba = self::decode($hash);

    return self::uri($rgba, $options);
  }

  /**
   * Returns the ThumbHash for a Kirby file object.
   */
  public static function encode(Asset|File $file, array $options = []): ?string
  {
    $kirby = App::instance();

    $id = self::getId($file);
    $options['ratio'] ??= $file->ratio();
    $cache = $kirby->cache('tobimori.thumbhash.encode');

    if (($cacheData = $cache->get($id)) !== null) {
      return $cacheData;
    }

    // generate a sample image for encode to avoid memory issues.
    $max = $kirby->option('tobimori.thumbhash.sampleMaxSize');

    $expectedHeight = round($options['ratio'] < 1 ? $max : $max / $options['ratio']);
    $expectedWidth = round($options['ratio'] >= 1 ? $max : $max * $options['ratio']);
    $thumbOptions = [
      'width' => $expectedWidth,
      'height' => $expectedHeight,
      'crop'  => true,
      'quality' => 70,
    ];

    $thumb = $file->thumb($thumbOptions);
    $actualWidth = $thumb->width();
    $actualHeight = $thumb->height();

    // check if dimensions differ by more than 10px
    if (abs($actualWidth - $expectedWidth) > 10 || abs($actualHeight - $expectedHeight) > 10) {
      if ($kirby->option('debug')) {
        throw new Exception("[ThumbHash] Failed to generate thumbhash for {$file->filename()}: Image could not be resized to expected sample dimensions");
      }

      return null;
    }

    // create a gd image from the file.
    $image = imagecreatefromstring($thumb->read());
    $height = imagesy($image);
    $width = imagesx($image);
    $pixels = [];

    for ($y = 0; $y < $height; $y++) {
      for ($x = 0; $x < $width; $x++) {
        $color_index = imagecolorat($image, $x, $y);
        $color = imagecolorsforindex($image, $color_index);
        $alpha = 255 - ceil($color['alpha'] * 255 / 127); // GD only supports 7-bit alpha channel
        $pixels[] = $color['red'];
        $pixels[] = $color['green'];
        $pixels[] = $color['blue'];
        $pixels[] = $alpha;
      }
    }

    $hashArray = THEncoder::RGBAToHash($width, $height, $pixels);
    $thumbhash = THEncoder::convertHashToString($hashArray);
    $cache->set($id, $thumbhash);

    return $thumbhash;
  }

  /**
   * Decodes a ThumbHash string or array to an array of RGBA values, and width & height
   */
  public static function decode(string|array $thumbhash): array
  {
    $kirby = App::instance();
    $cache = $kirby->cache('tobimori.thumbhash.decode');

    $id = is_array($thumbhash) ? THEncoder::convertHashToString($thumbhash) : $thumbhash;
    $thumbhash = is_string($thumbhash) ? THEncoder::convertStringToHash($thumbhash) : $thumbhash;

    if (($cacheData = $cache->get($id)) !== null) {
      return $cacheData;
    }

    $image = THEncoder::hashToRGBA($thumbhash);
    // check if any alpha value in RGBA array is less than 255
    $transparent = array_reduce(array_chunk($image['rgba'], 4), fn($carry, $item) => $carry || $item[3] < 255, false);

    $dataUri = THEncoder::rgbaToDataURL($image['w'], $image['h'], $image['rgba']);

    $data = [
      'uri' => $dataUri,
      'width' => $image['w'],
      'height' => $image['h'],
      'transparent' => $transparent,
    ];

    $cache->set($id, $data);
    return $data;
  }

  /**
   * Clears encoding cache for a file.
   */
  public static function clearCache(Asset|File $file)
  {
    $cache = App::instance()->cache('tobimori.thumbhash.encode');
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

    return "data:image/svg+xml;charset=utf-8,{$data}";
  }

  /**
   * Applies SVG filter and base64-encoding to binary image.
   * Based on https://github.com/johannschopplich/kirby-blurry-placeholder/blob/main/BlurryPlaceholder.php#L10
   */
  private static function svgFilter(array $image, array $options = []): string
  {

    $svgHeight = number_format($image['height'], 2, '.', '');
    $svgWidth = number_format($image['width'], 2, '.', '');

    // Wrap the blurred image in a SVG to avoid rasterizing the filter
    $alphaFilter = '';

    // If the image doesn't include an alpha channel itself, apply an additional filter
    // to remove the alpha channel from the blur at the edges
    if (!$image['transparent']) {
      $alphaFilter = <<<EOD
            <feComponentTransfer>
                <feFuncA type="discrete" tableValues="1 1"></feFuncA>
            </feComponentTransfer>
            EOD;
    }
    // Wrap the blurred image in a SVG to avoid rasterizing the filter
    $svg = <<<EOD
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$svgWidth} {$svgHeight}">
          <filter id="b" color-interpolation-filters="sRGB">
            <feGaussianBlur stdDeviation="{$options['blurRadius']}"></feGaussianBlur>
            {$alphaFilter}
          </filter>
          <image filter="url(#b)" x="0" y="0" width="100%" height="100%" href="{$image['uri']}"></image>
        </svg>
        EOD;

    return $svg;
  }

  /**
   * Returns a decoded BlurHash as a URI-encoded SVG with blur filter applied.
   */
  public static function uri(array $image, array $options = []): string
  {
    $uri = $image['uri'];
    $options['blurRadius'] ??= App::instance()->option('tobimori.thumbhash.blurRadius') ?? 1;

    if ($options['blurRadius'] !== 0) {
      $svg = self::svgFilter($image, $options);
      $uri = self::svgToUri($svg);
    }

    return $uri;
  }


  /**
   * Returns the uuid for a File, or its mediaHash for Assets.
   */
  private static function getId(Asset|File $file): string
  {
    if ($file instanceof Asset) {
      return $file->mediaHash();
    }

    return $file->uuid()?->id() ?? $file->id();
  }
}
