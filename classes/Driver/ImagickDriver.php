<?php

namespace tobimori\Driver;

use Exception;
use Imagick;

class ImagickDriver implements ImageDriver
{
  /**
   * Extract image data using Imagick extension
   */
  public static function extractPixels(string $content): array
  {
    $image = new Imagick();
    $image->readImageBlob($content);

    $width = $image->getImageWidth();
    $height = $image->getImageHeight();

    $pixels = [];
    for ($y = 0; $y < $height; $y++) {
      for ($x = 0; $x < $width; $x++) {
        $pixel = $image->getImagePixelColor($x, $y);
        $colors = $pixel->getColor(2);
        $pixels[] = $colors['r'];
        $pixels[] = $colors['g'];
        $pixels[] = $colors['b'];
        $pixels[] = $colors['a'];
      }
    }

    $image->clear();
    $image->destroy();

    return [$width, $height, $pixels];
  }

  public static function isAvailable(): bool
  {
    return extension_loaded('imagick');
  }
}
