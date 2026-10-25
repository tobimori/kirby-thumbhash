<?php

namespace tobimori\Tests;

use Imagick;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Asset;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use tobimori\Driver\GdDriver;
use tobimori\Driver\ImagickDriver;
use tobimori\Sampler;

class SamplerTest extends TestCase
{
	private const array SIZES = ['100x75' => [32, 24], '64x100' => [16, 25], '100x100' => [32, 32]];

	/**
	 * @return iterable<string, array{string, string, class-string<GdDriver>|class-string<ImagickDriver>, int, int}>
	 */
	public static function files(): iterable
	{
		foreach (Images::files() as $name => $content) {
			[$size] = explode(' ', $name);
			if (!isset(self::SIZES[$size])) {
				continue;
			}

			[$width, $height] = self::SIZES[$size];
			$extension = match (true) {
				str_contains($name, 'jpeg') => 'jpg',
				str_contains($name, 'webp') => 'webp',
				str_contains($name, 'gif') => 'gif',
				default => 'png'
			};
			yield "{$name} gd" => [$content, $extension, GdDriver::class, $width, $height];
			if (extension_loaded('imagick')) {
				yield "{$name} imagick" => [$content, $extension, ImagickDriver::class, $width, $height];
			}
		}
	}

	/**
	 * @param class-string<GdDriver>|class-string<ImagickDriver> $driver
	 */
	#[DataProvider('files')]
	public function testSample(string $content, string $extension, string $driver, int $width, int $height): void
	{
		$asset = self::asset("image.{$extension}", $content);
		// the Imagick export doesn't convert CMYK
		[$sourceWidth, $sourceHeight, $source] = GdDriver::extractPixels($content);

		$sample = Sampler::sample($asset, $driver, $width, $height);

		$this->assertNotNull($sample);
		$this->assertSame([$width, $height], [$sample[0], $sample[1]]);
		$expected = self::areaAverage($source, $sourceWidth, $sourceHeight, $width, $height);
		$this->assertLessThan(1, self::premultipliedRms($expected, $sample[2]));
	}

	/**
	 * @return iterable<string, array{string, class-string<GdDriver>|class-string<ImagickDriver>}>
	 */
	public static function largeFiles(): iterable
	{
		foreach (['jpg', 'png', 'gif'] as $extension) {
			yield "{$extension} gd" => [$extension, GdDriver::class];
			if (extension_loaded('imagick')) {
				yield "{$extension} imagick" => [$extension, ImagickDriver::class];
			}
		}
	}

	/**
	 * Images that the drivers read at a reduced size, or point sample before they average
	 *
	 * @param class-string<GdDriver>|class-string<ImagickDriver> $driver
	 */
	#[DataProvider('largeFiles')]
	public function testSampleLargeImage(string $extension, string $driver): void
	{
		$image = imagecreatetruecolor(1600, 1200);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		for ($x = 0; $x < 1600; $x++) {
			imageline($image, $x, 0, $x, 1199, (int)imagecolorallocate($image, intdiv($x * 255, 1599), 128, 255 - intdiv($x * 255, 1599)));
		}
		mt_srand(3);
		for ($i = 0; $i < 40; $i++) {
			$color = (int)imagecolorallocatealpha($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $extension === 'png' ? mt_rand(0, 127) : 0);
			imagefilledellipse($image, mt_rand(0, 1600), mt_rand(0, 1200), mt_rand(50, 500), mt_rand(50, 500), $color);
		}
		if ($extension === 'gif') {
			imagetruecolortopalette($image, false, 255);
			imagecolortransparent($image, (int)imagecolorat($image, 800, 600));
		}
		ob_start();
		match ($extension) {
			'jpg' => imagejpeg($image, null, 95),
			'png' => imagepng($image),
			default => imagegif($image)
		};
		$content = (string)ob_get_clean();
		$asset = self::asset("large.{$extension}", $content);
		[$sourceWidth, $sourceHeight, $source] = GdDriver::extractPixels($content);

		$sample = Sampler::sample($asset, $driver, 32, 24);

		$this->assertSame([32, 24], [$sample[0] ?? null, $sample[1] ?? null]);
		$expected = self::areaAverage($source, $sourceWidth, $sourceHeight, 32, 24);
		$this->assertLessThan(3, self::premultipliedRms($expected, $sample[2] ?? []));
	}

	public function testSampleDoesNotScaleUp(): void
	{
		$asset = self::asset('image.png', Images::files()['100x75 opaque png']);

		$sample = Sampler::sample($asset, GdDriver::class, 200, 150);

		$this->assertSame([100, 75], [$sample[0] ?? null, $sample[1] ?? null]);
	}

	/**
	 * @return iterable<string, array{class-string<GdDriver>|class-string<ImagickDriver>, int}>
	 */
	public static function orientations(): iterable
	{
		foreach (range(1, 8) as $orientation) {
			yield "gd {$orientation}" => [GdDriver::class, $orientation];
			if (extension_loaded('imagick')) {
				yield "imagick {$orientation}" => [ImagickDriver::class, $orientation];
			}
		}
	}

	/**
	 * @param class-string<GdDriver>|class-string<ImagickDriver> $driver
	 */
	#[DataProvider('orientations')]
	#[RequiresPhpExtension('imagick')]
	public function testOrientation(string $driver, int $orientation): void
	{
		$pixels = Images::create(100, 60, 'photo', 'opaque', 7);
		// a mark in the top left corner shows a wrong orientation
		foreach (range(0, 29) as $y) {
			foreach (range(0, 39) as $x) {
				array_splice($pixels, (($y * 100) + $x) * 4, 3, [255, 0, 0]);
			}
		}
		$upright = self::asset('upright.jpg', self::jpeg(100, 60, $pixels, 1));
		$turned = self::asset("turned-{$orientation}.jpg", self::jpeg(100, 60, $pixels, $orientation));

		$expected = Sampler::sample($upright, $driver, 25, 15);
		$sample = Sampler::sample($turned, $driver, 25, 15);

		$this->assertSame([25, 15], [$sample[0] ?? null, $sample[1] ?? null]);
		$this->assertNotNull($expected);
		$this->assertLessThan(3, self::premultipliedRms($expected[2], $sample[2] ?? []));
	}

