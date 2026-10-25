<?php

namespace tobimori\Tests;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Filesystem\Asset;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use LogicException;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * earthrise.jpg is "Earthrise" by NASA/Bill Anders (public domain),
	 * https://commons.wikimedia.org/wiki/File:NASA-Apollo8-Dec24-Earthrise.jpg, and the other earthrise and panorama images
	 * are made from it. rose, wizard, and logo are built-in images of ImageMagick.
	 */
	protected const string FIXTURES = __DIR__ . '/fixtures/images';

	/**
	 * Images in FIXTURES and whether they have transparent pixels
	 */
	protected const array IMAGES = [
		'earthrise.jpg' => false,
		'earthrise-rotated.jpg' => false,
		'earthrise-cmyk.jpg' => false,
		'panorama.jpg' => false,
		'rose.png' => false,
		'wizard.png' => true,
		'wizard.webp' => true,
		'logo.gif' => true
	];

	private App|null $app = null;

	protected function setUp(): void
	{
		Dir::remove((string)App::instance()->root('index'));
	}

	protected function tearDown(): void
	{
		if ($this->app !== null) {
			// A new Kirby app sets its own error handlers
			restore_error_handler();
			restore_exception_handler();
			App::instance($this->app);
			$this->app = null;
		}
	}

	/**
	 * Sets plugin options until the end of the test
	 *
	 * @param array<string, mixed> $options
	 */
	protected function options(array $options): void
	{
		$this->app ??= App::instance();
		$this->app->clone(['options' => $options]);
	}

	protected static function asset(string $image, string|null $content = null): Asset
	{
		$root = App::instance()->root('index');
		F::write("{$root}/assets/{$image}", $content ?? (string)F::read(self::FIXTURES . "/{$image}"));

		return new Asset("assets/{$image}");
	}

	/**
	 * Returns a new File object of an image in FIXTURES, the first call copies the image to the content folder
	 *
	 * @param array<string, string> $content
	 */
	protected static function file(string $image, array $content = []): File
	{
		$root = App::instance()->root('content');
		if (!F::exists("{$root}/images/{$image}")) {
			F::copy(self::FIXTURES . "/{$image}", "{$root}/images/{$image}");
		}

		$file = (new Page(['slug' => 'images', 'dirname' => 'images']))->file($image) ?? throw new LogicException("{$image} is missing");

		return $file->clone(['content' => [...$content, 'uuid' => md5($image . serialize($content))]]);
	}
}
