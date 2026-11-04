// Screenshot the webmail in each installed skin: mail, calendar, Settings → Mail accounts.
// Run on the app network with puppeteer, e.g.
//   docker run --rm --network mailhub_mailhub-internal -e MAILHUB_PASSWORD=... \
//     -v $PWD:/work -w /work ghcr.io/puppeteer/puppeteer:24.10.0 node skin-shots.js
// Writes shot-<skin>-<page>.png into the working directory.
const puppeteer = require('puppeteer');

const RC = process.env.RC_URL || 'http://mailhub-webmail/';
const SKINS = (process.env.SKINS || 'elastic,workbench,elastic2022').split(',');
const PAGES = {
  mail: '?_task=mail&_mbox=INBOX',
  calendar: '?_task=calendar',
  accounts: '?_task=settings&_action=preferences&_section=mailhub_accounts',
};

(async () => {
  const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  await page.goto(RC + '?_task=login', { waitUntil: 'networkidle0' });
  await page.type('#rcmloginuser', 'admin');
  await page.type('#rcmloginpwd', process.env.MAILHUB_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#rcmloginsubmit')]);

  for (const skin of SKINS) {
    // Save the skin the way Settings → User interface does.
    await page.goto(RC + '?_task=settings&_action=edit-prefs&_section=general&_framed=1', { waitUntil: 'networkidle0' });
    const saved = await page.evaluate(async (skin) => {
      const form = document.querySelector('form');
      const data = new FormData(form);
      data.set('_skin', skin);
      const res = await fetch('?_task=settings&_action=save-prefs&_framed=1', { method: 'POST', body: data });
      return res.status;
    }, skin);
    for (const [name, path] of Object.entries(PAGES)) {
      await page.goto(RC + path, { waitUntil: 'networkidle0' });
      await new Promise((r) => setTimeout(r, 1500)); // calendar renders after load
      const used = await page.evaluate(() => (window.rcmail && rcmail.env.skin) || '?');
      await page.screenshot({ path: `shot-${skin}-${name}.png` });
      console.log(`${skin} ${name}: saved=${saved} skin-in-use=${used}`);
    }
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
