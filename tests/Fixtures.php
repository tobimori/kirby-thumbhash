<?php

namespace tobimori\Tests;

/**
 * Real images and the output of the original JavaScript implementation for them, tests/fixtures/make.sh creates both
 */
class Fixtures
{
	public const string ROOT = __DIR__ . '/fixtures';

	/**
	 * Images and whether they have transparent pixels
	 */
	public const array IMAGES = [
		'earthrise.jpg' => false,
		'earthrise-rotated.jpg' => false,
		'earthrise-cmyk.jpg' => false,
		'panorama.jpg' => false,
		'rose.png' => false,
		'wizard.png' => true,
		'wizard.webp' => true,
		'logo.gif' => true
	];

	/**
	 * @return array{
	 *   images: array<string, array{pixels: string, width: int, height: int, hash: string, decoded: array{width: int, height: int, rgba: string}, dataUrl: string, averageRgba: array<string, float|int>, aspectRatio: float|int}>,
	 *   crops: list<array{image: string, x: int, y: int, w: int, h: int, hash: string, decoded: string, averageRgba: array<string, float|int>, aspectRatio: float|int}>
	 * }
	 */
	public static function expected(): array
	{
		/** @var array{images: array<string, array{pixels: string, width: int, height: int, hash: string, decoded: array{width: int, height: int, rgba: string}, dataUrl: string, averageRgba: array<string, float|int>, aspectRatio: float|int}>, crops: list<array{image: string, x: int, y: int, w: int, h: int, hash: string, decoded: string, averageRgba: array<string, float|int>, aspectRatio: float|int}>} */
		return json_decode((string)file_get_contents(self::ROOT . '/expected.json'), true, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * @return list<int>
	 */
	public static function bytes(string $data): array
	{
		/** @var list<int> */
		return array_values((array)unpack('C*', $data));
	}

	/**
	 * @return list<int> RGBA pixels of an expected.json entry
	 */
	public static function pixels(string $path): array
	{
		return self::bytes((string)file_get_contents(self::ROOT . "/{$path}"));
	}
}
