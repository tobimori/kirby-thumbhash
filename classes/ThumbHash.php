<?php

namespace tobimori;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\Exception;
use Kirby\Filesystem\Asset;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;
use WeakMap;

use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function min;
use function number_format;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function round;
use function sprintf;
use function str_replace;

class ThumbHash
{
	/**
	 * ThumbHashes of this request per file and ratio, so the cache is read at most once for each
	 *
	 * @var WeakMap<Asset|File, array<string, string>>|null
	 */
	private static WeakMap|null $hashes = null;

	/**
	 * Decoded ThumbHashes of this request
	 *
	 * @var array<string, array{uri: string, width: int, height: int, transparent: bool}>
	 */
	private static array $images = [];

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
		$ratioKey = (string)$ratio;
		$memo = self::$hashes ?? new WeakMap();
		self::$hashes = $memo;
		/** @var array<string, string> $hashes */
		$hashes = $memo[$file] ?? [];
		if (isset($hashes[$ratioKey])) {
			return $hashes[$ratioKey];
		}

		$id = self::getId($file);
		$cache = $kirby->cache('tobimori.thumbhash.encode');
		$cacheData = $cache->get($id);

		// Older cache entries are a string with the hash for the file's own aspect ratio
		if (is_string($cacheData)) {
			$cacheData = [(string)$file->ratio() => $cacheData];
		}

		$cacheData = is_array($cacheData) ? $cacheData : [];
		if (is_string($cacheData[$ratioKey] ?? null)) {
			$hashes[$ratioKey] = $cacheData[$ratioKey];
			$memo[$file] = $hashes;
			return $cacheData[$ratioKey];
		}

		$max = min(100, self::toFloat($kirby->option('tobimori.thumbhash.sampleMaxSize')) ?? 32);
		$width = (int)round($ratio >= 1 ? $max : $max * $ratio);
		$height = (int)round($ratio < 1 ? $max : $max / $ratio);
		$driver = match ($kirby->option('tobimori.thumbhash.engine')) {
			'gd' => GdDriver::class,
			'imagick' => ImagickDriver::class,
			default => ImagickDriver::isAvailable() ? ImagickDriver::class : GdDriver::class
		};

		try {
			if (!$driver::isAvailable()) {
				throw new Exception($driver === ImagickDriver::class ? 'Imagick extension is not available' : 'GD extension is not available');
			}

			[$width, $height, $pixels] = Sampler::sample($file, $driver, $width, $height);
		} catch (\Exception $e) {
			if ($kirby->option('debug')) {
				throw new Exception("[ThumbHash] Failed to sample {$file->filename()}: {$e->getMessage()}");
			}
			return null;
		}

		$thumbhash = Codec::toString(Codec::encode($width, $height, $pixels));

		$cacheData[$ratioKey] = $thumbhash;
		$cache->set($id, $cacheData);
		$hashes[$ratioKey] = $thumbhash;
		$memo[$file] = $hashes;

		return $thumbhash;
	}

	/**
	 * Decodes a ThumbHash to a PNG data URI, its size, and whether it has transparent pixels
	 *
	 * @param string|list<int> $thumbhash
	 * @return array{uri: string, width: int, height: int, transparent: bool}
	 */
	public static function decode(string|array $thumbhash): array
	{
		$id = is_array($thumbhash) ? Codec::toString($thumbhash) : $thumbhash;
		if (isset(self::$images[$id])) {
			return self::$images[$id];
		}

		// Limit the memory use of long-running processes
		if (count(self::$images) >= 500) {
			self::$images = [];
		}

		$cache = App::instance()->cache('tobimori.thumbhash.decode');

		/** @var array{uri: string, width: int, height: int, transparent: bool}|null $cacheData */
		$cacheData = $cache->get($id);
		if ($cacheData !== null) {
			return self::$images[$id] = $cacheData;
		}

		$image = Codec::decode(is_string($thumbhash) ? Codec::fromString($thumbhash) : $thumbhash);
		$data = [
			'uri' => Codec::pngDataUrl($image['w'], $image['h'], $image['rgba']),
			'width' => $image['w'],
			'height' => $image['h'],
			'transparent' => $image['transparent']
		];

		$cache->set($id, $data);
		return self::$images[$id] = $data;
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
	 * Clears the encoding cache of a file
	 */
	public static function clearCache(Asset|File $file): void
	{
		// Other objects of the same file can have memo entries
		self::$hashes = null;
		App::instance()->cache('tobimori.thumbhash.encode')->remove(self::getId($file));
	}

	/**
	 * Returns the image with a blur filter as a URI-encoded SVG, the SVG wraps the blurred image to avoid rasterizing the filter.
	 * Based on https://github.com/johannschopplich/kirby-blurry-placeholder/blob/main/BlurryPlaceholder.php
	 *
	 * @param array{uri: string, width: int, height: int, transparent: bool} $image
	 */
	private static function svgUri(array $image, float $blurRadius): string
	{
		$width = number_format($image['width'], 2, '.', '');
		$height = number_format($image['height'], 2, '.', '');

		// For opaque images, make the blurred edges opaque again
		$alphaFilter = $image['transparent'] ? '' : '<feComponentTransfer><feFuncA type="discrete" tableValues="1 1"></feFuncA></feComponentTransfer>';

		$svg = self::encodeUri(
			"<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$width} {$height}\"><filter id=\"b\" color-interpolation-filters=\"sRGB\">" .
				"<feGaussianBlur stdDeviation=\"{$blurRadius}\"></feGaussianBlur>{$alphaFilter}</filter>" .
				'<image filter="url(#b)" x="0" y="0" width="100%" height="100%" href="'
		);

		// In base64 data URIs, encodeUri() only changes '+', ';', and ','
		$uri = $image['uri'];
		$uri = preg_match('#^[A-Za-z0-9+/=:;,]*$#D', $uri) === 1
			? str_replace(['+', ';', ','], ['%2B', '%3B', '%2C'], $uri)
			: self::encodeUri(preg_replace(['/\s+/', '/> </'], [' ', '><'], $uri) ?? $uri);

		return "data:image/svg+xml;charset=utf-8,{$svg}{$uri}%22%3E%3C/image%3E%3C/svg%3E";
	}

	/**
	 * URI-encodes a string, '/', ':', and '=' stay as they are to improve compression
	 */
	private static function encodeUri(string $data): string
	{
		return str_replace(['%2F', '%3A', '%3D'], ['/', ':', '='], rawurlencode($data));
	}

	/**
	 * Returns a decoded ThumbHash as a blurred SVG data URI, or as its PNG data URI if the blur radius is 0
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

		return self::svgUri($image, $blurRadius);
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
