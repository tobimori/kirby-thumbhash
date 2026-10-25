<?php

// Kirby magic methods for Mago, never loaded. Mago can take a stub as the class declaration, so the stubs repeat the real class headers

namespace Kirby\Cms {
	/**
	 * @method bool isResizable()
	 * @method float ratio()
	 * @method \Kirby\Image\Exif exif()
	 * @method \Kirby\Content\Field focus()
	 * @method string|null thumbhash(float|array|null $ratio = null, array $options = [])
	 * @method string|null th(float|array|null $ratio = null, array $options = [])
	 * @method string|null thumbhashUri(float|array|null $ratio = null, float|null $blurRadius = null, array $options = [])
	 * @method string|null thUri(float|array|null $ratio = null, float|null $blurRadius = null, array $options = [])
	 * @method string|null averageColor(string $format = 'hex', float|null $ratio = null)
	 * @method array{r: int, g: int, b: int, a: float}|null averageColorRgba(float|null $ratio = null)
	 * @method bool hasTransparency(float|null $ratio = null)
	 */
	class File extends ModelWithContent
	{
		use FileActions;
		use FileModifications;
		use HasMethods;
		use HasSiblings;
		use \Kirby\Filesystem\IsFile;
		public static array $methods = [];
	}
}

namespace Kirby\Filesystem {
	/**
	 * @method bool isResizable()
	 * @method float ratio()
	 * @method string filename()
	 * @method \Kirby\Image\Exif exif()
	 * @method string|null thumbhash(float|array|null $ratio = null, array $options = [])
	 * @method string|null th(float|array|null $ratio = null, array $options = [])
	 * @method string|null thumbhashUri(float|array|null $ratio = null, float|null $blurRadius = null, array $options = [])
	 * @method string|null thUri(float|array|null $ratio = null, float|null $blurRadius = null, array $options = [])
	 * @method string|null averageColor(string $format = 'hex', float|null $ratio = null)
	 * @method array{r: int, g: int, b: int, a: float}|null averageColorRgba(float|null $ratio = null)
	 * @method bool hasTransparency(float|null $ratio = null)
	 */
	class Asset
	{
		use IsFile;
		use \Kirby\Cms\FileModifications;
		use \Kirby\Cms\HasMethods;
	}
}
