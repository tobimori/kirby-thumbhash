#!/usr/bin/env bash
# Creates the test images in tests/fixtures/images. Needs ImageMagick 7, exiv2, and curl.
#
# earthrise: "Earthrise" by NASA/Bill Anders, public domain,
# https://commons.wikimedia.org/wiki/File:NASA-Apollo8-Dec24-Earthrise.jpg
# rose, wizard, logo: built-in images of ImageMagick
set -euo pipefail
cd "$(dirname "$0")/images"

photo=$(mktemp --suffix .jpg)
trap 'rm -f "$photo"' EXIT
curl -fsSL -o "$photo" https://upload.wikimedia.org/wikipedia/commons/a/a8/NASA-Apollo8-Dec24-Earthrise.jpg

magick "$photo" -gravity center -crop 3:2 +repage -resize 1200x800 -strip -quality 85 earthrise.jpg
magick earthrise.jpg -resize 600x400 -colorspace CMYK -quality 85 earthrise-cmyk.jpg
magick earthrise.jpg -resize '101x31!' -quality 90 panorama.jpg

# Stored rotated by 90°, with an EXIF orientation that turns it upright
magick earthrise.jpg -rotate -90 -quality 85 earthrise-rotated.jpg
exiv2 -M 'set Exif.Image.Orientation Short 6' earthrise-rotated.jpg

magick rose: -strip rose.png

# The white background becomes transparent, with partly transparent edges
magick wizard: \( +clone -colorspace gray -negate \) -alpha off -compose copy-opacity -composite -strip wizard.png
magick wizard.png -quality 80 wizard.webp
magick logo: -fuzz 5% -transparent white logo.gif
