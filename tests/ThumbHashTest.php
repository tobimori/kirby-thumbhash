<?php

namespace tobimori\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Asset;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use tobimori\Tests\Reference\Reference;
use tobimori\ThumbHash;

class ThumbHashTest extends TestCase
{
	/**
	 * @return iterable<string, array{list<int>}>
	 */
	public static function hashes(): iterable
	{
		foreach (Hashes::all() as $name => $hash) {
			yield $name => [$hash];
		}
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProvider('hashes')]
	public function testDecode(array $hash): void
	{
		$this->assertSame(Reference::decode($hash), ThumbHash::decode(Reference::toString($hash)));
		$this->assertSame(Reference::decode($hash), ThumbHash::decode($hash));
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProvider('hashes')]
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

		// the sample image of the first call is the input of the reference
		$thumb = (string)F::read(glob("{$root}/media/assets/assets/*/image-*.{$extension}")[0] ?? '');
		[$w, $h, $pixels] = Reference::gdPixels($thumb);
		$this->assertSame(Reference::toString(Reference::encode($w, $h, $pixels)), $hash);
		$this->assertSame($hash, ThumbHash::encode($asset));
		$this->assertSame(Reference::decode(Reference::fromString($hash))['transparent'], ThumbHash::hasTransparency($asset));

		ThumbHash::clearCache($asset);
		$this->assertSame($hash, ThumbHash::encode($asset));
	}
}
