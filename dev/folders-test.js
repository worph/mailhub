// The merged folders as the webmail's main ones (mailhub_folders + mailhub-mirror's
// Merged/ views): the mail view opens on Merged/All inboxes shown as "Inbox", the
// local INBOX/Trash/Junk are not listed, Delete lands in Merged/Trash, deleting
// there removes for good. Needs a message in some account's INBOX whose subject
// contains $SUBJECT. Run on the app network:
//   docker run --rm --network mailhub_mailhub-internal -e MAILHUB_PASSWORD=... -e SUBJECT=... \
//     -v $PWD:/work -w /work ghcr.io/puppeteer/puppeteer:24.10.0 node folders-test.js
// Writes folders-inbox.png and folders-trash.png.
const puppeteer = require('puppeteer');

const RC = process.env.RC_URL || 'http://mailhub-webmail/';
const SUBJECT = process.env.SUBJECT;
const failures = [];
const check = (label, ok, detail) => {
  console.log((ok ? 'PASS ' : 'FAIL ') + label + (ok || detail === undefined ? '' : `  [${JSON.stringify(detail)}]`));
  if (!ok) failures.push(label);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage();
  page.on('dialog', (d) => d.accept());
  if (process.env.DEBUG) page.on('response', async (r) => { if (r.request().method() === 'POST') { let t = ''; try { t = (await r.text()).slice(-300); } catch (e) {} console.log('POST', r.url(), t); } });
  await page.setViewport({ width: 1440, height: 900 });

  await page.goto(RC + '?_task=login', { waitUntil: 'networkidle0' });
  await page.type('#rcmloginuser', 'admin');
  await page.type('#rcmloginpwd', process.env.MAILHUB_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#rcmloginsubmit')]);
  await sleep(1500);

  const env = await page.evaluate(() => ({
    mailbox: rcmail.env.mailbox, trash: rcmail.env.trash_mailbox, junk: rcmail.env.junk_mailbox,
    folders: [...document.querySelectorAll('#mailboxlist > li')].map((li) => [li.querySelector('a').getAttribute('rel'), li.querySelector('a').textContent.trim(), li.className]),
  }));
  console.log(JSON.stringify(env.folders));
  check('opens on Merged/All inboxes', env.mailbox === 'Merged/All inboxes', env.mailbox);
  check('it is listed first, as Inbox', env.folders[0] && env.folders[0][0] === 'Merged/All inboxes' && /^Inbox/.test(env.folders[0][1]) && /inbox/.test(env.folders[0][2]), env.folders[0]);
  check('Trash and Junk are the merged ones', env.trash === 'Merged/Trash' && env.junk === 'Merged/Junk', [env.trash, env.junk]);
  const rels = env.folders.map((f) => f[0]);
  check('Trash and Junk listed at the top level', rels.includes('Merged/Trash') && rels.includes('Merged/Junk'), rels);
  check('local INBOX, Trash and Junk not listed', !rels.includes('INBOX') && !rels.includes('Trash') && !rels.includes('Junk'), rels);

  const rowsWith = () => page.evaluate((s) => Object.entries(rcmail.message_list.rows)
    .filter(([, r]) => r.obj && r.obj.textContent.includes(s)).map(([uid]) => uid), SUBJECT);
  const select = (uid) => page.evaluate((uid) => { rcmail.message_list.select(uid); }, uid);
  let rows = await rowsWith();
  check('the test message shows in Inbox', rows.length === 1, rows);
  await page.screenshot({ path: 'folders-inbox.png' });

  if (rows.length) {
    await select(rows[0]);
    await sleep(800);
    await page.evaluate(() => rcmail.command('delete'));
    await sleep(2500);
    check('Delete removes it from Inbox', (await rowsWith()).length === 0);
    await page.evaluate(() => rcmail.command('list', 'Merged/Trash'));
    await sleep(2500);
    rows = await rowsWith();
    check('it is in Trash', rows.length === 1, rows);
    check('Empty is offered in Trash', await page.evaluate(() => rcmail.commands.purge === true || rcmail.env.mailbox === rcmail.env.trash_mailbox));
    await page.screenshot({ path: 'folders-trash.png' });
    if (rows.length) {
      await select(rows[0]);
      await sleep(800);
      await page.evaluate(() => rcmail.command('delete'));
      await sleep(2500);
      check('Delete in Trash removes it for good', (await rowsWith()).length === 0);
    }
  }

  await browser.close();
  console.log(failures.length ? `${failures.length} FAILED: ${failures}` : 'ALL PASSED');
  process.exit(failures.length ? 1 : 0);
})();
