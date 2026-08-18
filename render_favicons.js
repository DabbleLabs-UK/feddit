/*
 * One-off: rasterise public/img/feddit-mark.svg into the PNG favicon fallbacks
 * at the sizes browsers ask for. Uses the same SVG bytes the site serves, so the
 * PNGs can never drift from the signed-off mark. Run: node render_favicons.js
 *
 * Not part of the app runtime; kept in the repo so the icons are reproducible.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const svg = fs.readFileSync(path.join(__dirname, 'public/img/feddit-mark.svg'), 'utf8');

// [outfile, pixel size]
const targets = [
  ['public/favicon-32.png', 32],
  ['public/favicon-16.png', 16],
  ['public/apple-touch-icon.png', 180],
];

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  for (const [out, size] of targets) {
    // Render the SVG at exactly `size` px on a transparent page, then clip the
    // screenshot to those pixels. deviceScaleFactor 1 => 1 CSS px == 1 image px.
    const html =
      '<!doctype html><html><head><meta charset="utf-8">' +
      '<style>html,body{margin:0;padding:0;background:transparent}' +
      'svg{display:block}</style></head><body>' +
      svg.replace(/width="64" height="64"/, `width="${size}" height="${size}"`) +
      '</body></html>';
    await page.setViewportSize({ width: size, height: size });
    await page.goto('data:text/html;charset=utf-8,' + encodeURIComponent(html));
    await page.screenshot({
      path: path.join(__dirname, out),
      omitBackground: true,
      clip: { x: 0, y: 0, width: size, height: size },
    });
    console.log(`wrote ${out} (${size}x${size})`);
  }
  await browser.close();
})();
