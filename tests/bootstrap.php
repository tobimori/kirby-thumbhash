<?php

use Kirby\Cms\App;

$root = dirname(__DIR__);
require is_file("{$root}/.repos/vendor/autoload.php") ? "{$root}/.repos/vendor/autoload.php" : "{$root}/vendor/autoload.php";
require "{$root}/index.php";

new App([
	'roots' => ['index' => sys_get_temp_dir() . '/kirby-thumbhash-tests'],
	'options' => [
		'tobimori.thumbhash.cache.encode' => false,
		'tobimori.thumbhash.cache.decode' => false
	]
]);
