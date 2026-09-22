/* 虹越智造平台 · 原型回归冒烟测试脚本
 * 运行前提：已安装 playwright（npm i playwright）并按 README 回归清单逐项验证
 * 用法：node smoke-test.cjs   （自动起静态服务 → 冒烟断言 → 输出 PASS/FAIL）
 */
const http = require('http'), fs = require('fs'), path = require('path');
const ROOT = __dirname;
const PORT = 8932;

let pw;
try { pw = require('playwright'); }
catch (e) { console.error('✘ 缺少 playwright，请先执行：npm i playwright（或走 Playwright MCP 手工回归）'); process.exit(1); }

const MIME = { '.html': 'text/html; charset=utf-8' };
const server = http.createServer((req, res) => {
  const f = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
  if (!f.startsWith(ROOT) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'text/plain' });
  res.end(fs.readFileSync(f));
});

const results = [];
function check(name, cond) { results.push([cond ? '✓' : '✘', name]); }

(async () => {
  await new Promise(r => server.listen(PORT, '127.0.0.1', r));
  const browser = await pw.chromium.launch();
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));

  const base = `http://127.0.0.1:${PORT}/`;
  const file = encodeURIComponent('排产甘特页-交互原型.html');

  // —— 排产甘特 ——
  await page.goto(base + file);
  await page.waitForTimeout(500);
  check('甘特初始任务块=3', await page.locator('.job').count() === 3);
  check('任务池=9', await page.locator('.ptask').count() === 9);
  check('排产结果明细初始=3 行', await page.locator('#detailBody tr').count() === 3);

  await page.locator('.ptask').nth(5).click();
  await page.waitForTimeout(150);
  const box = await page.locator('.grow[data-m="B-01"] .track').boundingBox();
  await page.mouse.click(box.x + box.width * 0.3, box.y + box.height / 2);
  await page.waitForTimeout(350);
  check('点击放置后 B-01 +1 块', await page.locator('.grow[data-m="B-01"] .job').count() === 1);
  check('明细实时 +1 行', await page.locator('#detailBody tr').count() === 4);
  check('任务池同步 -1', await page.locator('.ptask').count() === 8);

  // 重复放置拦截
  await page.locator('.ptask').nth(5).click();
  await page.waitForTimeout(120);
  await page.mouse.click(box.x + box.width * 0.8, box.y + box.height / 2);
  await page.waitForTimeout(250);
  check('同一任务不重复落位', await page.locator('.grow[data-m="B-01"] .job').count() === 1);

  // 打断式插单
  await page.click('#btnUrgent');
  await page.waitForTimeout(200);
  check('插单弹层出现', await page.locator('#urgentMask').isVisible());
  await page.click('#optBreak');
  await page.waitForTimeout(300);
  check('打断后 I-02 ≥3 块(已完成/紧急/剩余)', (await page.locator('.grow[data-m="I-02"] .job').count()) >= 3);
  check('影响面板展示', await page.locator('#pImpact').isVisible());

  // 撤销
  await page.click('#btnUndo');
  await page.waitForTimeout(250);
  check('撤销后块数回退', await page.locator('.job').count() < 8);

  // 控制台零错误
  check('控制台无 JS 错误', errors.length === 0);

  // —— PC 总览 ——
  await page.goto(base + encodeURIComponent('00-系统UI总览-PC端.html'));
  await page.waitForTimeout(400);
  check('PC 总览加载', await page.locator('#nav .item').count() === 20);
  await page.click('#nav .item[data-p="p-quote"]');
  await page.waitForTimeout(150);
  await page.click('#q-result').catch(()=>{});
  check('AI 估价页可切', await page.locator('#p-quote.on').count() === 1);

  // —— 手机端 ——
  await page.goto(base + encodeURIComponent('11-工人报工-手机原型.html'));
  await page.waitForTimeout(300);
  check('报工手机加载(今日任务2)', await page.locator('.task').count() === 2);
  await page.locator('.btn-rep').first().click();
  await page.waitForTimeout(200);
  check('报工弹层含作业人', await page.locator('#owner').count() === 1);

  await browser.close();
  server.close();

  console.log('\n======== 冒烟测试结果 ========');
  let f = 0;
  results.forEach(r => { console.log(r[0] + ' ' + r[1]); if (r[0] === '✘') f++; });
  console.log('==============================\n' + (f === 0 ? '✔ 全部通过' : `✘ ${f} 项失败`));
  process.exit(f === 0 ? 0 : 1);
})().catch(e => { console.error('✘ 异常：', e.message); process.exit(1); });