	#[RequiresPhpExtension('imagick')]
	public function testFocus(): void
	{
		$pixels = [];
		foreach (range(0, 49) as $y) {
			foreach (range(0, 99) as $x) {
				array_push($pixels, ...$x < 50 ? [255, 0, 0, 255] : [0, 0, 255, 255]);
			}
		}
		$root = App::instance()->root('content');
		F::write("{$root}/focus/image.png", self::png(100, 50, $pixels));

		$sample = static function (string $focus): array {
			$page = new Page(['slug' => 'focus', 'dirname' => 'focus']);
			$file = $page->file('image.png');
			$file = $file?->clone(['content' => ['focus' => $focus, 'uuid' => 'focus']]);
			if ($file === null) {
				return [];
			}

			return Sampler::sample($file, GdDriver::class, 10, 10)[2] ?? [];
		};

		$this->assertSame([255, 0, 0, 255], array_slice($sample('0% 50%'), 0, 4));
		$this->assertSame([255, 0, 0, 255], array_slice($sample('0% 50%'), -4));
		$this->assertSame([0, 0, 255, 255], array_slice($sample('100% 50%'), 0, 4));
		$this->assertSame([0, 0, 255, 255], array_slice($sample(''), -4));
	}

	private static function asset(string $filename, string $content): Asset
	{
		$root = App::instance()->root('index');
		Dir::remove("{$root}/media");
		F::write("{$root}/assets/{$filename}", $content);

		return new Asset("assets/{$filename}");
	}

	/**
	 * @param list<int> $pixels
	 */
	private static function png(int $width, int $height, array $pixels): string
	{
		$image = new Imagick();
		$image->newImage($width, $height, 'none');
		$image->importImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR, $pixels);
		$image->setImageFormat('png');

		return $image->getImageBlob();
	}

	/**
	 * Encodes the pixels turned so that the EXIF orientation turns them upright
	 *
	 * @param list<int> $pixels
	 */
	private static function jpeg(int $width, int $height, array $pixels, int $orientation): string
	{
		$image = new Imagick();
		$image->newImage($width, $height, 'white');
		$image->importImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR, $pixels);
		match ($orientation) {
			2 => $image->flopImage(),
			3 => $image->rotateImage('white', 180),
			4 => $image->flipImage(),
			5 => $image->transposeImage(),
			6 => $image->rotateImage('white', -90),
			7 => $image->transverseImage(),
			8 => $image->rotateImage('white', 90),
			default => null
		};
		$image->setImageFormat('jpeg');
		$image->setImageCompressionQuality(100);
		$jpeg = $image->getImageBlob();

		// an APP1 segment with an Exif IFD that only has the orientation tag
		$exif = "Exif\0\0MM\0*\0\0\0\x08" . pack('nnnNnnN', 1, 0x0112, 3, 1, $orientation, 0, 0);

		return "\xFF\xD8\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
	}

	/**
	 * Scales the pixels down with the exact average of the covered area, weighted by alpha
	 *
	 * @param list<int> $pixels
	 * @return list<int>
	 */
	private static function areaAverage(array $pixels, int $sourceWidth, int $sourceHeight, int $width, int $height): array
	{
		$scaleX = $sourceWidth / $width;
		$scaleY = $sourceHeight / $height;
		$result = [];
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$sums = [0.0, 0.0, 0.0, 0.0];
				$area = 0.0;
				for ($sy = (int)floor($y * $scaleY); $sy < min($sourceHeight, (int)ceil(($y + 1) * $scaleY)); $sy++) {
					$coverY = min(($y + 1) * $scaleY, $sy + 1) - max($y * $scaleY, $sy);
					for ($sx = (int)floor($x * $scaleX); $sx < min($sourceWidth, (int)ceil(($x + 1) * $scaleX)); $sx++) {
						$cover = $coverY * (min(($x + 1) * $scaleX, $sx + 1) - max($x * $scaleX, $sx));
						$i = (($sy * $sourceWidth) + $sx) * 4;
						$alpha = $cover * $pixels[$i + 3];
						$sums[0] += $alpha * $pixels[$i];
						$sums[1] += $alpha * $pixels[$i + 1];
						$sums[2] += $alpha * $pixels[$i + 2];
						$sums[3] += $alpha;
						$area += $cover;
					}
				}
				$result[] = $sums[3] > 0 ? (int)round($sums[0] / $sums[3]) : 0;
				$result[] = $sums[3] > 0 ? (int)round($sums[1] / $sums[3]) : 0;
				$result[] = $sums[3] > 0 ? (int)round($sums[2] / $sums[3]) : 0;
				$result[] = (int)round($sums[3] / $area);
			}
		}

		return $result;
	}

	/**
	 * @param list<int> $expected
	 * @param list<int> $actual
	 */
	private static function premultipliedRms(array $expected, array $actual): float
	{
		$sum = 0;
		foreach ($expected as $i => $value) {
			$sum += ($i % 4) === 3
				? ($value - $actual[$i]) ** 2
				: ((($value * $expected[$i - ($i % 4) + 3]) - ($actual[$i] * $actual[$i - ($i % 4) + 3])) / 255) ** 2;
		}

		return sqrt($sum / max(1, count($expected)));
	}
}
