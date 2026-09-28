/* Chef Daily Order — the page chefs and admins use every day.
 * Login (F1–F7), order page (F8–F17), review & WhatsApp (F18–F19), outbox (F17, F20), past orders (F22).
 * Admin screens live in admin.js and are loaded only for admins. Design: docs/02-design.md.
 */
(() => {
  "use strict";

  // ── Helpers ────────────────────────────────────────────────────────────────
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  };
  const store = {
    get(key, fallback) { try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; } },
    set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* private mode */ } },
    del(key) { try { localStorage.removeItem(key); } catch { /* ignore */ } },
  };
  const fold = (s) => String(s || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
  const money = new Intl.NumberFormat("es-CL", { style: "currency", currency: "CLP", maximumFractionDigits: 0 });
  const qtyFmt = new Intl.NumberFormat("es-CL", { maximumFractionDigits: 3 });
  const pad = (n) => String(n).padStart(2, "0");
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

  class ApiError extends Error {
    constructor(status, message) { super(message); this.status = status; }
  }

  /** Call the server. Network trouble → ApiError with status 0. */
  async function api(path, { method = "GET", body, keepalive = false } = {}) {
    let res;
    try {
      res = await fetch(`api/${path}`, {
        method, credentials: "same-origin", keepalive, redirect: "error",
        headers: { "X-CDO": "1", ...(body !== undefined ? { "Content-Type": "application/json" } : {}) },
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
    } catch {
      throw new ApiError(0, "No connection. Try again when you have signal.");
    }
    let data = null;
    try { data = await res.json(); } catch { /* not JSON */ }
    if (!res.ok) throw new ApiError(res.status, (data && data.error) || `Server error (${res.status})`);
    if (!data) throw new ApiError(res.status, "Unexpected answer from the server");
    return data;
  }

  function toast(msg, ms = 2400) {
    const t = $("#toast");
    t.textContent = msg;
    t.classList.add("show");
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove("show"), ms);
  }

  // ── State ──────────────────────────────────────────────────────────────────
  let me = null;               // {user, settings}
  let catalog = null;          // {categories, items, marketDate, units, version}
  let byId = {};               // itemId → item (with .cat)
  let order = {};              // itemId → qty
  let draftSentAt = null;
  let myListOnly = false;
  const rows = {};             // itemId → {row, input, total}

  const key = (name) => `cdo.${name}.${me.user.id}`;
  const isAdmin = () => me && me.user.role === "admin";
  const catLabel = (c) => (c.en && c.en !== c.es ? `${c.es} / ${c.en}` : c.es);
  // "Cebolla morada (Onion red)": brackets inside the English name would nest, so they are dropped.
  const itemLabel = (it) => (it.en && it.en !== it.es ? `${it.es} (${it.en.replace(/[()]/g, "").replace(/\s+/g, " ").trim()})` : it.es);

  // ── Boot ───────────────────────────────────────────────────────────────────
  async function boot() {
    bindAuth();
    const reset = location.hash.match(/^#reset=([a-f0-9]{64})$/);
    if (reset) return showAuth("reset", reset[1]);

    try {
      me = await api("auth/me");
      store.set("cdo.me", me);
    } catch (e) {
      const cached = store.get("cdo.me", null);
      if (e.status === 0 && cached) me = cached;          // offline: keep working (F17)
      else { store.del("cdo.me"); return showAuth("login"); }
    }
    await startApp();
  }

  function showAuth(which, token) {
    $("#screen-app").hidden = true;
    document.body.classList.remove("has-app");
    $("#screen-login").hidden = false;
    for (const f of ["login", "forgot", "reset"]) $(`#${f}-form`).hidden = f !== which;
    $$(".form-error").forEach((p) => (p.textContent = ""));
    if (which === "reset") $("#reset-form").dataset.token = token;
    const first = $(`#${which}-form input`);
    if (first) setTimeout(() => first.focus(), 50);
  }

  function formError(form, msg) {
    $(".form-error", form).textContent = msg || "";
  }

  function bindAuth() {
    if (bindAuth.done) return;
    bindAuth.done = true;
    $("#login-form").addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = e.target;
      formError(f, "");
      try {
        me = await api("auth/login", { method: "POST", body: { username: f.username.value.trim(), password: f.password.value } });
        store.set("cdo.me", me);
        f.password.value = "";
        await startApp();
      } catch (err) {
        formError(f, err.message);
      }
    });
    $("#btn-forgot").addEventListener("click", () => showAuth("forgot"));
    $$("[data-back]").forEach((b) => b.addEventListener("click", () => { history.replaceState(null, "", location.pathname); showAuth("login"); }));
    $("#forgot-form").addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = e.target;
      try {
        const r = await api("auth/forgot", { method: "POST", body: { who: f.who.value.trim() } });
        formError(f, "");
        showAuth("login");
        toast(r.message, 5000);
      } catch (err) { formError(f, err.message); }
    });
    $("#reset-form").addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = e.target;
      if (f.password.value !== f.again.value) return formError(f, "The passwords do not match");
      try {
        const r = await api("auth/reset", { method: "POST", body: { token: f.dataset.token, password: f.password.value } });
        history.replaceState(null, "", location.pathname);
        showAuth("login");
        $("#login-form").username.value = r.username;
        toast("Password saved. Log in with your new password.", 4000);
      } catch (err) { formError(f, err.message); }
    });
  }

  async function startApp() {
    $("#screen-login").hidden = true;
    $("#screen-app").hidden = false;
    document.body.classList.add("has-app");
    $$("[data-restaurant]").forEach((n) => (n.textContent = me.settings.restaurant || "Restaurant"));
    $("#today").textContent = new Date().toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", year: "numeric" });
    $("#btn-items").hidden = !isAdmin();
    $$("[data-admin]").forEach((b) => (b.hidden = !isAdmin()));
    $("#menu-user").textContent = `${me.user.username} · ${me.user.role === "admin" ? "Admin" : "Chef"}`;
    await loadCatalog();
    bindApp();
    flushOutbox();
    if ("serviceWorker" in navigator && location.protocol === "https:") navigator.serviceWorker.register("sw.js").catch(() => {});
  }

  // ── Catalog ────────────────────────────────────────────────────────────────
  async function loadCatalog(fresh) {
    try {
      catalog = fresh || await api("catalog");
      store.set("cdo.catalog", catalog);
    } catch (e) {
      if (e.status === 401) return sessionExpired();
      catalog = store.get("cdo.catalog", null);
      if (!catalog) {
        $("#list").replaceChildren(el("div", "empty", "Could not load the item list. Check the connection and reload."));
        return;
      }
      toast("Offline — showing the last item list");
    }
    byId = {};
    const cats = Object.fromEntries(catalog.categories.map((c) => [c.id, c]));
    for (const it of catalog.items) if (cats[it.categoryId]) byId[it.id] = { ...it, cat: cats[it.categoryId] };
    loadDraft();
    renderTabs();
    renderList();
    refreshAll();
  }
  window.CDO_reloadCatalog = () => loadCatalog();

  function loadDraft() {
    const d = store.get(key("draft"), null);
    order = {};
    draftSentAt = null;
    if (!d) return;
    if (d.sentAt && new Date(d.sentAt).toDateString() !== new Date().toDateString()) return;   // sent yesterday → fresh start
    order = Object.fromEntries(Object.entries(d.order || {}).filter(([id, q]) => byId[id] && q > 0));
    draftSentAt = d.sentAt || null;
  }

  function saveDraft() {
    store.set(key("draft"), { order, sentAt: draftSentAt, savedAt: new Date().toISOString() });
  }

  // ── Render ─────────────────────────────────────────────────────────────────
  function renderTabs() {
    const tabs = $("#tabs");
    tabs.replaceChildren();
    const mine = el("button", "tab", "✓ My list");
    mine.dataset.tab = "mine";
    mine.append(el("span", "badge"));
    tabs.append(mine);
    for (const c of catalog.categories) {
      const b = el("button", "tab", `${c.icon} ${c.es}`.trim());
      b.dataset.tab = c.id;
      b.append(el("span", "badge"));
      tabs.append(b);
    }
  }

  function renderList() {
    const list = $("#list");
    list.replaceChildren();
    for (const c of catalog.categories) {
      const its = catalog.items.filter((i) => i.categoryId === c.id);
      if (!its.length) continue;
      const sec = el("section", "cat");
      sec.id = `cat-${c.id}`;
      sec.dataset.cat = c.id;
      const h = el("h2", null, `${c.icon} ${catLabel(c)}`.trim());
      h.append(el("span", "sub"));
      const box = el("div", "items");
      for (const it of its) box.append(renderItem(byId[it.id]));
      sec.append(h, box);
      list.append(sec);
    }
    const empty = el("div", "empty");
    empty.id = "empty";
    empty.hidden = true;
    list.append(empty);
  }

  function renderItem(it) {
    const row = el("div", "item");
    row.dataset.id = it.id;
    row.dataset.search = fold(`${it.es} ${it.en} ${it.cat.es} ${it.cat.en}`);
    const info = el("div");
    info.append(el("div", "es", it.es));
    if (it.en && it.en !== it.es) info.append(el("div", "en", it.en));
    const meta = el("div", "meta");
    meta.append(el("span", null, `${money.format(it.price)}/${it.unit}`));
    if (it.trend) meta.append(el("span", it.trend > 0 ? "trend-up" : "trend-down", `${it.trend > 0 ? "▲" : "▼"}${Math.abs(it.trend)}%`));
    const total = el("span", "line-total");
    meta.append(total);
    info.append(meta);

    const stepper = el("div", "stepper");
    const minus = el("button", null, "−");
    minus.dataset.act = "dec";
    minus.setAttribute("aria-label", `Less ${it.es}`);
    const plus = el("button", null, "+");
    plus.dataset.act = "inc";
    plus.setAttribute("aria-label", `More ${it.es}`);
    const box = el("div", "qty-box");
    const input = el("input", "qty");
    input.inputMode = "decimal";
    input.enterKeyHint = "next";
    input.autocomplete = "off";
    input.setAttribute("aria-label", `${it.es} quantity in ${it.unit}`);
    box.append(input, el("span", "unit", it.unit));
    stepper.append(minus, box, plus);
    row.append(info, stepper);
    rows[it.id] = { row, input, total };
    return row;
  }

  function parseQty(v) {
    const n = parseFloat(String(v).replace(",", ".").replace(/[^\d.]/g, ""));
    return isFinite(n) && n > 0 ? Math.round(n * 1000) / 1000 : 0;
  }

  function setQty(id, q, { fromInput = false } = {}) {
    if (q > 0) order[id] = q; else delete order[id];
    if (draftSentAt) draftSentAt = null;   // edited after sending → a new order
    saveDraft();
    updateRow(id, !fromInput);
    refreshTotals();
  }

  function updateRow(id, writeInput = true) {
    const r = rows[id];
    if (!r) return;
    const q = order[id] || 0;
    r.row.classList.toggle("filled", q > 0);
    if (writeInput) r.input.value = q > 0 ? qtyFmt.format(q) : "";
    r.input.placeholder = "0";
    r.total.textContent = q > 0 ? `= ${money.format(q * byId[id].price)}` : "";
  }

  function totals() {
    let sum = 0, count = 0;
    const perCat = {};
    for (const [id, q] of Object.entries(order)) {
      const it = byId[id];
      if (!it || !(q > 0)) continue;
      const line = q * it.price;
      sum += line;
      count++;
      const pc = (perCat[it.cat.id] ||= { sum: 0, count: 0 });
      pc.sum += line;
      pc.count++;
    }
    return { sum, count, perCat };
  }

  function refreshTotals() {
    const { sum, count, perCat } = totals();
    $("#sum-items").textContent = `${count} item${count === 1 ? "" : "s"}`;
    $("#sum-total").textContent = money.format(sum);
    $("#btn-review").disabled = count === 0;
    for (const t of $$(".tab")) {
      const n = t.dataset.tab === "mine" ? count : perCat[t.dataset.tab]?.count || 0;
      t.querySelector(".badge").textContent = n ? n : "";
    }
    for (const sec of $$(".cat")) {
      const pc = perCat[sec.dataset.cat];
      sec.querySelector("h2 .sub").textContent = pc ? money.format(pc.sum) : "";
    }
    $("#repeat-bar").hidden = count > 0 || store.get(key("history"), []).length === 0;
    if (myListOnly) applyFilter();
  }

  function refreshAll() {
    Object.keys(rows).forEach((id) => { if (!byId[id]) delete rows[id]; else updateRow(id); });
    refreshTotals();
    applyFilter();
  }

  function applyFilter() {
    const term = fold($("#search").value.trim());
    let visible = 0;
    for (const sec of $$(".cat")) {
      let n = 0;
      for (const row of $$(".item", sec)) {
        const show = (!term || row.dataset.search.includes(term)) && (!myListOnly || order[row.dataset.id] > 0);
        row.hidden = !show;
        if (show) n++;
      }
      sec.hidden = n === 0;
      visible += n;
    }
    const empty = $("#empty");
    if (empty) {
      empty.hidden = visible > 0;
      empty.textContent = myListOnly ? "Nothing in your list yet — fill in some quantities." : "No item matches your search.";
    }
    for (const t of $$(".tab")) {
      if (t.dataset.tab === "mine") t.classList.toggle("active", myListOnly);
      else if (myListOnly) t.classList.remove("active");
    }
  }

  function setActiveTab(catId) {
    for (const t of $$(".tab")) {
      const on = t.dataset.tab === String(catId);
      if (on && !t.classList.contains("active")) t.scrollIntoView({ inline: "center", block: "nearest", behavior: "smooth" });
      if (t.dataset.tab !== "mine") t.classList.toggle("active", on);
    }
  }

  // ── Report (F18) ───────────────────────────────────────────────────────────
  function orderNumber(now) {
    const code = me.user.username.replace(/[^A-Za-z0-9]/g, "").slice(0, 2).toUpperCase() || "XX";
    const today = store.get(key("history"), []).filter((h) => new Date(h.at).toDateString() === now.toDateString()).length;
    return `${String(now.getFullYear()).slice(2)}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${code}-${today + 1}`;
  }

  function priceNote() {
    if (catalog.marketDate) {
      const d = new Date(catalog.marketDate + "T12:00:00");
      return `Market prices of ${d.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" })} (ODEPA)`;
    }
    return "Reference prices";
  }

  function buildReport(now = new Date()) {
    const { sum, count, perCat } = totals();
    const orderNo = orderNumber(now);
    const L = [];
    L.push(`🛒 *${(me.settings.restaurant || "Restaurant").toUpperCase()} — DAILY ORDER*`);
    L.push(`🧾 Order #${orderNo}`);
    L.push(`📅 ${now.toLocaleDateString("en-GB", { weekday: "short", day: "2-digit", month: "short", year: "numeric" })}   ⏰ ${pad(now.getHours())}:${pad(now.getMinutes())}`);
    L.push(`👨‍🍳 Chef: ${me.user.username}`);
    L.push("━━━━━━━━━━━━━━━━");
    const lines = [];
    for (const c of catalog.categories) {
      const its = catalog.items.filter((i) => i.categoryId === c.id && order[i.id] > 0);
      if (!its.length) continue;
      L.push("");
      L.push(`${c.icon} *${catLabel(c).toUpperCase()}* (${its.length})`);
      for (const it of its) {
        const q = order[it.id];
        L.push(`• ${itemLabel(it)} — ${qtyFmt.format(q)} ${it.unit} × ${money.format(it.price)} = ${money.format(q * it.price)}`);
        lines.push({ itemId: it.id, category: catLabel(c), es: it.es, en: it.en, unit: it.unit, qty: q, price: it.price });
      }
      L.push(`   _Subtotal: ${money.format(perCat[c.id].sum)}_`);
    }
    L.push("");
    L.push("━━━━━━━━━━━━━━━━");
    L.push(`📦 Items: ${count}`);
    L.push(`💰 *TOTAL (reference): ${money.format(sum)}*`);
    L.push(`📈 ${priceNote()}`);
    L.push("_Reference prices only — final bill may vary._");
    return { text: L.join("\n"), sum, count, orderNo, now, lines };
  }

  function whatsappUrl(text) {
    const num = String(me.settings.whatsapp || "").replace(/\D/g, "");
    return `https://wa.me/${num}?text=${encodeURIComponent(text)}`;
  }

  let reportCtx = null;   // {text, fromHistory}
  function openReport(text, fromHistory = false) {
    reportCtx = { text, fromHistory };
    $("#report").textContent = text;
    $("#btn-new").hidden = fromHistory;
    $("#btn-whatsapp").textContent = fromHistory ? "Send again on WhatsApp" : "Send on WhatsApp";
    $("#dlg-review").showModal();
  }

  // ── Outbox (F17, F20) ──────────────────────────────────────────────────────
  function newUid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    return Array.from(b, (x) => x.toString(16).padStart(2, "0")).join("");
  }

  function recordSent(report) {
    const uid = newUid();
    const { now } = report;
    const history = store.get(key("history"), []);
    history.unshift({ at: now.toISOString(), uid, orderNo: report.orderNo, order: { ...order }, total: report.sum, count: report.count, text: report.text });
    store.set(key("history"), history.slice(0, 20));
    const outbox = store.get(key("outbox"), []);
    outbox.push({
      uid, orderNo: report.orderNo, date: ymd(now), time: `${pad(now.getHours())}:${pad(now.getMinutes())}`,
      priceNote: priceNote(), report: report.text, items: report.lines,
    });
    store.set(key("outbox"), outbox);
    draftSentAt = now.toISOString();
    saveDraft();
    flushOutbox();
  }

  let flushing = false;
  async function flushOutbox() {
    if (!me || flushing) return;
    flushing = true;
    try {
      for (;;) {
        const next = store.get(key("outbox"), [])[0];
        if (!next) break;
        try {
          await api("orders", { method: "POST", body: next, keepalive: true });
        } catch (e) {
          if (e.status === 401) { sessionExpired(); break; }
          if (e.status !== 400 && e.status !== 409) break;   // offline or server trouble: retry later
          console.warn("Order rejected by the server", next.uid, e.message);   // can never succeed; don't block the rest
        }
        store.set(key("outbox"), store.get(key("outbox"), []).filter((o) => o.uid !== next.uid));
      }
    } finally {
      flushing = false;
      showOutboxNote();
    }
  }

  function showOutboxNote() {
    if (!me) return;
    const n = store.get(key("outbox"), []).length;
    const note = $("#outbox-note");
    note.hidden = n === 0;
    note.textContent = `⏳ ${n} order${n === 1 ? "" : "s"} waiting for signal — will upload automatically.`;
  }

  function sessionExpired() {
    store.del("cdo.me");
    const waiting = me ? store.get(key("outbox"), []).length : 0;
    showAuth("login");
    if (me) $("#login-form").username.value = me.user.username;
    formError($("#login-form"), waiting ? `Please log in again to upload ${waiting} waiting order${waiting === 1 ? "" : "s"}.` : "Your session ended. Please log in again.");
  }

  // ── Past orders (F22) ──────────────────────────────────────────────────────
  let historyState = { before: null, user: "" };

  async function openHistory() {
    $("#dlg-history").showModal();
    $("#history-tools").hidden = !isAdmin();
    if (isAdmin() && $("#history-user").options.length === 1) {
      api("users").then((r) => {
        for (const u of r.users) {
          const o = el("option", null, u.username);
          o.value = u.id;
          $("#history-user").append(o);
        }
      }).catch(() => {});
    }
    historyState = { before: null, user: $("#history-user").value };
    $("#history").replaceChildren(el("p", "empty", "Loading…"));
    await loadHistoryPage(true);
  }

  async function loadHistoryPage(first) {
    const box = $("#history");
    const params = new URLSearchParams({ limit: 20 });
    if (!isAdmin()) params.set("mine", "1");
    else if (historyState.user) params.set("user", historyState.user);
    if (historyState.before) params.set("before", historyState.before);
    let data;
    try {
      data = await api(`orders?${params}`);
    } catch (e) {
      if (e.status === 401) return sessionExpired();
      // Offline: show what this phone remembers.
      const local = store.get(key("history"), []);
      box.replaceChildren(el("p", "muted small", "Offline — showing orders saved on this phone."));
      if (!local.length) box.append(el("p", "empty", "No orders yet."));
      local.forEach((h, i) => box.append(historyRow({ when: new Date(h.at), chef: null, count: h.count, total: h.total, orderNo: h.orderNo }, { local: i })));
      return;
    }
    if (first) box.replaceChildren();
    $("#history-more")?.remove();
    if (first && !data.orders.length) box.append(el("p", "empty", "No orders in the last 60 days."));
    for (const o of data.orders) {
      box.append(historyRow({ when: new Date(`${o.date}T${o.time || "00:00"}`), chef: isAdmin() ? o.chef : null, count: o.items, total: o.total, orderNo: o.orderNo }, { id: o.id }));
      historyState.before = o.id;
    }
    if (data.more) {
      const more = el("button", "btn ghost wide", "Load more");
      more.id = "history-more";
      more.addEventListener("click", () => loadHistoryPage(false));
      box.append(more);
    }
  }

  function historyRow(h, ref) {
    const row = el("div", "h-row");
    const info = el("div");
    info.append(el("div", "when", h.when.toLocaleString("en-GB", { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })));
    info.append(el("div", "muted small", `${h.chef ? h.chef + " · " : ""}#${h.orderNo} · ${h.count} items · ${money.format(h.total)}`));
    const btns = el("div", "btns");
    for (const [label, act] of [["View", "view"], ["Reuse", "reuse"]]) {
      const b = el("button", "btn ghost small", label);
      b.dataset.act = act;
      Object.assign(b.dataset, ref.id != null ? { id: ref.id } : { local: ref.local });
      btns.append(b);
    }
    row.append(info, btns);
    return row;
  }

  async function historyAction(btn) {
    let text, lines;
    if (btn.dataset.local != null) {
      const h = store.get(key("history"), [])[+btn.dataset.local];
      text = h.text;
      lines = Object.entries(h.order).map(([itemId, qty]) => ({ itemId: +itemId, qty }));
    } else {
      try {
        const o = await api(`orders/${btn.dataset.id}`);
        text = o.report;
        lines = o.lines;
      } catch (e) {
        return toast(e.message);
      }
    }
    if (btn.dataset.act === "view") {
      $("#dlg-history").close();
      openReport(text, true);
    } else {
      if (Object.keys(order).length && !confirm("Replace the current quantities with this order?")) return;
      const next = {};
      let missing = 0;
      for (const l of lines) {
        if (l.itemId && byId[l.itemId]) next[l.itemId] = (next[l.itemId] || 0) + l.qty;
        else missing++;
      }
      loadOrder(next);
      $("#dlg-history").close();
      toast(missing ? `Order loaded — ${missing} item(s) no longer exist` : "Order loaded — adjust quantities");
    }
  }

  function loadOrder(o) {
    order = Object.fromEntries(Object.entries(o || {}).filter(([id, q]) => byId[id] && q > 0));
    draftSentAt = null;
    saveDraft();
    refreshAll();
  }

  // ── Copy ───────────────────────────────────────────────────────────────────
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const ta = el("textarea");
      ta.value = text;
      document.body.append(ta);
      ta.select();
      document.execCommand("copy");
      ta.remove();
    }
    toast("Report copied ✓");
  }

  // ── Admin screens (admin.js, loaded on demand) ─────────────────────────────
  function loadScript(src) {
    return new Promise((ok, fail) => {
      if ($(`script[src="${src}"]`)) return ok();
      const s = el("script");
      s.src = src;
      s.onload = ok;
      s.onerror = () => fail(new Error("Could not load " + src));
      document.head.append(s);
    });
  }

  async function openAdmin(screen) {
    if (!navigator.onLine) return toast("Admin screens need a connection");
    try {
      await loadScript("admin.js");
      window.CDO_Admin.open(screen, { api, toast, me, el, $, $$, money, fold, reloadCatalog: () => loadCatalog(), onMe: (m) => { me = m; store.set("cdo.me", me); } });
    } catch (e) {
      toast(e.message);
    }
  }

  // ── Events ─────────────────────────────────────────────────────────────────
  function bindApp() {
    if (bindApp.done) return;
    bindApp.done = true;
    const list = $("#list");

    list.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-act]");
      if (!btn) return;
      const id = btn.closest(".item").dataset.id;
      const q = order[id] || 0;
      setQty(id, btn.dataset.act === "inc" ? Math.floor(q) + 1 : Math.max(0, Math.ceil(q) - 1));
    });
    list.addEventListener("input", (e) => {
      if (e.target.classList.contains("qty")) setQty(e.target.closest(".item").dataset.id, parseQty(e.target.value), { fromInput: true });
    });
    list.addEventListener("focusin", (e) => { if (e.target.classList.contains("qty")) e.target.select(); });
    list.addEventListener("focusout", (e) => { if (e.target.classList.contains("qty")) updateRow(e.target.closest(".item").dataset.id); });
    // Enter / "Next" on the keyboard jumps to the next visible item.
    list.addEventListener("keydown", (e) => {
      if (e.key !== "Enter" || !e.target.classList.contains("qty")) return;
      e.preventDefault();
      const inputs = $$(".item:not([hidden]) .qty", list);
      const next = inputs[inputs.indexOf(e.target) + 1];
      if (next) next.focus(); else e.target.blur();
    });

    $("#tabs").addEventListener("click", (e) => {
      const tab = e.target.closest(".tab");
      if (!tab) return;
      if (tab.dataset.tab === "mine") {
        myListOnly = !myListOnly;
        applyFilter();
        window.scrollTo({ top: 0, behavior: "smooth" });
        return;
      }
      myListOnly = false;
      $("#search").value = "";
      applyFilter();
      setActiveTab(tab.dataset.tab);
      $(`#cat-${tab.dataset.tab}`)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });

    $("#search").addEventListener("input", () => {
      myListOnly = false;
      applyFilter();
      window.scrollTo({ top: 0 });
    });

    const spy = new IntersectionObserver((entries) => {
      if (myListOnly) return;
      const vis = entries.filter((en) => en.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (vis[0]) setActiveTab(vis[0].target.dataset.cat);
    }, { rootMargin: "-180px 0px -60% 0px" });
    new MutationObserver(() => $$(".cat").forEach((s) => spy.observe(s))).observe(list, { childList: true });
    $$(".cat").forEach((s) => spy.observe(s));

    $("#btn-review").addEventListener("click", () => openReport(buildReport().text));
    $("#btn-whatsapp").addEventListener("click", () => {
      let text = reportCtx.text;
      if (!reportCtx.fromHistory) {
        const report = buildReport();
        text = report.text;
        recordSent(report);
      }
      window.open(whatsappUrl(text), "_blank");
      toast("Opening WhatsApp…");
    });
    $("#btn-copy").addEventListener("click", () => copyText(reportCtx.text));
    if (navigator.share) {
      $("#btn-share").hidden = false;
      $("#btn-share").addEventListener("click", () => navigator.share({ text: reportCtx.text }).catch(() => {}));
    }
    $("#btn-new").addEventListener("click", () => {
      if (!draftSentAt && !confirm("This order has not been sent yet. Clear it anyway?")) return;
      loadOrder({});
      $("#dlg-review").close();
      window.scrollTo({ top: 0 });
      toast("New order started");
    });
    $("#btn-repeat").addEventListener("click", async () => {
      const last = store.get(key("history"), [])[0];
      if (!last) return;
      loadOrder(last.order);
      toast("Last order loaded — adjust quantities");
    });

    $("#btn-history").addEventListener("click", openHistory);
    $("#history-user").addEventListener("change", () => { historyState = { before: null, user: $("#history-user").value }; loadHistoryPage(true); });
    $("#history").addEventListener("click", (e) => { const b = e.target.closest("button[data-act]"); if (b) historyAction(b); });

    $("#btn-items").addEventListener("click", () => openAdmin("items"));
    $("#btn-menu").addEventListener("click", () => $("#dlg-menu").showModal());
    $("#dlg-menu").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-menu]");
      if (!b) return;
      $("#dlg-menu").close();
      const what = b.dataset.menu;
      if (what === "password") { $("#password-form").reset(); formError($("#password-form"), ""); $("#dlg-password").showModal(); }
      else if (what === "logout") logout();
      else openAdmin(what);
    });

    $("#password-form").addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = e.target;
      if (f.new.value !== f.again.value) return formError(f, "The new passwords do not match");
      try {
        await api("auth/password", { method: "POST", body: { current: f.current.value, new: f.new.value } });
        $("#dlg-password").close();
        toast("Password changed ✓");
      } catch (err) { formError(f, err.message); }
    });

    $$("dialog [data-close]").forEach((b) => b.addEventListener("click", () => b.closest("dialog").close()));
    $$("dialog").forEach((d) => d.addEventListener("click", (e) => { if (e.target === d) d.close(); }));

    window.addEventListener("online", flushOutbox);
    document.addEventListener("visibilitychange", async () => {
      if (document.hidden || !me) return;
      flushOutbox();
      // Pick up item changes made by an admin (F33) without losing the draft.
      try {
        const fresh = await api("catalog");
        if (fresh.version !== catalog?.version) loadCatalog(fresh);
      } catch { /* offline: keep the current list */ }
    });
  }

  async function logout() {
    const waiting = store.get(key("outbox"), []).length;
    if (waiting && !confirm(`${waiting} order(s) are not uploaded yet. They will upload the next time you log in on this phone. Log out anyway?`)) return;
    try { await api("auth/logout", { method: "POST" }); } catch { /* offline: cookie stays until it expires */ }
    store.del("cdo.me");
    location.reload();
  }

  // A reset link opened in a tab where the page is already open changes only the #part.
  window.addEventListener("hashchange", () => {
    const reset = location.hash.match(/^#reset=([a-f0-9]{64})$/);
    if (reset) showAuth("reset", reset[1]);
  });

  document.addEventListener("DOMContentLoaded", () => boot().catch((err) => {
    console.error(err);
    $("#screen-app").hidden = true;
    $("#screen-login").hidden = false;
    formError($("#login-form"), "Something went wrong loading the page. Please reload.");
  }));
})();
