<?php

namespace tobimori\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use tobimori\Codec;

/**
 * Compares the codec with the original JavaScript implementation, https://github.com/evanw/thumbhash (npm thumbhash 0.1.1).
 * tests/fixtures/codec.json holds the pixels of each image, scaled down to at most 100 x 100, and the JavaScript output for
 * the full image and for centered crops of every 6th width and height. A different order of floating-point operations
 * changes the output for only some inputs, so the crops give many inputs. Crops with an average alpha of 0 are left out:
 * their placeholder is invisible, and the codec adds the DCT terms in another order, so their rounding noise differs.
 *
 * @phpstan-type Fixtures array<string, array{width: int, height: int, rgba: string, cases: list<array{x: int, y: int, w: int, h: int, hash: string, png: string, average: array{r: float|int, g: float|int, b: float|int, a: float|int}, ratio: float|int}>}>
 */
class CodecTest extends TestCase
{
	/**
	 * @var Fixtures|null
	 */
	private static array|null $fixtures = null;

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function images(): iterable
	{
		foreach (array_keys(self::fixtures()) as $image) {
			yield $image => [$image];
		}
	}

	#[DataProvider('images')]
	public function testMatchesJavaScript(string $image): void
	{
		['width' => $width, 'rgba' => $rgba, 'cases' => $cases] = self::fixtures()[$image];
		/** @var list<int> $pixels */
		$pixels = array_values((array)unpack('C*', (string)base64_decode($rgba, true)));

		foreach ($cases as $expected) {
			['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h] = $expected;
			$message = "{$image} cropped to {$w}x{$h}+{$x}+{$y}";
			$crop = [];
			for ($row = $y; $row < ($y + $h); $row++) {
				array_push($crop, ...array_slice($pixels, (($row * $width) + $x) * 4, $w * 4));
			}

			$hash = Codec::encode($w, $h, $crop);
			$this->assertSame($expected['hash'], Codec::toString($hash), $message);
			$this->assertSame($hash, Codec::fromString($expected['hash']), $message);

			$decoded = Codec::decode($hash);
			$this->assertSame($expected['png'], sha1(Codec::pngDataUrl($decoded['w'], $decoded['h'], $decoded['rgba'])), $message);
			$this->assertSame(min(array_map(intval(...), array_column(array_chunk($decoded['rgba'], 4), 3))) < 255, $decoded['transparent'], $message);

			$this->assertSame(array_map(floatval(...), $expected['average']), array_map(floatval(...), Codec::averageRgba($hash)), $message);
			$this->assertSame((float)$expected['ratio'], Codec::aspectRatio($hash), $message);
		}
	}

	/**
	 * @return Fixtures
	 */
	private static function fixtures(): array
	{
		if (self::$fixtures === null) {
			/** @var Fixtures $fixtures */
			$fixtures = json_decode((string)file_get_contents(__DIR__ . '/fixtures/codec.json'), true, flags: JSON_THROW_ON_ERROR);
			self::$fixtures = $fixtures;
		}

		return self::$fixtures;
	}
}
