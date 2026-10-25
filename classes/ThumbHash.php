<?php

namespace tobimori;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\FileVersion;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Asset;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;

use function is_array;
use function is_string;

class ThumbHash
{
	/**
	 * Creates thumb for an image based on the ThumbHash algorithm, returns a data URI with an SVG filter.
	 *
	 * @param float|array|null $ratio aspect ratio, an options array is deprecated
	 * @param float|null $blurRadius blur radius (default from config)
	 * @param array $options @deprecated use $ratio and $blurRadius parameters instead
	 */
	public static function thumb(Asset|File $file, float|array|null $ratio = null, float|null $blurRadius = null, array $options = []): string|null
	{
		if ($file->isResizable() === false) {
			return null;
		}

		// backwards compatibility: treat an array $ratio as options
		if (is_array($ratio)) {
			$options = [...$ratio, ...$options];
			$ratio = null;
		}

		$ratio = self::toFloat($options['ratio'] ?? null) ?? $ratio;
		$blurRadius = self::toFloat($options['blurRadius'] ?? null) ?? $blurRadius;

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
	 * @param float|array|null $ratio aspect ratio, an options array is deprecated
	 * @param array $options @deprecated use $ratio parameter instead
	 */
	public static function encode(Asset|File $file, float|array|null $ratio = null, array $options = []): string|null
	{
		if ($file->isResizable() === false) {
			return null;
		}

		$kirby = App::instance();

		// backwards compatibility: treat an array $ratio as options
		if (is_array($ratio)) {
			$options = [...$ratio, ...$options];
			$ratio = null;
		}

		$ratio = self::toFloat($options['ratio'] ?? null) ?? $ratio ?? $file->ratio();
		$id = self::getId($file);
		$ratioKey = (string)$ratio;
		$cache = $kirby->cache('tobimori.thumbhash.encode');
		$cacheData = $cache->get($id);

		// backwards compat: migrate old string cache entries
		if (is_string($cacheData)) {
			$cacheData = [(string)$file->ratio() => $cacheData];
		}

		$cacheData = is_array($cacheData) ? $cacheData : [];
		if (is_string($cacheData[$ratioKey] ?? null)) {
			return $cacheData[$ratioKey];
		}

		// generate a sample image for encode to avoid memory issues.
		$max = self::toFloat($kirby->option('tobimori.thumbhash.sampleMaxSize')) ?? 100;

		$expectedHeight = round($ratio < 1 ? $max : $max / $ratio);
		$expectedWidth = round($ratio >= 1 ? $max : $max * $ratio);
		$thumbOptions = [
			'width' => $expectedWidth,
			'height' => $expectedHeight,
			'crop' => true,
			'quality' => 70
		];

		$thumb = $file->thumb($thumbOptions);

		// thumb() returns the original file for non-resizable types (svg, etc.)
		// or when the job file can't be created: only proceed with actual thumbnails
		// https://github.com/tobimori/kirby-thumbhash/issues/10
		if (!$thumb instanceof FileVersion) {
			return null;
		}

		$actualWidth = $thumb->width();
		$actualHeight = $thumb->height();

		// check if dimensions differ by more than 10px
		if (abs($actualWidth - $expectedWidth) > 10 || abs($actualHeight - $expectedHeight) > 10) {
			$thumb->delete(); // do not keep thumbs with wrong w/h. remove the thumb and try again next time

			if ($kirby->option('debug')) {
				throw new Exception(
					"[ThumbHash] Failed to generate thumbhash for {$file->filename()}: Image could not be resized to expected sample dimensions, will retry"
				);
			}

			return null;
		}

		// extract pixels using the configured driver
		$engine = $kirby->option('tobimori.thumbhash.engine') === 'imagick' ? 'imagick' : 'gd';

		try {
			$content = $thumb->read() ?: throw new Exception('Unable to read the sample image');

			[$width, $height, $pixels] = match ($engine) {
				'imagick' => ImagickDriver::isAvailable()
					? ImagickDriver::extractPixels($content)
					: throw new Exception('Imagick extension is not available'),
				'gd' => GdDriver::isAvailable()
					? GdDriver::extractPixels($content)
					: throw new Exception('GD extension is not available')
			};
		} catch (\Exception $e) {
			if ($kirby->option('debug')) {
				throw new Exception("[ThumbHash] Failed to extract pixels from {$file->filename()} using {$engine} driver: {$e->getMessage()}");
			}
			return null;
		}

		$thumbhash = Codec::toString(Codec::encode($width, $height, $pixels));

		$cacheData[$ratioKey] = $thumbhash;
		$cache->set($id, $cacheData);

		return $thumbhash;
	}

	/**
	 * Decodes a ThumbHash string or array to an array of RGBA values, and width & height
	 *
	 * @param string|list<int> $thumbhash
	 * @return array{uri: string, width: int, height: int, transparent: bool}
	 */
	public static function decode(string|array $thumbhash): array
	{
		$kirby = App::instance();
		$cache = $kirby->cache('tobimori.thumbhash.decode');

		$id = is_array($thumbhash) ? Codec::toString($thumbhash) : $thumbhash;
		$thumbhash = is_string($thumbhash) ? Codec::fromString($thumbhash) : $thumbhash;

		/** @var array{uri: string, width: int, height: int, transparent: bool}|null $cacheData */
		$cacheData = $cache->get($id);
		if ($cacheData !== null) {
			return $cacheData;
		}

		$image = Codec::decode($thumbhash);
		// check if any alpha value in RGBA array is less than 255
		$transparent = min(array_column(array_chunk($image['rgba'], 4), 3)) < 255;

		$dataUri = Codec::pngDataUrl($image['w'], $image['h'], $image['rgba']);

		$data = [
			'uri' => $dataUri,
			'width' => $image['w'],
			'height' => $image['h'],
			'transparent' => $transparent
		];

		$cache->set($id, $data);
		return $data;
	}

	/**
	 * Returns average color from a file's thumbhash as RGBA array
	 *
	 * @param float|null $ratio aspect ratio
	 * @return array{r: int, g: int, b: int, a: float}|null
	 */
	public static function averageColorRgba(Asset|File $file, float|null $ratio = null): array|null
	{
		$hash = self::encode($file, $ratio);
		if ($hash === null) {
			return null;
		}

		$rgba = Codec::averageRgba(Codec::fromString($hash));

		return [
			'r' => (int)round($rgba['r'] * 255),
			'g' => (int)round($rgba['g'] * 255),
			'b' => (int)round($rgba['b'] * 255),
			'a' => round($rgba['a'], 2)
		];
	}

	/**
	 * Returns average color from a file's thumbhash in CSS format
	 *
	 * @param string $format 'hex', 'rgb' (modern syntax with alpha), or 'rgba' (legacy syntax)
	 * @param float|null $ratio aspect ratio
	 */
	public static function averageColor(Asset|File $file, string $format = 'hex', float|null $ratio = null): string|null
	{
		$rgba = self::averageColorRgba($file, $ratio);

		if ($rgba === null) {
			return null;
		}

		return match ($format) {
			'rgb' => "rgb({$rgba['r']} {$rgba['g']} {$rgba['b']} / {$rgba['a']})",
			'rgba' => "rgba({$rgba['r']}, {$rgba['g']}, {$rgba['b']}, {$rgba['a']})",
			'hex' => sprintf('#%02X%02X%02X%02X', $rgba['r'], $rgba['g'], $rgba['b'], (int)round($rgba['a'] * 255)),
			default => null
		};
	}

	/**
	 * Returns whether a file's thumbhash has transparent pixels
	 *
	 * @param float|null $ratio aspect ratio
	 */
	public static function hasTransparency(Asset|File $file, float|null $ratio = null): bool
	{
		$hash = self::encode($file, $ratio);
		return $hash !== null && self::decode($hash)['transparent'];
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
		$data = preg_replace(['/\s+/', '/> </'], [' ', '><'], $data) ?? $data;

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
	 *
	 * @param array{uri: string, width: int, height: int, transparent: bool} $image
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
		return <<<EOD
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$svgWidth} {$svgHeight}">
			  <filter id="b" color-interpolation-filters="sRGB">
			    <feGaussianBlur stdDeviation="{$blurRadius}"></feGaussianBlur>
			    {$alphaFilter}
			  </filter>
			  <image filter="url(#b)" x="0" y="0" width="100%" height="100%" href="{$image['uri']}"></image>
			</svg>
			EOD;
	}

