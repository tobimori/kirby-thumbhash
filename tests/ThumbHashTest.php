<?php

namespace tobimori\Tests;

use DOMDocument;
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
		foreach (self::engines() as $engine) {
			foreach (array_keys(self::IMAGES) as $image) {
				yield "{$image} {$engine}" => [$image, $engine];
			}
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
		$this->assertSame(self::IMAGES[$image], $file->hasTransparency());

		$png = $file->thumbhashUri(null, 0);
		$this->assertStringStartsWith('data:image/png;base64,', (string)$png);
		foreach (['1' => $file->thumbhashUri(), '2.5' => $file->thUri(null, 2.5)] as $blurRadius => $uri) {
			$prefix = 'data:image/svg+xml;charset=utf-8,';
			$this->assertStringStartsWith($prefix, (string)$uri);
			$svg = new DOMDocument();
			$this->assertTrue($svg->loadXML(rawurldecode(substr((string)$uri, strlen($prefix)))));
			$this->assertSame((string)$blurRadius, $svg->getElementsByTagName('feGaussianBlur')->item(0)?->getAttribute('stdDeviation'));
			$this->assertSame(self::IMAGES[$image], $svg->getElementsByTagName('feComponentTransfer')->length === 0);
			$this->assertSame($png, $svg->getElementsByTagName('image')->item(0)?->getAttribute('href'));
		}

		[$hexAlpha, $alpha] = self::IMAGES[$image] ? ['(?!FF)[0-9A-F]{2}', '0\.\d+'] : ['FF', '1'];
		$this->assertMatchesRegularExpression("/^#[0-9A-F]{6}{$hexAlpha}$/", (string)$file->averageColor());
		$this->assertMatchesRegularExpression("#^rgb\\(\\d+ \\d+ \\d+ / {$alpha}\\)$#", (string)$file->averageColor('rgb'));
		$this->assertMatchesRegularExpression("/^rgba\\(\\d+, \\d+, \\d+, {$alpha}\\)$/", (string)$file->averageColor('rgba'));
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
		F::copy(self::FIXTURES . '/rose.png', (string)$file->root(), true);
		$this->assertSame($hash, self::file('earthrise.jpg')->thumbhash(1.0));

		ThumbHash::clearCache($file);
		$this->assertNotSame($hash, self::file('earthrise.jpg')->thumbhash(1.0));
	}

	/**
	 * The SVG must stay the same as in version 2
	 */
	public function testUri(): void
	{
		$image = ['uri' => 'data:image/png;base64,iVBO+/w==', 'width' => 32, 'height' => 21, 'transparent' => false];

		$this->assertSame(
			'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20viewBox=%220%200%2032.00%2021.00%22%3E%3Cfilter%20id=%22b%22%20color-interpolation-filters=%22sRGB%22%3E%3CfeGaussianBlur%20stdDeviation=%221.5%22%3E%3C/feGaussianBlur%3E%3CfeComponentTransfer%3E%3CfeFuncA%20type=%22discrete%22%20tableValues=%221%201%22%3E%3C/feFuncA%3E%3C/feComponentTransfer%3E%3C/filter%3E%3Cimage%20filter=%22url%28%23b%29%22%20x=%220%22%20y=%220%22%20width=%22100%25%22%20height=%22100%25%22%20href=%22data:image/png%3Bbase64%2CiVBO%2B/w==%22%3E%3C/image%3E%3C/svg%3E',
			ThumbHash::uri($image, 1.5)
		);
		$this->assertSame(
			'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20viewBox=%220%200%2032.00%2021.00%22%3E%3Cfilter%20id=%22b%22%20color-interpolation-filters=%22sRGB%22%3E%3CfeGaussianBlur%20stdDeviation=%221.5%22%3E%3C/feGaussianBlur%3E%3C/filter%3E%3Cimage%20filter=%22url%28%23b%29%22%20x=%220%22%20y=%220%22%20width=%22100%25%22%20height=%22100%25%22%20href=%22data:image/png%3Bbase64%2CiVBO%2B/w==%22%3E%3C/image%3E%3C/svg%3E',
			ThumbHash::uri([...$image, 'transparent' => true], 1.5)
		);
		$this->assertSame($image['uri'], ThumbHash::uri($image, 0));
	}

	/**
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function sampleSizes(): iterable
	{
		foreach (self::engines() as $engine) {
			yield "{$engine} panorama at 100" => [$engine, 'panorama.jpg', 100];
			yield "{$engine} earthrise at 1000" => [$engine, 'earthrise.jpg', 1000];
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

	/**
	 * @return list<string>
	 */
	private static function engines(): array
	{
		return extension_loaded('imagick') ? ['gd', 'imagick'] : ['gd'];
	}
}
