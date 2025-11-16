---
title: Usage
---

## Client-side decoding

### **`$file->thumbhash()`**

**Encodes the image with ThumbHash and returns ThumbHash as a string**

The default implementation of ThumbHash expects the string to be decoded on the client-side.

This provides the most benefits, most notably including better color representation and smaller payload size, but requires the initial execution of such a library on the client-side, and thus is better used with a headless site or heavily makes use of client-side infinite scrolling/loading.

With an lazy-loading library like [unlazy](https://unlazy.byjohann.dev/) you can implement lazy-loading with client-side decoding easily by providing the thumbhash as attribute.

```php
<img
  data-thumbhash="<?= $image->thumbhash() ?>"
  data-src="<?= $image->url() ?>" // Original src attribute will be placed by unlazy
  loading="lazy"
  alt="<?= $image->alt() ?>"
/>
```

## Server-side decoding

### **`$file->thumbhashUri()`**

**Encodes the image with ThumbHash, then decodes & rasterizes it. Finally returns it as a data URI which can be used without any client-side library.**

In addition to simply outputting the ThumbHash string for usage on the client-side, this plugin also provides a server-side decoding option that allows you to output a base64-encoded image string, which can be used as a placeholder image without any client-side libraries.

This is especially useful when you only have a few images on your site or don't want to go through the hassle of using a client-side library for outputting placeholders. Using this approach, you'll still get better color representation of the ThumbHash algorithm than with regularly downsizing an image, but image previews will still be about ~1kB large.

```php
<img src="<?= $image->thumbhashUri() ?>" />
```

## Average color extraction

### **`$file->averageColor()`**

**Extracts the average color from the image's thumbhash and returns it as a CSS color string.**

ThumbHash encodes the average color of an image in its hash. This method extracts that color and returns it in various CSS-compatible formats:

```php
// Hex format with alpha (default)
$image->averageColor() // '#FF8800AA'

// Modern CSS rgb() syntax with alpha
$image->averageColor('rgb') // 'rgb(255 136 0 / 0.67)'

// Legacy CSS rgba() syntax
$image->averageColor('rgba') // 'rgba(255, 136, 0, 0.67)'
```

This is useful for setting background colors, creating color-matched loading states, or implementing color-based UI themes.

### **`$file->averageColorRgba()`**

**Returns the average color as an RGBA array with separate values.**

```php
$color = $image->averageColorRgba();
// ['r' => 255, 'g' => 136, 'b' => 0, 'a' => 0.67]
```
