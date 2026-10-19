const { chromium, firefox } = require(
  process.env.PLAYWRIGHT_MODULE || "playwright",
);
const assert = require("node:assert/strict"),
  fs = require("node:fs"),
  path = require("node:path");
const base = process.env.BASE_URL || "http://127.0.0.1:8085",
  out = path.resolve(__dirname, "../test-output");
fs.mkdirSync(out, { recursive: true });
async function run(engine, name, options) {
  const browser = await engine.launch({ headless: true, ...options }),
    context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
      timezoneId: "Europe/Kyiv",
    }),
    page = await context.newPage();
  const errors = [],
    checks = [],
    sku = "QA-" + name.toUpperCase() + "-" + Date.now();
  let partId;
  page.on("pageerror", (e) => errors.push(e.message));
  const row = () => page.locator("tr[data-part-id]").filter({ hasText: sku });
  const post = async (p, route, data) =>
    p.request.post(base + "/?page=" + route, {
      form: {
        ...data,
        csrf: await p.locator("input[name=csrf]").first().getAttribute("value"),
      },
      maxRedirects: 0,
    });
  async function check(label, fn) {
    await fn();
    checks.push(label);
    console.log(name + ": " + label);
  }
  async function move(type, qty, from = "1", to = "3") {
    await page.goto(base + "/?page=movement");
    await page.locator("#type").selectOption(type);
    await page.locator("#part_id").selectOption(partId);
    if (type !== "receipt")
      await page.locator("#from_location_id").selectOption(from);
    if (type !== "issue")
      await page.locator("#to_location_id").selectOption(to);
    await page.locator("#quantity").fill(String(qty));
    await page.locator("#note").fill("ТО автопарку — " + name);
    await page.getByRole("button", { name: "Провести операцію" }).click();
  }
  try {
    await page.goto(base);
    await check("Головна сторінка та дані MySQL", async () => {
      assert.equal(await page.locator(".metric").count(), 4);
      assert.ok((await page.locator("tbody tr").count()) >= 6);
    });
    await page.getByRole("link", { name: "＋ Додати деталь" }).click();
    await page.locator("#sku").fill(sku);
    await page.locator("#name").fill("Фільтр паливний Bosch");
    await page.locator("#category").fill("Фільтри");
    await page.locator("#min_qty").fill("8");
    if (name === "Chrome")
      await page.screenshot({
        path: path.join(out, "02-create.png"),
        fullPage: true,
      });
    await check("Створення комплектуючої через HTML форму", async () => {
      await page.getByRole("button", { name: "Зберегти", exact: true }).click();
      await row().waitFor();
      partId = await row().getAttribute("data-part-id");
      assert.match(await row().innerText(), /Відсутні/);
    });
    await check("Унікальний артикул контролюється на сервері", async () => {
      let r = await post(page, "part-form", {
        action: "save_part",
        id: "",
        sku,
        name: "Дублікат деталі",
        category: "Фільтри",
        min_qty: "1",
      });
      assert.equal(r.status(), 422);
      assert.match(await r.text(), /уже існує/);
    });
    await check("Редагування зберігається після перезавантаження", async () => {
      await row()
        .getByRole("link", { name: "Редагувати " + sku, exact: true })
        .click();
      await page.locator("#name").fill("Фільтр паливний Bosch Euro 6");
      await page.getByRole("button", { name: "Зберегти", exact: true }).click();
      await page.reload();
      assert.match(await row().innerText(), /Bosch Euro 6/);
    });
    await check("Пошук і скидання фільтрів", async () => {
      await page.locator("input[name=q]").fill(sku);
      await page.getByRole("button", { name: "Знайти", exact: true }).click();
      assert.equal(await page.locator("tbody tr").count(), 1);
      await page.getByRole("link", { name: "Скинути", exact: true }).click();
    });
    await check("Надходження 20 одиниць", async () => {
      await move("receipt", 20, "1", "1");
      await page.getByRole("status").waitFor();
      await page.goto(base);
      assert.match(await row().locator(".stock-number").innerText(), /20/);
    });
    await check("Повторне завантаження не дублює операцію", async () => {
      await page.reload();
      assert.match(await row().locator(".stock-number").innerText(), /20/);
    });
    await check("Списання 3 одиниць", async () => {
      await move("issue", 3);
      await page.goto(base);
      assert.match(await row().locator(".stock-number").innerText(), /17/);
    });
    await check("Переміщення 5 одиниць між місцями", async () => {
      await move("transfer", 5);
      await page.goto(base);
      assert.match(await row().innerText(), /A-01\s+12/);
      assert.match(await row().innerText(), /B-01\s+5/);
      assert.match(await row().locator(".stock-number").innerText(), /17/);
    });
    await check(
      "Надмірне списання відхиляється без зміни залишку",
      async () => {
        await move("issue", 99);
        await page.getByRole("alert").waitFor();
        assert.match(await page.getByRole("alert").innerText(), /доступно 12/);
        if (name === "Chrome")
          await page.screenshot({
            path: path.join(out, "04-validation.png"),
            fullPage: true,
          });
        await page.goto(base);
        assert.match(await row().locator(".stock-number").innerText(), /17/);
      },
    );
    await check("Фільтр місця та низького запасу", async () => {
      await page.locator("select[name=location]").selectOption("3");
      await page.getByRole("button", { name: "Знайти", exact: true }).click();
      assert.equal(await row().count(), 1);
      await page.locator("select[name=status]").selectOption("low");
      await page.getByRole("button", { name: "Знайти", exact: true }).click();
      assert.equal(await row().count(), 0);
      await page.goto(base);
    });
    await check("Сервер відхиляє нуль, дріб та однакові місця", async () => {
      for (let quantity of ["0", "1.5"])
        assert.equal(
          (
            await post(page, "movement", {
              action: "move_stock",
              type: "receipt",
              part_id: partId,
              to_location_id: "1",
              quantity,
              note: "Перевірка",
            })
          ).status(),
          422,
        );
      assert.equal(
        (
          await post(page, "movement", {
            action: "move_stock",
            type: "transfer",
            part_id: partId,
            from_location_id: "1",
            to_location_id: "1",
            quantity: "1",
            note: "Перевірка",
          })
        ).status(),
        422,
      );
    });
    await check("CSRF запит без токена відхиляється", async () => {
      assert.equal(
        (
          await page.request.post(base, {
            form: { action: "delete_part", id: partId },
            maxRedirects: 0,
          })
        ).status(),
        403,
      );
    });
    await check("Використану деталь не можна видалити", async () => {
      let r = await post(page, "inventory", {
        action: "delete_part",
        id: partId,
      });
      assert.equal(r.status(), 422);
      assert.match(await r.text(), /історію руху/);
    });
    await check("Журнал відображає всі три типи руху", async () => {
      await page.goto(base + "/?page=history&part=" + partId);
      assert.equal(await page.locator("tbody tr").count(), 3);
      for (let t of ["Надходження", "Списання", "Переміщення"])
        assert.ok((await page.locator("tbody").innerText()).includes(t));
      if (name === "Chrome")
        await page.screenshot({
          path: path.join(out, "05-history.png"),
          fullPage: true,
        });
    });
    await check(
      "Конкурентні списання не створюють від’ємний залишок",
      async () => {
        let sessions = await Promise.all([
            browser.newContext(),
            browser.newContext(),
          ]),
          pp = await Promise.all(
            sessions.map(async (c) => {
              let p = await c.newPage();
              await p.goto(base + "/?page=movement");
              return p;
            }),
          );
        let rr = await Promise.all(
          pp.map((p) =>
            post(p, "movement", {
              action: "move_stock",
              type: "issue",
              part_id: partId,
              from_location_id: "1",
              quantity: "7",
              note: "Конкурентна перевірка",
            }),
          ),
        );
        assert.deepEqual(rr.map((r) => r.status()).sort(), [303, 422]);
        await Promise.all(sessions.map((c) => c.close()));
        await page.goto(base);
        assert.match(await row().innerText(), /A-01\s+5/);
        assert.match(await row().locator(".stock-number").innerText(), /10/);
      },
    );
    await check("CRUD місця зберігання через форми", async () => {
      await page.goto(base + "/?page=location-form");
      await page.locator("#code").fill("QA-" + name.toUpperCase());
      await page.locator("#name").fill("Резервний склад");
      await page.locator("#description").fill("Полиця перевірки");
      await page.getByRole("button", { name: "Зберегти", exact: true }).click();
      let card = page
        .locator(".location-card")
        .filter({ hasText: "QA-" + name.toUpperCase() });
      await card.getByRole("link", { name: "Редагувати" }).click();
      await page.locator("#description").fill("Оновлена полиця");
      await page.getByRole("button", { name: "Зберегти", exact: true }).click();
      assert.match(await card.innerText(), /Оновлена полиця/);
      page.once("dialog", (d) => d.accept());
      await card.getByRole("button", { name: "Видалити" }).click();
      await page.getByRole("status").waitFor();
      assert.equal(await card.count(), 0);
    });
    await check(
      "HTML введення екранується та невикористана деталь видаляється",
      async () => {
        await page.goto(base + "/?page=part-form");
        await page.locator("#sku").fill(sku + "-X");
        await page.locator("#name").fill("<script>window.__xss=1</script>");
        await page.locator("#category").fill("Тест");
        await page
          .getByRole("button", { name: "Зберегти", exact: true })
          .click();
        assert.equal(await page.evaluate(() => window.__xss), undefined);
        let x = page
          .locator("tr[data-part-id]")
          .filter({ hasText: sku + "-X" });
        assert.match(await x.innerText(), /<script>/);
        page.once("dialog", (d) => d.accept());
        await x
          .getByRole("button", { name: "Видалити " + sku + "-X", exact: true })
          .click();
        await page.getByRole("status").waitFor();
        assert.equal(await x.count(), 0);
      },
    );
    await check("Адаптивне відображення на ширині 390 пікселів", async () => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto(base);
      assert.equal(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
        true,
      );
      assert.ok(
        await page
          .locator(".table-scroll")
          .evaluate((el) => el.scrollWidth > el.clientWidth),
      );
      if (name === "Chrome")
        await page.screenshot({
          path: path.join(out, "06-mobile.png"),
          fullPage: true,
        });
      await page.setViewportSize({ width: 1440, height: 1000 });
    });
    await check("Невідома сторінка повертає 404", async () =>
      assert.equal((await page.goto(base + "/?page=unknown")).status(), 404),
    );
    assert.deepEqual(errors, []);
    return {
      browser: name,
      version: browser.version(),
      passed: checks.length,
      checks,
      errors,
      fixture: { sku, partId },
    };
  } finally {
    await browser.close();
  }
}
(async () => {
  let results = [];
  results.push(await run(chromium, "Chrome", { channel: "chrome" }));
  results.push(await run(firefox, "Firefox", {}));
  fs.writeFileSync(
    path.join(out, "browser-results.json"),
    JSON.stringify(results, null, 2),
  );
  console.log(
    "ALL PASS",
    results.map((r) => r.browser + ": " + r.passed).join(", "),
  );
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
