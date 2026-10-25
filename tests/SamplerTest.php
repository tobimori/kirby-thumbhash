<?php

namespace tobimori\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;
use tobimori\Sampler;

class SamplerTest extends TestCase
{
	/**
	 * @return iterable<string, array{string}>
	 */
	public static function images(): iterable
	{
		foreach (array_keys(self::IMAGES) as $image) {
			yield $image => [$image];
		}
	}

	/**
	 * GD and Imagick read and scale the images independently
	 */
	#[DataProvider('images')]
	#[RequiresPhpExtension('imagick')]
	public function testDriversAgree(string $image): void
	{
		$file = self::file($image);
		[$width, $height] = self::sampleSize($file->ratio());

		$gd = Sampler::sample($file, GdDriver::class, $width, $height);
		$imagick = Sampler::sample($file, ImagickDriver::class, $width, $height);

		$this->assertSame([$gd[0], $gd[1]], [$imagick[0], $imagick[1]]);
		$this->assertLessThan(3, self::difference($gd[2], $imagick[2]));
	}

	/**
	 * @return iterable<string, array{class-string<GdDriver>|class-string<ImagickDriver>}>
	 */
	public static function drivers(): iterable
	{
		yield 'gd' => [GdDriver::class];
		if (extension_loaded('imagick')) {
			yield 'imagick' => [ImagickDriver::class];
		}
	}

	/**
	 * @param class-string<GdDriver>|class-string<ImagickDriver> $driver
	 */
	#[DataProvider('drivers')]
	public function testOrientation(string $driver): void
	{
		$upright = Sampler::sample(self::file('earthrise.jpg'), $driver, 32, 21);
		$rotated = Sampler::sample(self::file('earthrise-rotated.jpg'), $driver, 32, 21);

		$this->assertSame([32, 21], [$rotated[0], $rotated[1]]);
		$this->assertLessThan(3, self::difference($upright[2], $rotated[2]));
	}

	/**
	 * @param class-string<GdDriver>|class-string<ImagickDriver> $driver
	 */
	#[DataProvider('drivers')]
	public function testCmyk(string $driver): void
	{
		$rgb = Sampler::sample(self::file('earthrise.jpg'), $driver, 32, 21);
		$cmyk = Sampler::sample(self::file('earthrise-cmyk.jpg'), $driver, 32, 21);

		$this->assertLessThan(5, self::difference($rgb[2], $cmyk[2]));
	}

	/**
	 * @param class-string<GdDriver>|class-string<ImagickDriver> $driver
	 */
	#[DataProvider('drivers')]
	public function testFocus(string $driver): void
	{
		$source = imagecreatefromjpeg(self::FIXTURES . '/earthrise.jpg') ?: null;
		$this->assertNotNull($source);

		foreach (['0% 50%' => 0, '100% 50%' => 400] as $focus => $left) {
			$square = imagecrop($source, ['x' => $left, 'y' => 0, 'width' => 800, 'height' => 800]) ?: null;
			$this->assertNotNull($square);
			ob_start();
			imagepng($square);
			$expected = Sampler::sample(self::asset('square.png', (string)ob_get_clean()), $driver, 32, 32);

			$sample = Sampler::sample(self::file('earthrise.jpg', ['focus' => $focus]), $driver, 32, 32);

			$this->assertLessThan(3, self::difference($expected[2], $sample[2]), $focus);
		}
	}

	public function testDoesNotScaleUp(): void
	{
		$sample = Sampler::sample(self::file('rose.png'), GdDriver::class, 140, 92);

		$this->assertSame([70, 46], [$sample[0], $sample[1]]);
	}

	/**
	 * @return array{int, int}
	 */
	private static function sampleSize(float $ratio): array
	{
		return $ratio >= 1 ? [32, (int)round(32 / $ratio)] : [(int)round(32 * $ratio), 32];
	}

	/**
	 * Root mean square difference of the pixels, with the colors premultiplied by alpha
	 *
	 * @param list<int> $expected
	 * @param list<int> $actual
	 */
	private static function difference(array $expected, array $actual): float
	{
		$sum = 0;
		foreach ($expected as $i => $value) {
			$alpha = $i - ($i % 4) + 3;
			$sum += ($i % 4) === 3
				? ($value - $actual[$i]) ** 2
				: ((($value * $expected[$alpha]) - ($actual[$i] * $actual[$alpha])) / 255) ** 2;
		}

		return sqrt($sum / max(1, count($expected)));
	}
}
