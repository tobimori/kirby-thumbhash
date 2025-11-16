<?php

namespace tobimori\Driver;

interface ImageDriver
{
  /**
   * Extracts width, height, and RGBA pixel data from image content
   *
   * @param string $content Binary image data
   * @return array [$width, $height, $pixels]
   */
  public static function extractPixels(string $content): array;

  /**
   * Checks if this driver is available on the system
   */
  public static function isAvailable(): bool;
}
