<?php

namespace tobimori\Driver;

use Exception;

class GdDriver implements ImageDriver
{
  /**
   * Extract image data using the GD extension.
   * GD only provides 127-bit alpha data, so this up-scales it to 255 bits.
   */
  public static function extractPixels(string $content): array
  {
    $image = imagecreatefromstring($content);

    if ($image === false) {
      throw new Exception("Unable to read image data with GD");
    }

    $width = imagesx($image);
    $height = imagesy($image);

    $pixels = [];
    for ($y = 0; $y < $height; $y++) {
      for ($x = 0; $x < $width; $x++) {
        $color_index = imagecolorat($image, $x, $y);
        $color = imagecolorsforindex($image, $color_index);
        $alpha = 255 - ceil($color['alpha'] * 255 / 127); // GD only supports 7-bit alpha channel
        $pixels[] = $color['red'];
        $pixels[] = $color['green'];
        $pixels[] = $color['blue'];
        $pixels[] = $alpha;
      }
    }

    return [$width, $height, $pixels];
  }

  public static function isAvailable(): bool
  {
    return extension_loaded('gd');
  }
}
