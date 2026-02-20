<?php

namespace tobimori;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Asset;
use Thumbhash\Thumbhash as THEncoder;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;

use function is_array;
use function is_string;

class ThumbHash
{
  /**
   * Creates thumb for an image based on the ThumbHash algorithm, returns a data URI with an SVG filter.
   *
   * @param float|null $ratio aspect ratio
   * @param float|null $blurRadius blur radius (default from config)
   * @param array $options @deprecated use $ratio and $blurRadius parameters instead
   */
  public static function thumb(Asset|File $file, float|array|null $ratio = null, float|null $blurRadius = null, array $options = []): ?string
  {
    // backwards compatibility: if $ratio is array and $blurRadius is null and $options is empty, treat $ratio as options
    if (is_array($ratio) && $blurRadius === null && empty($options)) {
      $options = $ratio;
      $ratio = $options['ratio'] ?? null;
      $blurRadius = $options['blurRadius'] ?? null;
    }

    // if $options has values, use them (for named params)
    if (isset($options['ratio'])) {
      $ratio = $options['ratio'];
    }
    if (isset($options['blurRadius'])) {
      $blurRadius = $options['blurRadius'];
    }

    $hash = self::encode($file, $ratio);
    if ($hash === null) {
      return null;
    }

    $rgba = self::decode($hash);

    return self::uri($rgba, $blurRadius);
  }

  /**
   * Returns the ThumbHash for a Kirby file object.
   *
   * @param float|null $ratio aspect ratio
   * @param array $options @deprecated use $ratio parameter instead
   */
  public static function encode(Asset|File $file, float|array|null $ratio = null, array $options = []): ?string
  {
    $kirby = App::instance();

    // backwards compatibility: if $ratio is array and $options is empty, treat $ratio as options
    if (is_array($ratio) && empty($options)) {
      $options = $ratio;
      $ratio = $options['ratio'] ?? null;
    }

    // if $options has ratio, use it (for named params)
    if (isset($options['ratio'])) {
      $ratio = $options['ratio'];
    }

    $ratio ??= $file->ratio();
    $id = self::getId($file);
    $ratioKey = (string) $ratio;
    $cache = $kirby->cache('tobimori.thumbhash.encode');
    $cacheData = $cache->get($id);

    if (is_array($cacheData) && isset($cacheData[$ratioKey])) {
      return $cacheData[$ratioKey];
    }

    // backwards compat: migrate old string cache entries
    if (is_string($cacheData)) {
      $cacheData = [$file->ratio() => $cacheData];
    }

    // generate a sample image for encode to avoid memory issues.
    $max = $kirby->option('tobimori.thumbhash.sampleMaxSize');

    $expectedHeight = round($ratio < 1 ? $max : $max / $ratio);
    $expectedWidth = round($ratio >= 1 ? $max : $max * $ratio);
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
      $thumb->delete(); // do not keep thumbs with wrong w/h. remove the thumb and try again next time

      if ($kirby->option('debug')) {
        throw new Exception("[ThumbHash] Failed to generate thumbhash for {$file->filename()}: Image could not be resized to expected sample dimensions, will retry");
      }

      return null;
    }

    // extract pixels using the configured driver
    $engine = $kirby->option('tobimori.thumbhash.engine') ?? 'gd';
    $content = $thumb->read();

    try {
      [$width, $height, $pixels] = match ($engine) {
        'imagick' => ImagickDriver::isAvailable()
          ? ImagickDriver::extractPixels($content)
          : throw new Exception("Imagick extension is not available"),
        'gd' => GdDriver::isAvailable()
          ? GdDriver::extractPixels($content)
          : throw new Exception("GD extension is not available"),
        default => GdDriver::isAvailable()
          ? GdDriver::extractPixels($content)
          : throw new Exception("GD extension is not available"),
      };
    } catch (\Exception $e) {
      if ($kirby->option('debug')) {
        throw new Exception("[ThumbHash] Failed to extract pixels from {$file->filename()} using {$engine} driver: {$e->getMessage()}");
      }
      return null;
    }

    $hashArray = THEncoder::RGBAToHash($width, $height, $pixels);
    $thumbhash = THEncoder::convertHashToString($hashArray);

    $cacheData = is_array($cacheData) ? $cacheData : [];
    $cacheData[$ratioKey] = $thumbhash;
    $cache->set($id, $cacheData);

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
   * Returns average color from a file's thumbhash as RGBA array
   *
   * @param float|null $ratio aspect ratio
   */
  public static function averageColorRgba(Asset|File $file, float|null $ratio = null): ?array
  {
    $hash = self::encode($file, $ratio);
    if ($hash === null) {
      return null;
    }

    $thumbhash = THEncoder::convertStringToHash($hash);
    $encoder = new THEncoder();
    $rgba = $encoder->toAverageRGBA($thumbhash);

    return [
      'r' => (int) round($rgba['r'] * 255),
      'g' => (int) round($rgba['g'] * 255),
      'b' => (int) round($rgba['b'] * 255),
      'a' => round($rgba['a'], 2),
    ];
  }

  /**
   * Returns average color from a file's thumbhash in CSS format
   *
   * @param string $format 'hex', 'rgb' (modern syntax with alpha), or 'rgba' (legacy syntax)
   * @param float|null $ratio aspect ratio
   */
  public static function averageColor(Asset|File $file, string $format = 'hex', float|null $ratio = null): ?string
  {
    $rgba = self::averageColorRgba($file, $ratio);

    if ($rgba === null) {
      return null;
    }

    return match ($format) {
      'rgb' => "rgb({$rgba['r']} {$rgba['g']} {$rgba['b']} / {$rgba['a']})",
      'rgba' => "rgba({$rgba['r']}, {$rgba['g']}, {$rgba['b']}, {$rgba['a']})",
      'hex' => sprintf('#%02X%02X%02X%02X', $rgba['r'], $rgba['g'], $rgba['b'], (int) round($rgba['a'] * 255)),
      default => null,
    };
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
  private static function svgFilter(array $image, float $blurRadius): string
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
            <feGaussianBlur stdDeviation="{$blurRadius}"></feGaussianBlur>
            {$alphaFilter}
          </filter>
          <image filter="url(#b)" x="0" y="0" width="100%" height="100%" href="{$image['uri']}"></image>
        </svg>
        EOD;

    return $svg;
  }

  /**
   * Returns a decoded BlurHash as a URI-encoded SVG with blur filter applied.
   *
   * @param float|null $blurRadius blur radius (default from config)
   * @param array $options @deprecated use $blurRadius parameter instead
   */
  public static function uri(array $image, float|array|null $blurRadius = null, array $options = []): string
  {
    // backwards compatibility: if $blurRadius is array and $options is empty, treat $blurRadius as options
    if (is_array($blurRadius) && empty($options)) {
      $options = $blurRadius;
      $blurRadius = $options['blurRadius'] ?? null;
    }

    // if $options has blurRadius, use it (for named params: options: ['blurRadius' => 2])
    if (isset($options['blurRadius'])) {
      $blurRadius = $options['blurRadius'];
    }

    $blurRadius ??= App::instance()->option('tobimori.thumbhash.blurRadius') ?? 1;

    if ($blurRadius === 0) {
      return $image['uri'];
    }

    $svg = self::svgFilter($image, $blurRadius);
    return self::svgToUri($svg);
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
