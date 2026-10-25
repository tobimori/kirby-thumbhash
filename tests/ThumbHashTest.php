<?php

namespace tobimori\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Asset;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use tobimori\Tests\Reference\Reference;
use tobimori\ThumbHash;

class ThumbHashTest extends TestCase
{
	/**
	 * @param list<int> $hash
	 */
	#[DataProviderExternal(Hashes::class, 'cases')]
	public function testDecode(array $hash): void
	{
		$this->assertSame(Reference::decode($hash), ThumbHash::decode(Reference::toString($hash)));
		$this->assertSame(Reference::decode($hash), ThumbHash::decode($hash));
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProviderExternal(Hashes::class, 'cases')]
	public function testUri(array $hash): void
	{
		$image = Reference::decode($hash);
		foreach ([0.0, 0.5, 1.0, 2.5, 10.0] as $blurRadius) {
			$this->assertSame(Reference::uri($image, $blurRadius), ThumbHash::uri($image, $blurRadius));
		}
		$this->assertSame(Reference::uri($image, 1.0), ThumbHash::uri($image));
	}

	public function testUriWithOtherData(): void
	{
		foreach (["data:image/svg+xml,<svg> <g>\n\t</g>  </svg>", 'https://example.com/a b%20c?d=e&f=g#h', "\u{1F600} ä ", ''] as $uri) {
			foreach ([true, false] as $transparent) {
				$image = ['uri' => $uri, 'width' => 32, 'height' => 17, 'transparent' => $transparent];
				$this->assertSame(Reference::uri($image, 1.5), ThumbHash::uri($image, 1.5));
			}
		}
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function assets(): iterable
	{
		foreach (Images::files() as $name => $content) {
			if (preg_match('/^(100x75|64x100) .+ (jpeg|png)$/', $name) === 1) {
				yield $name => [$content, str_ends_with($name, 'jpeg') ? 'jpg' : 'png'];
			}
		}
	}

	#[DataProvider('assets')]
	public function testEncode(string $content, string $extension): void
	{
		$root = App::instance()->root('index');
		Dir::remove("{$root}/media");
		F::write("{$root}/assets/image.{$extension}", $content);
		$asset = new Asset("assets/image.{$extension}");

		$hash = ThumbHash::encode($asset);

		$this->assertNotNull($hash);
		$this->assertDirectoryDoesNotExist("{$root}/media");
		$this->assertSame($hash, ThumbHash::encode($asset));
		ThumbHash::clearCache($asset);
		$this->assertSame($hash, ThumbHash::encode($asset));

		// The sample is cropped and scaled, so compare only the size and the average color with a hash of the whole image.
		// The hash has 4 bits for the average alpha, so it gets a larger delta.
		$reference = Reference::fromString(Reference::toString(Reference::encode(...Reference::gdPixels($content))));
		$this->assertSame(Reference::decode($reference)['width'], ThumbHash::decode($hash)['width']);
		$this->assertSame(Reference::decode($reference)['height'], ThumbHash::decode($hash)['height']);
		$average = Reference::averageRgba(Reference::fromString($hash));
		foreach (Reference::averageRgba($reference) as $channel => $value) {
			$this->assertEqualsWithDelta($value, $average[$channel], $channel === 'a' ? 0.07 : 0.05, $channel);
		}
	}

	/**
	 * @return iterable<string, array{string, int, int, int}>
	 */
	public static function sampleSizes(): iterable
	{
		foreach (['gd', 'imagick'] as $engine) {
			yield "{$engine} 101x31 at 100" => [$engine, 101, 31, 100];
			yield "{$engine} 303x93 at 1000" => [$engine, 303, 93, 1000];
		}
	}

	/**
	 * Samples must not exceed the 100 x 100 pixels that the codec accepts
	 *
	 * @see https://github.com/tobimori/kirby-thumbhash/issues/12
	 */
	#[DataProvider('sampleSizes')]
	public function testEncodeAtMaxSampleSize(string $engine, int $width, int $height, int $max): void
	{
		if ($engine === 'imagick' && !extension_loaded('imagick')) {
			$this->markTestSkipped('Imagick is not available');
		}

		$kirby = App::instance();
		$root = $kirby->root('index');
		$image = imagecreatetruecolor($width, $height);
		ob_start();
		imagejpeg($image);
		F::write("{$root}/assets/wide.jpg", (string)ob_get_clean());
		$kirby->clone(['options' => [
			'tobimori.thumbhash.engine' => $engine,
			'tobimori.thumbhash.sampleMaxSize' => $max
		]]);

		try {
			$this->assertNotNull(ThumbHash::encode(new Asset('assets/wide.jpg')));
		} finally {
			// A new Kirby app sets its own error handlers
			restore_error_handler();
			restore_exception_handler();
			App::instance($kirby);
		}
	}
}
