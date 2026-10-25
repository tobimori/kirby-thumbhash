<?php

namespace tobimori\Tests;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use tobimori\Codec;
use tobimori\Tests\Reference\Reference;

class CodecTest extends TestCase
{
	#[DataProviderExternal(Images::class, 'cases')]
	public function testEncode(int $w, int $h, string $color, string $alpha, int $seed): void
	{
		$pixels = Images::create($w, $h, $color, $alpha, $seed);
		$hash = Reference::encode($w, $h, $pixels);

		// Quantization hides most changes to the floating-point operations, so compare the unquantized DCT terms too
		$this->assertSame(self::floats(Reference::dct($w, $h, $pixels)), self::floats(Codec::dct($w, $h, $pixels)));

		$this->assertSame($hash, Codec::encode($w, $h, $pixels));
		$this->assertSame(Reference::toString($hash), Codec::toString($hash));
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProviderExternal(Hashes::class, 'cases')]
	public function testDecode(array $hash): void
	{
		$expected = Reference::decode($hash);
		$image = Codec::decode($hash);

		$this->assertSame(self::floats(Reference::rgba($hash)), self::floats(['w' => $image['w'], 'h' => $image['h'], 'rgba' => $image['rgba']]));
		$this->assertSame($expected['transparent'], $image['transparent']);

		$this->assertSame($expected['width'], $image['w']);
		$this->assertSame($expected['height'], $image['h']);
		$this->assertSame($expected['uri'], Codec::pngDataUrl($image['w'], $image['h'], $image['rgba']));
	}

	/**
	 * @param list<int> $hash
	 */
	#[DataProviderExternal(Hashes::class, 'cases')]
	public function testMetadata(array $hash): void
	{
		$this->assertSame(self::floats(Reference::averageRgba($hash)), self::floats(Codec::averageRgba($hash)));
		$this->assertSame(Reference::aspectRatio($hash), Codec::aspectRatio($hash));

		$string = Reference::toString($hash);
		$this->assertSame($string, Codec::toString($hash));
		$this->assertSame(Reference::fromString($string), Codec::fromString($string));
	}

	/**
	 * Casts all numbers to floats, so assertSame() ignores int and float differences of equal values
	 */
	private static function floats(mixed $value): mixed
	{
		return match (true) {
			is_array($value) => array_map(self::floats(...), $value),
			is_int($value) => (float)$value,
			default => $value
		};
	}
}
