---
title: Advanced
---

### Cropped images

Kirby doesn't support file methods on cropped images, so you'll have to use the original image, and pass the ratio as a parameter to get the correct ThumbHash.

```php
<?php $cropped = $original->crop(500, 400) ?>
<img
  src="<?= $original->thumbhashUri(5/4) ?>"
  data-src="<?= $cropped->url() ?>"
  data-lazyload
  alt="<?= $original->alt() ?>"
/>
```

All methods support the ratio parameter:
- `$file->thumbhash($ratio)`
- `$file->thumbhashUri($ratio, $blurRadius)`
- `$file->averageColor($format, $ratio)`
- `$file->averageColorRgba($ratio)`

### Working with static assets (using `asset()` helper)

All methods are available as asset methods since Kirby 3.9.2.

```php
asset('assets/image.jpg')->thumbhash();
asset('assets/image.jpg')->thumbhashUri();
```

[Read more about the `asset()` helper here](https://getkirby.com/docs/reference/objects/filesystem/asset).

### Aliases

```php
$file->th(); // thumbhash()
$file->thUri(); // thumbhashUri()
```

### Parameters

All methods support clean parameter syntax for better readability:

```php
// Encoding with custom ratio
$file->thumbhash(16/9); // cropped to 16:9

// Server-side placeholder with custom blur
$file->thumbhashUri(null, 2); // blur radius of 2
$file->thumbhashUri(3/2, 0); // cropped to 3:2, no blur (raw PNG)

// Average colors with custom ratio
$file->averageColor('rgb', 16/9); // modern CSS format, 16:9 ratio
$file->averageColorRgba(4/3); // RGBA array, 4:3 ratio
```

**Named parameters** are also supported for clarity:

```php
$file->thumbhashUri(ratio: 16/9, blurRadius: 2);
$file->averageColor(format: 'rgba', ratio: 1.5);
```

**Legacy array syntax** (deprecated but still supported):

```php
$file->thumbhash(['ratio' => 16/9]);
$file->thumbhashUri(['ratio' => 3/2, 'blurRadius' => 0]);
```

### Clear cache

The encoding cache is automatically cleared when an image gets replaced or updated, however you can also clear the cache manually with the `clearCache` static method:

```php
<?php

use tobimori\ThumbHash;

ThumbHash::clearCache($file);
```

This might be helpful when you use third party plugins to edit your images, and they do not trigger Kirby's internal file update hooks but instead have their own.

## Options

| Option          | Default | Description                                                                                       |
| --------------- | ------- | ------------------------------------------------------------------------------------------------- |
| `cache.decode`  | `true`  | Enable decoding cache                                                                             |
| `cache.encode`  | `true`  | Enable encoding cache                                                                             |
| `engine`        | `'gd'`  | Image processing driver: `'gd'` or `'imagick'` (Imagick offers better performance and quality)    |
| `sampleMaxSize` | `100`   | Max width or height for sample image that gets encoded (affects memory usage and quality)         |
| `blurRadius`    | `1`     | Default radius of the SVG blur filter applied to decoded image, set to 0 for raw base64 PNG      |

Options allow you to fine tune the behaviour of the plugin. You can set them in your `config.php` file:

```php
return [
    'tobimori.thumbhash' => [
        'engine' => 'imagick', // use Imagick for better performance (requires ext-imagick)
        'sampleMaxSize' => 100,
        'blurRadius' => 1,
    ],
];
```

### Image Processing Drivers

The plugin supports two image processing drivers:

- **GD** (default): Uses PHP's built-in GD extension. Always available, good performance.
- **Imagick**: Uses the Imagick extension. Offers better performance and quality, especially for larger images.

To use Imagick, install the PHP extension and set the engine option:

```sh
# Install Imagick extension (varies by system)
pecl install imagick
```

```php
// config.php
return [
    'tobimori.thumbhash.engine' => 'imagick',
];
```

### Error Handling

When sample thumb generation fails, the plugin returns `null` silently in production. If you have Kirby's [debug mode](https://getkirby.com/docs/reference/system/options/debug) enabled, it will throw an exception with a detailed error message instead. The failed thumbnail is automatically deleted to allow retry on next request.
