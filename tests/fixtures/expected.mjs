import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import {
  rgbaToThumbHash,
  thumbHashToApproximateAspectRatio,
  thumbHashToAverageRGBA,
  thumbHashToDataURL,
  thumbHashToRGBA
} from 'thumbhash'

const base64 = (bytes) => Buffer.from(bytes).toString('base64')
const sha1 = (bytes) => createHash('sha1').update(bytes).digest('hex')
const expected = { images: {}, crops: [] }

for (const file of readdirSync(new URL('pixels', import.meta.url)).sort()) {
  const [, name, w, h] = file.match(/^(.+)-(\d+)x(\d+)\.rgba$/)
  const rgba = readFileSync(new URL(`pixels/${file}`, import.meta.url))
  const hash = rgbaToThumbHash(+w, +h, rgba)
  const image = thumbHashToRGBA(hash)

  expected.images[`${name}-${w}x${h}`] = {
    pixels: `pixels/${file}`,
    width: +w,
    height: +h,
    hash: base64(hash).replace(/=+$/, ''),
    decoded: { width: image.w, height: image.h, rgba: sha1(image.rgba) },
    dataUrl: thumbHashToDataURL(hash),
    averageRgba: thumbHashToAverageRGBA(hash),
    aspectRatio: thumbHashToApproximateAspectRatio(hash)
  }
}

// Crops of the 100px images give many more inputs, because a different order of floating-point operations changes the
// output for only some inputs
for (const [key, image] of Object.entries(expected.images)) {
  if (image.width < 70 && image.height < 70) continue
  const rgba = readFileSync(new URL(image.pixels, import.meta.url))
  for (let w = 1; w <= image.width; w += 6) {
    for (let h = 1; h <= image.height; h += 6) {
      const x = (w * 13) % (image.width - w + 1)
      const y = (h * 7) % (image.height - h + 1)
      const crop = new Uint8Array(w * h * 4)
      for (let row = 0; row < h; row++) {
        crop.set(rgba.subarray(((y + row) * image.width + x) * 4, ((y + row) * image.width + x + w) * 4), row * w * 4)
      }
      const hash = rgbaToThumbHash(w, h, crop)
      // The placeholder of an almost transparent crop is invisible, and its hash holds rounding noise. srwiez/thumbhash and
      // this plugin add the DCT terms in a different order, so the noise differs.
      if (thumbHashToAverageRGBA(hash).a === 0) continue
      const decoded = thumbHashToRGBA(hash)
      expected.crops.push({
        image: key,
        x, y, w, h,
        hash: base64(hash).replace(/=+$/, ''),
        decoded: sha1(decoded.rgba),
        averageRgba: thumbHashToAverageRGBA(hash),
        aspectRatio: thumbHashToApproximateAspectRatio(hash)
      })
    }
  }
}

const crops = expected.crops.map((crop) => `    ${JSON.stringify(crop)}`).join(',\n')
const json = JSON.stringify({ ...expected, crops: [] }, null, 2).replace('"crops": []', `"crops": [\n${crops}\n  ]`)
writeFileSync(new URL('expected.json', import.meta.url), `${json}\n`)
