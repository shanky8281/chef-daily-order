/* Chef Daily Order — admin screens, loaded only when an admin opens them.
 * Items & categories (F23–F30), Excel/CSV import & export (F28), users (F2, F3), settings (F19, F21).
 * The server checks every action again; hiding buttons here is only for convenience (N6).
 */
(() => {
  "use strict";
  let ctx;          // helpers from app.js: api, toast, me, el, $, $$, money, fold, reloadCatalog
  let data = null;  // full catalog incl. deleted (admin view)
  let showDeleted = false;
  let changed = false;

  const IMPORT_HEADERS = ["ID", "Categoría (category)", "Nombre (Spanish)", "Name (English)", "Unidad (unit)",
                          "Precio (price)", "Fuente (Market/Manual)", "Palabras clave (market keywords)"];

  // ── Small UI helpers ───────────────────────────────────────────────────────
  function sheet(id, title, { full = false } = {}) {
    let d = document.getElementById(id);
    if (d) d.remove();
    d = ctx.el("dialog", "sheet" + (full ? " full" : ""));
    d.id = id;
    const head = ctx.el("div", "sheet-head");
    const close = ctx.el("button", "icon-btn", "✕");
    close.setAttribute("aria-label", "Close");
    close.addEventListener("click", () => d.close());
    head.append(ctx.el("h2", null, title), close);
    d.append(head);
    d.addEventListener("click", (e) => { if (e.target === d) d.close(); });
    d.addEventListener("close", () => { d.remove(); if (changed && !document.querySelector("#admin-root dialog[open]")) { changed = false; ctx.reloadCatalog(); } });
    document.getElementById("admin-root").append(d);
    return d;
  }

  function field(label, input, hint) {
    const l = ctx.el("label", null, label);
    l.append(input);
    if (hint) l.append(ctx.el("small", "muted small", hint));
    return l;
  }

  function input(name, value = "", attrs = {}) {
    const i = ctx.el("input");
    i.name = name;
    i.value = value ?? "";
    Object.assign(i, attrs);
    return i;
  }

  function select(name, options, value) {
    const s = ctx.el("select");
    s.name = name;
    for (const [v, t] of options) {
      const o = ctx.el("option", null, t);
      o.value = v;
      if (String(v) === String(value)) o.selected = true;
      s.append(o);
    }
    return s;
  }

  function button(text, cls, onClick) {
    const b = ctx.el("button", "btn " + cls, text);
    b.type = "button";
    if (onClick) b.addEventListener("click", onClick);
    return b;
  }

  async function run(fn, form) {
    const err = form && form.querySelector(".form-error");
    if (err) err.textContent = "";
    try {
      return await fn();
    } catch (e) {
      if (err) err.textContent = e.message; else ctx.toast(e.message, 4000);
      throw e;
    }
  }

  const catName = (c) => (c.en && c.en !== c.es ? `${c.es} / ${c.en}` : c.es);

  // ── Items (F23–F27, F29, F30) ──────────────────────────────────────────────
  async function loadData() {
    data = await ctx.api("catalog?all=1");
  }

  async function openItems() {
    await loadData();
    const d = sheet("adm-items", "Items", { full: true });
    const tools = ctx.el("div", "sheet-tools");
    const search = input("q", "", { type: "search", placeholder: "Search… cebolla, onion" });
    const toggle = button(showDeleted ? "Hide deleted" : "Show deleted", "ghost small");
    tools.append(search, button("+ Item", "primary small", () => editItem(null)), button("Categories", "ghost small", openCategories),
                 button("Import", "ghost small", openImport), button("Export", "ghost small", exportItems), toggle);
    const box = ctx.el("div", "rows");
    d.append(tools, box);

    const render = () => {
      const term = ctx.fold(search.value.trim());
      box.replaceChildren();
      for (const c of data.categories) {
        if (c.deleted && !showDeleted) continue;
        const its = data.items.filter((i) => i.categoryId === c.id && (showDeleted || !i.deleted)
          && (!term || ctx.fold(`${i.es} ${i.en}`).includes(term)));
        if (!its.length && term) continue;
        const h = ctx.el("div", "a-cat", `${c.icon} ${catName(c)}${c.deleted ? " (deleted)" : ""}`);
        h.append(ctx.el("span", null, `${its.length}`));
        box.append(h);
        const live = its.filter((i) => !i.deleted);
        for (const it of its) {
          const row = ctx.el("div", "a-row" + (it.deleted ? " deleted" : ""));
          const main = ctx.el("div", "item-row-main");
          const move = ctx.el("div", "move");
          if (!it.deleted && !term) {
            const up = ctx.el("button", null, "↑"), down = ctx.el("button", null, "↓");
            up.setAttribute("aria-label", `Move ${it.es} up`);
            down.setAttribute("aria-label", `Move ${it.es} down`);
            up.disabled = live[0] === it;
            down.disabled = live[live.length - 1] === it;
            up.addEventListener("click", () => moveItem(it, "up", render));
            down.addEventListener("click", () => moveItem(it, "down", render));
            move.append(up, down);
          }
          const names = ctx.el("div");
          names.append(ctx.el("div", "es", it.es), ctx.el("div", "en", [it.en, it.unit].filter(Boolean).join(" · ")));
          main.append(move, names);
          const right = ctx.el("div", "btns");
          right.append(ctx.el("span", "price", ctx.money.format(it.price)),
                       ctx.el("span", "tag" + (it.source === "market" ? " red" : ""), it.source === "market" ? "M" : "✎"));
          right.append(it.deleted ? button("Restore", "ghost small", () => restoreItem(it, render)) : button("Edit", "ghost small", () => editItem(it)));
          row.append(main, right);
          box.append(row);
        }
      }
      if (!box.children.length) box.append(ctx.el("p", "empty", "No items match."));
    };
    search.addEventListener("input", render);
    toggle.addEventListener("click", () => { showDeleted = !showDeleted; toggle.textContent = showDeleted ? "Hide deleted" : "Show deleted"; render(); });
    openItems.render = render;
    render();
    d.showModal();
  }

  async function refreshItems() {
    await loadData();
    changed = true;
    openItems.render?.();
  }

  async function moveItem(it, direction) {
    await run(() => ctx.api(`items/${it.id}/move`, { method: "POST", body: { direction } }));
    await refreshItems();
  }

  async function restoreItem(it) {
    await run(() => ctx.api(`items/${it.id}/restore`, { method: "POST", body: {} }));
    ctx.toast(`${it.es} restored`);
    await refreshItems();
  }

  function editItem(it) {
    const d = sheet("adm-item", it ? "Edit item" : "New item");
    const f = ctx.el("form", "form");
    const cats = data.categories.filter((c) => !c.deleted).map((c) => [c.id, `${c.icon} ${catName(c)}`]);
    const source = select("source", [["manual", "Manual — price set here"], ["market", "Market — daily ODEPA price"]], it?.source || "manual");
    const keywords = field("Market keywords", input("keywords", it?.keywords || "", { placeholder: "e.g. cebolla morada, cebolla" }),
                           "ODEPA product names, most specific first, separated by commas.");
    const two = ctx.el("div", "two");
    two.append(field("Unit", select("unit", data.units.map((u) => [u, u]), it?.unit || "kg")),
               field("Price (CLP)", input("price", it ? it.manualPrice : "", { inputMode: "numeric", required: true })));
    f.append(
      field("Spanish name *", input("es", it?.es || "", { required: true, maxLength: 120 })),
      field("English name", input("en", it?.en || "", { maxLength: 120 })),
      field("Category", select("categoryId", cats, it?.categoryId ?? cats[0]?.[0])),
      two,
      field("Price source", source),
      keywords,
    );
    if (it?.source === "market" && it.marketPrice != null) {
      f.append(ctx.el("p", "muted small", `Market price now: ${ctx.money.format(it.marketPrice)} (${it.marketDate}) — ${it.marketQuote}. ` +
        "The price above is used when no market price is available."));
    }
    const syncKw = () => { keywords.hidden = source.value !== "market"; };
    source.addEventListener("change", syncKw);
    syncKw();
    f.append(ctx.el("p", "form-error"));
    const actions = ctx.el("div", "two");
    actions.append(button("Save", "primary", () => f.requestSubmit()));
    if (it) actions.append(button("Delete", "danger-ghost", () => deleteItem(it, d)));
    f.append(actions);
    if (it) {
      const hist = ctx.el("div", "muted small");
      hist.textContent = it.updatedBy ? `Last changed by ${it.updatedBy}, ${it.updatedAt}` : `Last changed ${it.updatedAt}`;
      const more = ctx.el("button", "link", "Show change history");
      more.type = "button";
      more.addEventListener("click", () => showHistory(it, hist, more));
      f.append(hist, more);
    }
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(f));
      body.categoryId = +body.categoryId;
      await run(() => ctx.api(it ? `items/${it.id}` : "items", { method: it ? "PUT" : "POST", body }), f);
      ctx.toast(it ? "Item saved ✓" : "Item added ✓");
      d.close();
      await refreshItems();
    });
    d.append(f);
    d.showModal();
  }

  async function showHistory(it, box, link) {
    const r = await run(() => ctx.api(`items/${it.id}/history`));
    link.remove();
    box.replaceChildren();
    const label = { add: "added", edit: "edited", delete: "deleted", restore: "restored", move: "moved", import: "imported" };
    for (const c of r.changes) {
      const line = ctx.el("div", null, `${c.at} · ${c.by || "?"} · ${label[c.action] || c.action}`);
      if (c.action === "edit" && c.before && c.after) {
        const diffs = Object.keys(c.after).filter((k) => String(c.before[k]) !== String(c.after[k]))
          .map((k) => `${k}: ${c.before[k]} → ${c.after[k]}`);
        if (diffs.length) line.textContent += ` (${diffs.join(", ")})`;
      }
      box.append(line);
    }
    if (!r.changes.length) box.textContent = "No changes recorded yet.";
  }

  async function deleteItem(it, d) {
    if (!confirm(`Delete "${it.es}"? It disappears from the order page. Past orders keep it, and you can restore it later.`)) return;
    await run(() => ctx.api(`items/${it.id}`, { method: "DELETE", body: {} }));
    ctx.toast(`${it.es} deleted`);
    d.close();
    await refreshItems();
  }

  // ── Categories (F26) ───────────────────────────────────────────────────────
  function openCategories() {
    const d = sheet("adm-cats", "Categories");
    const box = ctx.el("div", "rows");
    const render = () => {
      box.replaceChildren();
      const live = data.categories.filter((c) => !c.deleted);
      for (const c of data.categories) {
        const row = ctx.el("div", "a-row" + (c.deleted ? " deleted" : ""));
        const main = ctx.el("div", "item-row-main");
        const move = ctx.el("div", "move");
        if (!c.deleted) {
          const i = live.indexOf(c);
          for (const [sym, to] of [["↑", i - 1], ["↓", i + 1]]) {
            const b = ctx.el("button", null, sym);
            b.disabled = to < 0 || to >= live.length;
            b.addEventListener("click", async () => {
              const ids = live.map((x) => x.id);
              [ids[i], ids[to]] = [ids[to], ids[i]];
              await run(() => ctx.api("categories/order", { method: "PUT", body: { order: ids } }));
              await refreshItems();
              render();
            });
            move.append(b);
          }
        }
        const names = ctx.el("div");
        names.append(ctx.el("div", "es", `${c.icon} ${c.es}`), ctx.el("div", "en", c.en));
        main.append(move, names);
        const btns = ctx.el("div", "btns");
        btns.append(c.deleted
          ? button("Restore", "ghost small", async () => { await run(() => ctx.api(`categories/${c.id}/restore`, { method: "POST", body: {} })); await refreshItems(); render(); })
          : button("Edit", "ghost small", () => editCategory(c, render)));
        row.append(main, btns);
        box.append(row);
      }
    };
    const add = button("+ Category", "primary small", () => editCategory(null, render));
    const tools = ctx.el("div", "sheet-tools");
    tools.append(add);
    d.append(tools, box);
    render();
    d.showModal();
  }

  function editCategory(c, after) {
    const d = sheet("adm-cat", c ? "Edit category" : "New category");
    const f = ctx.el("form", "form");
    f.append(field("Spanish name *", input("es", c?.es || "", { required: true, maxLength: 80 })),
             field("English name", input("en", c?.en || "", { maxLength: 80 })),
             field("Icon (emoji)", input("icon", c?.icon || "", { maxLength: 8, placeholder: "🥕" })),
             ctx.el("p", "form-error"));
    const actions = ctx.el("div", "two");
    actions.append(button("Save", "primary", () => f.requestSubmit()));
    if (c) actions.append(button("Remove", "danger-ghost", async () => {
      if (!confirm(`Remove the category "${c.es}"?`)) return;
      try {
        await ctx.api(`categories/${c.id}`, { method: "DELETE", body: {} });
      } catch (e) {
        if (e.status !== 409) return ctx.toast(e.message, 4000);
        if (!confirm(e.message)) return;
        await run(() => ctx.api(`categories/${c.id}`, { method: "DELETE", body: { confirm: true } }));
      }
      ctx.toast("Category removed");
      d.close();
      await refreshItems();
      after();
    }));
    f.append(actions);
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(f));
      await run(() => ctx.api(c ? `categories/${c.id}` : "categories", { method: c ? "PUT" : "POST", body }), f);
      d.close();
      await refreshItems();
      after();
    });
    d.append(f);
    d.showModal();
  }

  // ── Import & export (F28) ──────────────────────────────────────────────────
  function exportItems() {
    const cats = Object.fromEntries(data.categories.map((c) => [c.id, c]));
    const rows = data.items.filter((i) => !i.deleted).map((i) => ({
      [IMPORT_HEADERS[0]]: i.id, [IMPORT_HEADERS[1]]: cats[i.categoryId]?.es || "", [IMPORT_HEADERS[2]]: i.es,
      [IMPORT_HEADERS[3]]: i.en, [IMPORT_HEADERS[4]]: i.unit, [IMPORT_HEADERS[5]]: i.manualPrice,
      [IMPORT_HEADERS[6]]: i.source === "market" ? "Market" : "Manual", [IMPORT_HEADERS[7]]: i.keywords,
    }));
    const blob = window.XLSXLite.write(rows, IMPORT_HEADERS);
    const a = ctx.el("a");
    a.href = URL.createObjectURL(blob);
    a.download = `items-${new Date().toISOString().slice(0, 10)}.xlsx`;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  }

  function openImport() {
    const d = sheet("adm-import", "Import items", { full: true });
    const intro = ctx.el("div", "form");
    intro.append(ctx.el("p", "muted",
      "Choose an Excel (.xlsx) or CSV file with a header row. Columns: " + IMPORT_HEADERS.join(", ") +
      ". Rows with an ID update that item; rows without an ID are matched by Spanish name within the category, " +
      "otherwise added. Tip: use Export to get a file in the right format. Nothing changes until you confirm."));
    const file = ctx.el("input");
    file.type = "file";
    file.accept = ".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
    intro.append(file, ctx.el("p", "form-error"));
    const result = ctx.el("div");
    d.append(intro, result);

    file.addEventListener("change", async () => {
      result.replaceChildren();
      const f = file.files[0];
      if (!f) return;
      let rows;
      try {
        rows = await window.XLSXLite.read(f);
      } catch (e) {
        intro.querySelector(".form-error").textContent = e.message || "Could not read the file";
        return;
      }
      intro.querySelector(".form-error").textContent = "";
      const plan = await run(() => ctx.api("items/import/preview", { method: "POST", body: { rows } }));
      showPlan(plan, rows, result, d);
    });
    d.showModal();
  }

  function showPlan(plan, rows, box, d) {
    const s = plan.summary;
    const sum = ctx.el("div", "summary-line");
    sum.append(ctx.el("span", "st-new", `${s.new} new`), ctx.el("span", "st-changed", `${s.changed} changed`),
               ctx.el("span", "st-unchanged", `${s.unchanged} unchanged`), ctx.el("span", "st-error", `${s.errors} with errors (skipped)`));
    if (plan.newCategories.length) sum.append(ctx.el("span", null, `New categories: ${plan.newCategories.join(", ")}`));
    const wrap = ctx.el("div", "table-wrap");
    const t = ctx.el("table", "preview");
    const head = ctx.el("tr");
    for (const h of ["Row", "Status", "Spanish", "English", "Category", "Details"]) head.append(ctx.el("th", null, h));
    t.append(head);
    const label = { new: "New", changed: "Changed", unchanged: "Same", error: "Error" };
    for (const p of plan.rows) {
      const tr = ctx.el("tr");
      const details = p.status === "error" ? p.error
        : p.status === "changed" ? Object.entries(p.changes).map(([k, v]) => `${k}: ${v.from} → ${v.to}`).join("; ")
        : p.status === "new" ? `${p.fields.unit}, ${ctx.money.format(p.fields.price)}, ${p.fields.source}` : "";
      tr.append(ctx.el("td", null, p.row), ctx.el("td", `st-${p.status}`, label[p.status]), ctx.el("td", null, p.es),
                ctx.el("td", null, p.en), ctx.el("td", null, p.category), ctx.el("td", null, details));
      t.append(tr);
    }
    wrap.append(t);
    const go = button(`Import ${s.new + s.changed} item(s)`, "primary wide", async () => {
      go.disabled = true;
      try {
        const r = await run(() => ctx.api("items/import/commit", { method: "POST", body: { rows } }));
        ctx.toast(`Imported: ${r.added} added, ${r.updated} updated`, 4000);
        d.close();
        await refreshItems();
      } finally {
        go.disabled = false;
      }
    });
    go.disabled = s.new + s.changed === 0;
    const actions = ctx.el("div", "sheet-actions");
    actions.append(go);
    box.replaceChildren(sum, actions, wrap);
  }

  // ── Users (F2, F3) ─────────────────────────────────────────────────────────
  async function openUsers() {
    const d = sheet("adm-users", "Users", { full: true });
    const tools = ctx.el("div", "sheet-tools");
    const box = ctx.el("div", "rows");
    const render = async () => {
      const r = await run(() => ctx.api("users"));
      box.replaceChildren();
      for (const u of r.users) {
        const row = ctx.el("div", "a-row" + (u.active ? "" : " deleted"));
        const info = ctx.el("div");
        info.append(ctx.el("div", "es", u.username));
        info.append(ctx.el("div", "en", [u.role === "admin" ? "Admin" : "Chef", u.email || "no e-mail",
          u.active ? null : "inactive", u.locked ? "locked (too many attempts)" : null,
          u.lastLoginAt ? `last login ${u.lastLoginAt.slice(0, 16)}` : "never logged in"].filter(Boolean).join(" · ")));
        row.append(info, button("Edit", "ghost small", () => editUser(u, render)));
        box.append(row);
      }
    };
    tools.append(button("+ User", "primary small", () => editUser(null, render)));
    d.append(tools, box);
    await render();
    d.showModal();
  }

  function editUser(u, after) {
    const d = sheet("adm-user", u ? `Edit ${u.username}` : "New user");
    const f = ctx.el("form", "form");
    const active = input("active", "", { type: "checkbox", checked: u ? u.active : true });
    const activeLabel = ctx.el("label", "check");
    activeLabel.append(active, "Active (can log in)");
    f.append(field("Username *", input("username", u?.username || "", { required: true, maxLength: 40, autocapitalize: "off" })),
             field("E-mail", input("email", u?.email || "", { type: "email", maxLength: 190 }), "Needed for “Forgot password?” e-mails."),
             field("Role", select("role", [["chef", "Chef — sends orders, sees own orders"], ["admin", "Admin — everything"]], u?.role || "chef")),
             activeLabel);
    if (!u) f.append(field("Temporary password", input("password", "", { type: "password", autocomplete: "new-password", minLength: 8 }),
      "Leave empty to e-mail them a link to choose their own password."));
    f.append(ctx.el("p", "form-error"));
    const actions = ctx.el("div", "two");
    actions.append(button("Save", "primary", () => f.requestSubmit()));
    f.append(actions);
    if (u) {
      const reset = ctx.el("div", "two");
      reset.append(
        button("E-mail reset link", "ghost", async () => {
          await run(() => ctx.api(`users/${u.id}/reset`, { method: "POST", body: {} }), f);
          ctx.toast(`Reset link sent to ${u.email}`, 4000);
        }),
        button("Set temporary password", "ghost", async () => {
          const pw = prompt(`New temporary password for ${u.username} (at least 8 characters):`);
          if (!pw) return;
          await run(() => ctx.api(`users/${u.id}/reset`, { method: "POST", body: { password: pw } }), f);
          ctx.toast("Password set. Tell the user, and ask them to change it.", 5000);
        }));
      f.append(ctx.el("p", "muted small", "Resetting logs the user out on all devices."), reset);
    }
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      const body = Object.fromEntries(new FormData(f));
      body.active = active.checked;
      const r = await run(() => ctx.api(u ? `users/${u.id}` : "users", { method: u ? "PUT" : "POST", body }), f);
      ctx.toast(r.emailSent ? "User added — e-mail sent to set a password" : "Saved ✓", 4000);
      d.close();
      await after();
    });
    d.append(f);
    d.showModal();
  }

  // ── Settings (F19, F21) ────────────────────────────────────────────────────
  async function openSettings() {
    const s = await run(() => ctx.api("settings"));
    const d = sheet("adm-settings", "Settings");
    const f = ctx.el("form", "form");
    f.append(field("Restaurant name", input("restaurant", s.restaurant, { required: true, maxLength: 60 })),
             field("Owner's WhatsApp number", input("whatsapp", s.whatsapp, { inputMode: "tel", placeholder: "+56 9 1234 5678" }),
                   "With country code. Leave empty to choose a contact each time."),
             field("Google Sheet ID", input("sheetId", s.sheetId), "The long code in the sheet's address, between /d/ and /edit."),
             field("Sheet tab", input("sheetTab", s.sheetTab)),
             ctx.el("p", "form-error"),
             button("Save", "primary", () => f.requestSubmit()));
    const sync = ctx.el("div", "form");
    const status = ctx.el("p");
    const showStatus = (x) => {
      status.textContent = x.pending === 0 ? "✓ All orders are in the Google Sheet." : `${x.pending} order line(s) waiting to be copied.`;
      if (x.lastError || x.error) status.textContent += ` Last error: ${x.lastError || x.error}`;
    };
    showStatus(s.sync);
    sync.append(ctx.el("h3", null, "Google Sheet copy"), status, button("Copy now", "ghost", async () => {
      const r = await run(() => ctx.api("sync/sheet", { method: "POST", body: {} }));
      showStatus(r);
      ctx.toast(r.error ? "Copy failed — see the error" : `Copied ${r.synced} line(s)`, 4000);
    }), ctx.el("p", "muted small", `Market prices: ODEPA, last market date ${s.marketDate || "—"}.`));
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      await run(() => ctx.api("settings", { method: "PUT", body: Object.fromEntries(new FormData(f)) }), f);
      const me = await ctx.api("auth/me");
      ctx.onMe(me);
      document.querySelectorAll("[data-restaurant]").forEach((n) => (n.textContent = me.settings.restaurant));
      ctx.toast("Settings saved ✓");
      d.close();
    });
    d.append(f, sync);
    d.showModal();
  }

  // ── Entry ──────────────────────────────────────────────────────────────────
  async function loadXlsx() {
    if (window.XLSXLite) return;
    await new Promise((ok, fail) => {
      const s = document.createElement("script");
      s.src = "xlsx.js";
      s.onload = ok;
      s.onerror = () => fail(new Error("Could not load the Excel reader"));
      document.head.append(s);
    });
  }

  window.CDO_Admin = {
    async open(screen, helpers) {
      ctx = helpers;
      if (ctx.me.user.role !== "admin") return ctx.toast("Admins only");
      try {
        if (screen === "items") { await loadXlsx(); await openItems(); }
        else if (screen === "users") await openUsers();
        else if (screen === "settings") await openSettings();
      } catch (e) {
        if (e.status === 401) location.reload();
        else ctx.toast(e.message, 4000);
      }
    },
  };
})();
