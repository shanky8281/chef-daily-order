/* Browser tests: drive the real page in Chromium like a chef and an admin would.
 *   npm install && npx playwright install chromium   (once)
 *   node tests/browser.cjs
 * Uses the same database settings as tests/run.php (CDO_TEST_DSN, CDO_TEST_USER, CDO_TEST_PASSWORD).
 * Set PW_CHROMIUM to use an already-installed Chromium.
 */
const { chromium } = require("playwright");
const { spawn } = require("child_process");
const fs = require("fs");
const os = require("os");
const path = require("path");

let failures = 0, checks = 0;
function check(name, ok, detail) {
  checks++;
  if (ok) return console.log(`  ok    ${name}`);
  failures++;
  console.log(`  FAIL  ${name}${detail === undefined ? "" : " → " + JSON.stringify(detail)}`);
}

function startServer() {
  return new Promise((resolve, reject) => {
    const p = spawn("php", [path.join(__dirname, "run.php"), "--serve"], { stdio: ["ignore", "pipe", "inherit"] });
    let buf = "";
    p.stdout.on("data", (d) => {
      buf += d;
      const m = buf.match(/READY (\S+) (\S+)/);
      if (m) resolve({ proc: p, base: m[1], mailLog: m[2] });
    });
    p.on("exit", (code) => reject(new Error(`test server exited (${code}): ${buf}`)));
  });
}

