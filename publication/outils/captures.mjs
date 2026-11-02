// Captures d'écran de la fiche drupal.org, sur le site de démonstration
// monté par demo.sh. Usage :
//   PLAYWRIGHT_MODULE=/chemin/vers/node_modules/playwright \
//   node publication/outils/captures.mjs <lien de connexion de drush user:login>
import { createRequire } from "node:module";
import { mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? "playwright");
const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, "..", "captures");
const site = "http://localhost:8095";
const login = process.argv[2];
const step = process.argv[3] ?? "all";
mkdirSync(out, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

if (step === "logo") {
  // Project logo for drupal.org: logo.png at the root, square.
  const { readFileSync } = await import("node:fs");
  const svg = readFileSync(join(here, "logo.svg"), "utf8");
  const logo = await browser.newPage({ viewport: { width: 512, height: 512 } });
  await logo.setContent(`<html><body style="margin:0">${svg.replace("<svg ", '<svg width="512" height="512" ')}</body></html>`);
  await logo.screenshot({ path: join(here, "..", "..", "logo.png"), omitBackground: true });
} else if (step === "shot") {
  // Image de « l'étape en échec » servie par l'API de démonstration.
  await page.goto(`${site}/contact`);
  await page.screenshot({ path: join(here, "shot.png") });
} else {
  await page.goto(login);
  const shots = [
    ["1-reglages.png", "/admin/config/services/pathly", true],
    ["2-rapport.png", "/admin/reports/pathly", false],
    ["3-encart-contenu.png", "/admin/content", false],
  ];
  for (const [file, path, full] of shots) {
    await page.goto(site + path);
    await page.screenshot({ path: join(out, file), fullPage: full });
  }
  // The badge, as a visitor sees it (not logged in).
  const visitor = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await visitor.goto(site + "/");
  await visitor.locator(".pathly-badge").scrollIntoViewIfNeeded();
  await visitor.screenshot({ path: join(out, "4-badge.png") });
}
await browser.close();
