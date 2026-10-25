<?php

namespace tobimori\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\F;
use PHPUnit\Framework\Attributes\DataProvider;
use tobimori\Codec;
use tobimori\ThumbHash;

class ThumbHashTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function images(): iterable
	{
		foreach (self::engines() as $engine => [$name]) {
			foreach (array_keys(Fixtures::IMAGES) as $image) {
				yield "{$image} {$engine}" => [$image, $name];
			}
		}
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function engines(): iterable
	{
		yield 'gd' => ['gd'];
		if (extension_loaded('imagick')) {
			yield 'imagick' => ['imagick'];
		}
	}

	#[DataProvider('images')]
	public function testFileMethods(string $image, string $engine): void
	{
		$this->options(['tobimori.thumbhash.engine' => $engine]);
		$file = self::file($image);

		$hash = $file->thumbhash();

		$this->assertIsString($hash);
		$this->assertSame($hash, $file->th());
		$media = App::instance()->root('media');
		$this->assertDirectoryDoesNotExist("{$media}/pages");
		$this->assertSame(Fixtures::IMAGES[$image], $file->hasTransparency());

		$image = ThumbHash::decode($hash);
		$this->assertSame(LegacySvg::uri($image, 1.0), $file->thumbhashUri());
		$this->assertSame(LegacySvg::uri($image, 2.5), $file->thUri(null, 2.5));
		$this->assertSame($image['uri'], $file->thumbhashUri(null, 0));

		$rgba = $file->averageColorRgba();
		$this->assertNotNull($rgba);
		['r' => $r, 'g' => $g, 'b' => $b, 'a' => $a] = $rgba;
		$this->assertSame(sprintf('#%02X%02X%02X%02X', $r, $g, $b, round($a * 255)), $file->averageColor());
		$this->assertSame("rgb({$r} {$g} {$b} / {$a})", $file->averageColor('rgb'));
		$this->assertSame("rgba({$r}, {$g}, {$b}, {$a})", $file->averageColor('rgba'));
	}

	public function testRatio(): void
	{
		$file = self::file('earthrise.jpg');

		$square = $file->thumbhash(1.0);

		$this->assertSame(1.0, Codec::aspectRatio(Codec::fromString((string)$square)));
		$this->assertNotSame($file->thumbhash(), $square);
		$this->assertSame($square, $file->thumbhash(['ratio' => 1.0]));
	}

	public function testCache(): void
	{
		$this->options(['tobimori.thumbhash.cache.encode' => true]);
		$file = self::file('earthrise.jpg');
		$hash = $file->thumbhash(1.0);

		// The cache key has the ratio, and a new File object can't use the in-memory hashes of the first one
		F::copy(Fixtures::ROOT . '/rose.png', (string)$file->root(), true);
		$this->assertSame($hash, self::file('earthrise.jpg')->thumbhash(1.0));

		ThumbHash::clearCache($file);
		$this->assertNotSame($hash, self::file('earthrise.jpg')->thumbhash(1.0));
	}

	public function testUriWithOtherData(): void
	{
		foreach (["data:image/svg+xml,<svg> <g>\n\t</g>  </svg>", 'https://example.com/a b%20c?d=e&f=g#h', "\u{1F600} ä ", ''] as $uri) {
			foreach ([true, false] as $transparent) {
				$image = ['uri' => $uri, 'width' => 32, 'height' => 17, 'transparent' => $transparent];
				$this->assertSame(LegacySvg::uri($image, 1.5), ThumbHash::uri($image, 1.5));
			}
		}
	}

	/**
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function sampleSizes(): iterable
	{
		foreach (self::engines() as $engine => [$name]) {
			yield "{$engine} panorama at 100" => [$name, 'panorama.jpg', 100];
			yield "{$engine} earthrise at 1000" => [$name, 'earthrise.jpg', 1000];
		}
	}

	/**
	 * Samples must not exceed the 100 x 100 pixels that the codec accepts
	 *
	 * @see https://github.com/tobimori/kirby-thumbhash/issues/12
	 */
	#[DataProvider('sampleSizes')]
	public function testMaxSampleSize(string $engine, string $image, int $max): void
	{
		$this->options(['tobimori.thumbhash.engine' => $engine, 'tobimori.thumbhash.sampleMaxSize' => $max]);

		$this->assertIsString(self::file($image)->thumbhash());
	}
}
