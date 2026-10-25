#!/usr/bin/env bash
# Creates the test images and the expected codec output. Needs ImageMagick 7, exiv2, curl, Node.js, and `pnpm install`.
# earthrise: "Earthrise" by NASA/Bill Anders, public domain,
# https://commons.wikimedia.org/wiki/File:NASA-Apollo8-Dec24-Earthrise.jpg
# rose, wizard, logo: built-in images of ImageMagick
set -euo pipefail
cd "$(dirname "$0")"

source=$(mktemp --suffix .jpg)
curl -sL -o "$source" 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a8/NASA-Apollo8-Dec24-Earthrise.jpg/1920px-NASA-Apollo8-Dec24-Earthrise.jpg'

magick "$source" -gravity center -crop 1920x1280+0+0 +repage -resize 1200x800 -strip -quality 85 earthrise.jpg
magick earthrise.jpg -rotate -90 -strip -quality 85 earthrise-rotated.jpg
exiv2 -M'set Exif.Image.Orientation Short 6' earthrise-rotated.jpg
magick earthrise.jpg -resize 600x400 -colorspace CMYK -quality 85 earthrise-cmyk.jpg
magick earthrise.jpg -resize 101x31! -quality 90 panorama.jpg
magick rose: -strip rose.png
magick wizard: \( +clone -colorspace gray -negate \) -alpha off -compose copy_opacity -composite -strip wizard.png
magick wizard.png -quality 80 wizard.webp
magick logo: -fuzz 5% -transparent white logo.gif

rm "$source"

# Raw RGBA pixels for the codec tests, at the largest size that ThumbHash accepts and at the default sample size
rm -rf pixels && mkdir pixels
for spec in earthrise.jpg:100 earthrise.jpg:32 rose.png:70 wizard.png:100 wizard.png:32 logo.gif:100 panorama.jpg:100; do
  file=${spec%:*}
  size=${spec#*:}
  name="${file%.*}-$(magick "$file" -resize "${size}x${size}>" -format '%wx%h' info:)"
  magick "$file" -resize "${size}x${size}>" -depth 8 "rgba:pixels/${name}.rgba"
done

# Expected output of the original JavaScript implementation, https://github.com/evanw/thumbhash
node expected.mjs
