<?php

// Kirby magic methods for Mago, never loaded. Mago can take a stub as the class declaration, so the stubs repeat the real class headers

namespace Kirby\Cms {
	/**
	 * @method bool isResizable()
	 * @method float ratio()
	 * @method \Kirby\Image\Exif exif()
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

	/**
	 * @method string|false read()
	 * @method int width()
	 * @method int height()
	 * @method bool delete()
	 */
	class FileVersion
	{
		use \Kirby\Filesystem\IsFile;
	}
}

namespace Kirby\Filesystem {
	/**
	 * @method bool isResizable()
	 * @method float ratio()
	 * @method string filename()
	 * @method \Kirby\Image\Exif exif()
	 */
	class Asset
	{
		use IsFile;
		use \Kirby\Cms\FileModifications;
		use \Kirby\Cms\HasMethods;
	}
}
