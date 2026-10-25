<?php

namespace tobimori\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use tobimori\Codec;

/**
 * Compares the codec with the output of the original JavaScript implementation in tests/fixtures/expected.json
 */
class CodecTest extends TestCase
{
	/**
	 * @return iterable<string, array{array{pixels: string, width: int, height: int, hash: string, decoded: array{width: int, height: int, rgba: string}, dataUrl: string, averageRgba: array<string, float|int>, aspectRatio: float|int}}>
	 */
	public static function images(): iterable
	{
		foreach (Fixtures::expected()['images'] as $name => $expected) {
			yield $name => [$expected];
		}
	}

	/**
	 * @param array{pixels: string, width: int, height: int, hash: string, decoded: array{width: int, height: int, rgba: string}, dataUrl: string, averageRgba: array<string, float|int>, aspectRatio: float|int} $expected
	 */
	#[DataProvider('images')]
	public function testImage(array $expected): void
	{
		$hash = Codec::encode($expected['width'], $expected['height'], Fixtures::pixels($expected['pixels']));
		$this->assertSame($expected['hash'], Codec::toString($hash));

		$image = Codec::decode($hash);
		$this->assertSame([$expected['decoded']['width'], $expected['decoded']['height']], [$image['w'], $image['h']]);
		$this->assertSame($expected['decoded']['rgba'], self::rgbaHash($image['rgba']));
		$this->assertSame($expected['dataUrl'], Codec::pngDataUrl($image['w'], $image['h'], $image['rgba']));
		$this->assertSame(min(array_column(array_chunk(array_map(intval(...), $image['rgba']), 4), 3)) < 255, $image['transparent']);

		$this->assertSame(array_map(floatval(...), $expected['averageRgba']), array_map(floatval(...), Codec::averageRgba($hash)));
		$this->assertSame((float)$expected['aspectRatio'], Codec::aspectRatio($hash));
		$this->assertSame($hash, Codec::fromString($expected['hash']));
	}

	/**
	 * A different order of floating-point operations changes the output only for some inputs, so many crops are checked
	 */
	public function testCrops(): void
	{
		$expected = Fixtures::expected();
		$pixels = [];
		foreach ($expected['crops'] as $crop) {
			$source = $expected['images'][$crop['image']];
			$pixels[$crop['image']] ??= Fixtures::pixels($source['pixels']);
			$rgba = [];
			for ($row = 0; $row < $crop['h']; $row++) {
				array_push($rgba, ...array_slice($pixels[$crop['image']], ((($crop['y'] + $row) * $source['width']) + $crop['x']) * 4, $crop['w'] * 4));
			}

			$name = "{$crop['image']} {$crop['w']}x{$crop['h']}+{$crop['x']}+{$crop['y']}";
			$hash = Codec::encode($crop['w'], $crop['h'], $rgba);
			$this->assertSame($crop['hash'], Codec::toString($hash), $name);
			$this->assertSame($crop['decoded'], self::rgbaHash(Codec::decode($hash)['rgba']), $name);
			$this->assertSame(array_map(floatval(...), $crop['averageRgba']), array_map(floatval(...), Codec::averageRgba($hash)), $name);
			$this->assertSame((float)$crop['aspectRatio'], Codec::aspectRatio($hash), $name);
		}
	}

	/**
	 * The JavaScript implementation stores the decoded pixels in a Uint8Array, which truncates them
	 *
	 * @param list<float|int> $rgba
	 */
	private static function rgbaHash(array $rgba): string
	{
		return sha1(pack('C*', ...array_map(intval(...), $rgba)));
	}
}