(async () => {
  const server = await startServer();
  const BASE = server.base;
  const browser = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
  const downloads = fs.mkdtempSync(path.join(os.tmpdir(), "cdo-dl-"));
  const newPhone = async () => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, acceptDownloads: true });
    const page = await ctx.newPage();
    page.errors = [];
    page.on("pageerror", (e) => page.errors.push(e.message));
    // WhatsApp opens in a new window; remember the address instead.
    await page.addInitScript(() => { window.open = (url) => { window.__opened = url; return null; }; });
    page.on("dialog", (d) => d.accept(page.nextPrompt ?? undefined));
    return { ctx, page };
  };
  const login = async (page, user, pass) => {
    await page.goto(BASE + "/");
    await page.waitForSelector("#login-form:not([hidden])");
    await page.fill("#login-form [name=username]", user);
    await page.fill("#login-form [name=password]", pass);
    await page.click("#login-form button.primary");
  };
  const api = (page, p, opts = {}) => page.evaluate(async ([p, opts]) => {
    const r = await fetch("api/" + p, { method: opts.method || "GET", headers: { "X-CDO": "1", "Content-Type": "application/json" },
      body: opts.body ? JSON.stringify(opts.body) : undefined });
    return { status: r.status, body: await r.json() };
  }, [p, opts]);

  try {
    // ── Login (F1) ──
    console.log("\n── login");
    const { ctx: adminCtx, page: a } = await newPhone();
    await login(a, "Shankar", "wrong-password");
    await a.waitForSelector("#login-form .form-error:not(:empty)");
    check("F1 wrong password shows a message", (await a.textContent("#login-form .form-error")) === "Wrong username or password");
    await a.fill("#login-form [name=password]", "admin-pass-1");
    await a.click("#login-form button.primary");
    await a.waitForSelector(".item");
    check("F1 admin reaches the order page", await a.isVisible("#screen-app"));
    check("F23 admin sees the Items button", await a.isVisible("#btn-items"));

    // ── Usability & security basics (N1, N6) ──
    console.log("\n── touch targets & security");
    const small = await a.$$eval("button:not([hidden]), input.qty", (els) => els
      .filter((e) => e.offsetParent !== null && !e.closest("dialog"))
      .map((e) => { const r = e.getBoundingClientRect(); return { t: e.textContent.trim() || e.className, w: Math.round(r.width), h: Math.round(r.height) }; })
      .filter((r) => r.w < 44 || r.h < 44));
    check("N1 every button on the order page is at least 44×44 px", small.length === 0, small.slice(0, 5));
    const heads = await a.evaluate(async () => {
      const r = await fetch("api/auth/me");
      return { nosniff: r.headers.get("x-content-type-options"), cache: r.headers.get("cache-control") };
    });
    check("N6 API answers are never cached and not sniffed", heads.nosniff === "nosniff" && heads.cache === "no-store", heads);
    const cookie = (await adminCtx.cookies()).find((k) => k.name === "cdo_session");
    check("N6 session cookie is HttpOnly and SameSite=Lax", cookie && cookie.httpOnly && cookie.sameSite === "Lax", cookie);

    // ── Order page (F8–F15) ──
    console.log("\n── order page");
    const first = a.locator(".item").first();
    check("F9 Spanish name first, English below", (await first.locator(".es").textContent()) === "Cebolla morada" && (await first.locator(".en").textContent()) === "Onion (red)");
    const esSize = await first.locator(".es").evaluate((e) => parseFloat(getComputedStyle(e).fontSize));
    const enSize = await first.locator(".en").evaluate((e) => parseFloat(getComputedStyle(e).fontSize));
    check("F9 Spanish name is larger", esSize > enSize, [esSize, enSize]);
    const bg = await a.evaluate(() => getComputedStyle(document.body).backgroundColor);
    check("N2 black background", bg === "rgb(0, 0, 0)", bg);
    await a.fill("#search", "onion");
    const found = await a.$$eval(".item:not([hidden]) .es", (e) => e.map((x) => x.textContent));
    check("F10 search in English", found.length === 3 && found.includes("Cebollín"), found);
    await a.fill("#search", "cilantro");
    const cil = await a.$$eval(".item:not([hidden]) .es", (e) => e.map((x) => x.textContent));
    check("F10 search in Spanish", cil.join() === "Cilantro,Cilantro molido", cil);
    await a.fill("#search", "aji");
    check("F10 search ignores accents", (await a.$$eval(".item:not([hidden]) .es", (e) => e.map((x) => x.textContent))).includes("Ají verde"));
    await a.fill("#search", "");
    await first.locator(".qty").fill("2,5");
    await a.click('.item[data-id="3"] [data-act=inc]');
    await a.click('.item[data-id="3"] [data-act=inc]');
    check("F12 decimal typed with a comma, and + adds whole units", (await first.locator(".qty").inputValue()) === "2,5"
      && (await a.locator('.item[data-id="3"] .qty').inputValue()) === "2");
    await a.click('.item[data-id="3"] [data-act=dec]');
    check("F12 − removes one unit", (await a.locator('.item[data-id="3"] .qty').inputValue()) === "1");
    await a.click('.item[data-id="3"] [data-act=inc]');
    const install = await a.evaluate(async () => {
      const link = document.querySelector('link[rel="manifest"]');
      const m = await (await fetch(link.href)).json();
      const sw = await fetch("sw.js");
      return { name: m.name, display: m.display, icons: m.icons.length, sw: sw.ok };
    });
    check("N4 installable: manifest (standalone, icon) and offline worker are served", install.display === "standalone" && install.icons > 0 && install.sw, install);
    check("F13 totals update", (await a.textContent("#sum-items")) === "2 items" && (await a.textContent("#sum-total")).includes("4.611"), await a.textContent("#sum-total"));
    await a.click('.tab[data-tab="mine"]');
    check("F14 My list shows only filled items", (await a.locator(".item:not([hidden])").count()) === 2);
    await a.click('.tab[data-tab="mine"]');
    await a.reload();
    await a.waitForSelector(".item");
    check("F15 draft survives a reload", (await first.locator(".qty").inputValue()) === "2,5");

    // ── Send offline, upload later (F17–F20) ──
    console.log("\n── send offline");
    await adminCtx.setOffline(true);
    await a.click("#btn-review");
    const report = await a.textContent("#report");
    check("F18 report: Spanish (English) names", report.includes("• Cebolla morada (Onion red) — 2,5 kg × $950 = $2.375"), report.split("\n").slice(7, 9));
    check("F18 report: category, chef, order number", report.includes("VERDURAS / VEGETABLES") && report.includes("Chef: Shankar") && /Order #\d{6}-SH-1/.test(report));
    await a.click("#btn-whatsapp");
    check("F19 WhatsApp opens with the report", decodeURIComponent(await a.evaluate(() => window.__opened || "")).includes("DAILY ORDER"));
    await a.click("#dlg-review [data-close]");
    await a.waitForSelector("#outbox-note:not([hidden])");
    check("F17 offline: order waits on the phone", (await a.textContent("#outbox-note")).includes("1 order waiting"));
    await adminCtx.setOffline(false);
    await a.evaluate(() => window.dispatchEvent(new Event("online")));
    await a.waitForSelector("#outbox-note", { state: "hidden" });
    const hist = await api(a, "orders");
    check("F17 back online: uploaded exactly once", hist.status === 200 && hist.body.orders.length === 1, hist.body);
    await a.evaluate(() => window.dispatchEvent(new Event("online")));
    await a.waitForTimeout(500);
    check("F17 no duplicate on a second retry", (await api(a, "orders")).body.orders.length === 1);

    // ── Past orders (F22) ──
    console.log("\n── past orders");
    await a.click("#dlg-review [data-close]").catch(() => {});
    await a.click("#btn-new").catch(() => {});
    await a.evaluate(() => { localStorage.removeItem("cdo.draft.1"); });
    await a.reload();
    await a.waitForSelector(".item");
    check("F16 Repeat last order is offered on an empty list", await a.isVisible("#btn-repeat"));
    await a.click("#btn-history");
    await a.waitForSelector("#history .h-row");
    check("F22 past orders listed", (await a.locator("#history .h-row").count()) === 1);
    await a.click('#history [data-act="reuse"]');
    await a.waitForFunction(() => document.querySelector("#sum-items").textContent === "2 items");
    check("F22 reuse loads the quantities", (await first.locator(".qty").inputValue()) === "2,5" && (await a.textContent("#sum-items")) === "2 items");

    // ── Item manager (F23–F28) ──
    console.log("\n── item manager");
    await a.click("#btn-items");
    await a.waitForSelector("#adm-items .a-row");
    await a.click("#adm-items >> text=+ Item");
    await a.fill("#adm-item [name=es]", "Chirivía");
    await a.fill("#adm-item [name=en]", "Parsnip");
    await a.fill("#adm-item [name=price]", "1.500");
    await a.click("#adm-item >> text=Save");
    await a.waitForSelector("#adm-item", { state: "detached" });
    await a.fill("#adm-items [name=q]", "chirivia");
    check("F24 added item appears in the manager", (await a.locator("#adm-items .a-row .es").allTextContents()).join() === "Chirivía");
    check("F24 price typed as 1.500 is $1.500", (await a.locator("#adm-items .a-row .price").first().textContent()).includes("1.500"));
    await a.click("#adm-items .a-row >> text=Edit");
    await a.fill("#adm-item [name=price]", "1800");
    await a.click("#adm-item >> text=Show change history");
    await a.waitForTimeout(300);
    check("F30 change history shown", (await a.textContent("#adm-item")).includes("Shankar · added"));
    await a.click("#adm-item >> text=Save");
    await a.waitForSelector("#adm-item", { state: "detached" });
    await a.click("#adm-items .sheet-head .icon-btn");
    await a.waitForTimeout(500);
    await a.fill("#search", "parsnip");
    check("F24 new item on the order page after closing the manager", (await a.$$eval(".item:not([hidden]) .meta span", (e) => e[0]?.textContent)) === "$1.800/kg");
    await a.fill("#search", "");

    // Export then import the same file: nothing changes (F28).
    await a.click("#btn-items");
    await a.waitForSelector("#adm-items .a-row");
    const [dl] = await Promise.all([a.waitForEvent("download"), a.click("#adm-items >> text=Export")]);
    const xlsx = path.join(downloads, dl.suggestedFilename());
    await dl.saveAs(xlsx);
    check("F28 export downloads an .xlsx", /^items-\d{4}-\d{2}-\d{2}\.xlsx$/.test(dl.suggestedFilename()) && fs.statSync(xlsx).size > 2000);
    await a.click("#adm-items >> text=Import");
    await a.setInputFiles("#adm-import input[type=file]", xlsx);
    await a.waitForSelector("#adm-import .summary-line");
    const summary = await a.textContent("#adm-import .summary-line");
    check("F28 re-importing the export: all 103 unchanged", summary.includes("0 new") && summary.includes("0 changed") && summary.includes("103 unchanged") && summary.includes("0 with errors"), summary);
    await a.click("#adm-import .sheet-head .icon-btn");

    // CSV with a new item and a price change (F28).
    const csv = path.join(downloads, "new.csv");
    fs.writeFileSync(csv, "Categoría;Nombre;Name;Unidad;Precio;Fuente\nVerduras;Rúcula;Rocket;bunch;990;Manual\nVerduras;Chirivía;Parsnip;kg;2.000;Manual\n", "latin1");
    await a.click("#adm-items >> text=Import");
    await a.setInputFiles("#adm-import input[type=file]", csv);
    await a.waitForSelector("#adm-import .summary-line");
    const s2 = await a.textContent("#adm-import .summary-line");
    check("F28 CSV (Excel Windows encoding) preview: 1 new, 1 changed", s2.includes("1 new") && s2.includes("1 changed"), s2);
    check("F28 preview shows the price change", (await a.textContent("#adm-import table")).includes("price: 1800 → 2000"));
    await a.click("#adm-import >> text=Import 2 item(s)");
    await a.waitForSelector("#adm-import", { state: "detached" });
    await a.fill("#adm-items [name=q]", "rucula");
    check("F28 imported item in the manager", (await a.locator("#adm-items .a-row .es").allTextContents()).join() === "Rúcula");

    // Delete (F25)
    await a.click("#adm-items .a-row >> text=Edit");
    await a.click("#adm-item >> text=Delete");
    await a.waitForSelector("#adm-item", { state: "detached" });
    await a.click("#adm-items .sheet-head .icon-btn");
    await a.waitForTimeout(500);
    await a.fill("#search", "rucula");
    check("F25 deleted item leaves the order page", (await a.locator(".item:not([hidden])").count()) === 0);
    await a.fill("#search", "");

    // An item name that looks like code must show as plain text (N6).
    const evil = '<img src=x onerror="window.__xss=1">';
    const made = await api(a, "items", { method: "POST", body: { es: evil, en: "test", unit: "kg", price: 1, categoryId: 1 } });
    await a.reload();
    await a.waitForSelector(".item");
    const shown = await a.locator(`.item[data-id="${made.body.id}"] .es`).textContent();
    check("N6 item names are shown as text, never run as code", shown === evil && (await a.evaluate(() => window.__xss)) === undefined
      && (await a.locator(".item img").count()) === 0, shown);
    await api(a, `items/${made.body.id}`, { method: "DELETE", body: {} });
    await a.reload();
    await a.waitForSelector(".item");

    // ── Settings (F19) ──
    console.log("\n── settings & users");
    await a.click("#btn-menu");
    await a.click('[data-menu="settings"]');
    await a.waitForSelector("#adm-settings [name=whatsapp]");
    await a.fill("#adm-settings [name=whatsapp]", "+56 9 8765 4321");
    await a.click("#adm-settings >> text=Save");
    await a.waitForSelector("#adm-settings", { state: "detached" });
    await a.click("#btn-review");
    await a.click("#btn-whatsapp");
    check("F19 WhatsApp goes to the owner's number", (await a.evaluate(() => window.__opened)).startsWith("https://wa.me/56987654321?text="));
    await a.click("#dlg-review [data-close]");

    // ── Users (F2, F3) ──
    await a.click("#btn-menu");
    await a.click('[data-menu="users"]');
    await a.click("#adm-users >> text=+ User");
    await a.fill("#adm-user [name=username]", "Pedro");
    await a.fill("#adm-user [name=email]", "pedro@example.com");
    await a.fill("#adm-user [name=password]", "pedro-pass-1");
    await a.click("#adm-user >> text=Save");
    await a.waitForSelector("#adm-users .a-row >> text=Pedro");
    check("F2 admin adds a chef", true);
    await a.click("#adm-users .sheet-head .icon-btn");

    // ── Chef view (roles, F22, F4, F5) ──
    const { ctx: chefCtx, page: c } = await newPhone();
    await login(c, "Pedro", "pedro-pass-1");
    await c.waitForSelector(".item");
    check("N6 chef has no Items button", !(await c.isVisible("#btn-items")));
    await c.click("#btn-menu");
    check("N6 chef menu has no admin entries", !(await c.isVisible('[data-menu="users"]')) && !(await c.isVisible('[data-menu="settings"]')));
    await c.click('[data-menu="password"]');
    await c.fill("#password-form [name=current]", "pedro-pass-1");
    await c.fill("#password-form [name=new]", "pedro-pass-2");
    await c.fill("#password-form [name=again]", "pedro-pass-2");
    await c.click("#password-form button.primary");
    await c.waitForSelector("#dlg-password", { state: "hidden" });
    check("F4 chef changes own password", (await api(c, "auth/me")).status === 200);
    await c.click("#btn-history");
    await c.waitForSelector("#history .empty");
    check("F22 chef sees only own orders (none yet)", (await c.locator("#history .h-row").count()) === 0 && !(await c.isVisible("#history-user")));
    await c.click("#dlg-history [data-close]");
    await c.click("#btn-menu");
    await c.click('[data-menu="logout"]');
    await c.waitForSelector("#login-form:not([hidden])");
    check("F6 log out returns to the login page", true);

    // Forgot password by e-mail (F5)
    await c.click("#btn-forgot");
    await c.fill("#forgot-form [name=who]", "pedro@example.com");
    await c.click("#forgot-form button.primary");
    await c.waitForSelector("#login-form:not([hidden])");
    const mail = fs.readFileSync(server.mailLog, "utf8").trim().split("\n").map(JSON.parse).pop();
    const link = mail.body.match(/#reset=[a-f0-9]{64}/)[0];
    check("F5 reset e-mail sent", mail.to === "pedro@example.com");
    await c.goto(BASE + "/" + link);
    await c.waitForSelector("#reset-form:not([hidden])");
    await c.fill("#reset-form [name=password]", "pedro-pass-3");
    await c.fill("#reset-form [name=again]", "pedro-pass-3");
    await c.click("#reset-form button.primary");
    await c.waitForSelector("#login-form:not([hidden])");
    check("F5 after reset the username is filled in", (await c.inputValue("#login-form [name=username]")) === "Pedro");
    await c.fill("#login-form [name=password]", "pedro-pass-3");
    await c.click("#login-form button.primary");
    await c.waitForSelector(".item");
    check("F5 log in with the new password", true);

    // Session ends while an order waits on the phone (F17): log in again, then it uploads.
    console.log("\n── session ends while offline");
    await chefCtx.setOffline(true);
    await c.locator(".item").first().locator(".qty").fill("4");
    await c.click("#btn-review");
    await c.click("#btn-whatsapp");
    await c.click("#dlg-review [data-close]");
    await c.waitForSelector("#outbox-note:not([hidden])");
    const pedro = (await api(a, "users")).body.users.find((u) => u.username === "Pedro");
    await api(a, `users/${pedro.id}/reset`, { method: "POST", body: { password: "pedro-pass-4" } });   // ends Pedro's sessions
    await chefCtx.setOffline(false);
    await c.evaluate(() => window.dispatchEvent(new Event("online")));
    await c.waitForSelector("#login-form:not([hidden])");
    const msg = await c.textContent("#login-form .form-error");
    check("F17 expired session: the chef is asked to log in to upload the waiting order", msg === "Please log in again to upload 1 waiting order.", msg);
    await c.fill("#login-form [name=password]", "pedro-pass-4");
    await c.click("#login-form button.primary");
    await c.waitForSelector(".item");
    await c.waitForFunction((id) => JSON.parse(localStorage.getItem(`cdo.outbox.${id}`) || "[]").length === 0, pedro.id);
    const pedroOrders = (await api(a, `orders?user=${pedro.id}`)).body.orders;
    check("F17 after logging in, the waiting order uploads once", pedroOrders.length === 1 && pedroOrders[0].chef === "Pedro", pedroOrders);

    // Load time on a 4G connection (N5): a new phone with an empty cache.
    console.log("\n── speed on 4G");
    const { ctx: slowCtx, page: f } = await newPhone();
    const cdp = await slowCtx.newCDPSession(f);
    await cdp.send("Network.enable");
    await cdp.send("Network.emulateNetworkConditions", { offline: false, latency: 100, downloadThroughput: 9e6 / 8, uploadThroughput: 1.5e6 / 8 });
    let t0 = Date.now();
    await f.goto(BASE + "/");
    await f.waitForSelector("#login-form:not([hidden])");
    const tLogin = Date.now() - t0;
    await f.fill("#login-form [name=username]", "Pedro");
    await f.fill("#login-form [name=password]", "pedro-pass-4");
    t0 = Date.now();
    await f.click("#login-form button.primary");
    await f.waitForSelector(".item");
    const tList = Date.now() - t0;
    t0 = Date.now();
    await f.reload();
    await f.waitForSelector(".item");
    const tReopen = Date.now() - t0;
    console.log(`        4G (9 Mbps, 100 ms): login page ${tLogin} ms · log in → item list ${tList} ms · reopen logged in ${tReopen} ms`);
    check("N5 login page loads in under 2 s on 4G", tLogin < 2000, tLogin);
    check("N5 item list appears in under 2 s after logging in on 4G", tList < 2000, tList);
    check("N5 reopening the page (logged in) takes under 2 s on 4G", tReopen < 2000, tReopen);
    check("no JavaScript errors (4G phone)", f.errors.length === 0, f.errors);
    await slowCtx.close();

    check("no JavaScript errors (admin)", a.errors.length === 0, a.errors);
    check("no JavaScript errors (chef)", c.errors.length === 0, c.errors);
    await chefCtx.close();
    await adminCtx.close();
  } catch (e) {
    failures++;
    console.log("  FAIL  crashed: " + e.message);
  } finally {
    await browser.close();
    server.proc.kill();
    fs.rmSync(downloads, { recursive: true, force: true });
  }
  console.log(`\n${failures ? failures + " FAILED" : "ALL PASSED"} (${checks} checks)`);
  process.exit(failures ? 1 : 0);
})();
