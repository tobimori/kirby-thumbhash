// Creates codec.json with the output of the original JavaScript implementation, https://github.com/evanw/thumbhash.
// Needs ImageMagick 7 and `pnpm install`.
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { writeFileSync } from 'node:fs'
import { rgbaToThumbHash, thumbHashToApproximateAspectRatio, thumbHashToAverageRGBA, thumbHashToDataURL } from 'thumbhash'

const images = ['earthrise.jpg', 'rose.png', 'panorama.jpg', 'wizard.png', 'logo.gif']

// A different order of floating-point operations changes the output for only some inputs, so the crops give many inputs
const cropStep = 6

const read = (file) => {
  const args = [new URL(`images/${file}`, import.meta.url).pathname, '-resize', '100x100>', '-depth', '8']
  const [width, height] = execFileSync('magick', [...args, '-format', '%w %h', 'info:']).toString().split(' ').map(Number)
  return { width, height, rgba: execFileSync('magick', [...args, 'rgba:-']) }
}

const crop = ({ width, rgba }, x, y, w, h) => {
  const pixels = new Uint8Array(w * h * 4)
  for (let row = 0; row < h; row++) {
    const start = ((y + row) * width + x) * 4
    pixels.set(rgba.subarray(start, start + w * 4), row * w * 4)
  }
  return pixels
}

const expected = (image, x, y, w, h) => {
  const hash = rgbaToThumbHash(w, h, crop(image, x, y, w, h))
  return {
    x,
    y,
    w,
    h,
    hash: Buffer.from(hash).toString('base64').replace(/=+$/, ''),
    png: createHash('sha1').update(thumbHashToDataURL(hash)).digest('hex'),
    average: thumbHashToAverageRGBA(hash),
    ratio: thumbHashToApproximateAspectRatio(hash)
  }
}

const fixtures = {}
for (const file of images) {
  const image = read(file)
  const cases = [expected(image, 0, 0, image.width, image.height)]
  for (let w = 1; w < image.width; w += cropStep) {
    for (let h = 1; h < image.height; h += cropStep) {
      const result = expected(image, (image.width - w) >> 1, (image.height - h) >> 1, w, h)
      // The placeholder of an almost transparent crop is invisible, and its hash is rounding noise. The plugin adds the DCT
      // terms in another order, so the noise differs.
      if (result.average.a > 0) cases.push(result)
    }
  }

  fixtures[file] = { width: image.width, height: image.height, rgba: image.rgba.toString('base64'), cases }
}

writeFileSync(new URL('codec.json', import.meta.url), `${JSON.stringify(fixtures, null, 2)}\n`)