	/**
	 * Returns a decoded BlurHash as a URI-encoded SVG with blur filter applied.
	 *
	 * @param array{uri: string, width: int, height: int, transparent: bool} $image
	 * @param float|array|null $blurRadius blur radius (default from config), an options array is deprecated
	 * @param array $options @deprecated use $blurRadius parameter instead
	 */
	public static function uri(array $image, float|array|null $blurRadius = null, array $options = []): string
	{
		// backwards compatibility: treat an array $blurRadius as options
		if (is_array($blurRadius)) {
			$options = [...$blurRadius, ...$options];
			$blurRadius = null;
		}

		$blurRadius = self::toFloat($options['blurRadius'] ?? null) ?? $blurRadius;
		$blurRadius ??= self::toFloat(App::instance()->option('tobimori.thumbhash.blurRadius')) ?? 1.0;

		if ($blurRadius === 0.0) {
			return $image['uri'];
		}

		$svg = self::svgFilter($image, $blurRadius);
		return self::svgToUri($svg);
	}

	private static function toFloat(mixed $value): float|null
	{
		return is_numeric($value) ? (float)$value : null;
	}

	/**
	 * Returns the uuid for a File, or its mediaHash for Assets.
	 */
	private static function getId(Asset|File $file): string
	{
		if ($file instanceof Asset) {
			return $file->mediaHash();
		}

		// Kirby documents a non-null uuid(), but it returns null when UUIDs are disabled
		// @mago-expect analysis:redundant-nullsafe-operator
		// @mago-expect analysis:redundant-null-coalesce
		return $file->uuid()?->id() ?? $file->id();
	}
}
