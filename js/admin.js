"use strict";

console.log("renderAnalytics() called");

let currentVariants = [];

// Make sure no direct DELETE calls are made
window.deleteImageDirect = function () {
  showToast('Use the "Save Images" button to confirm deletion.', "error");
};
function escapeHtml(str) {
  if (!str) return "";
  return str
    .replace(/[&<>]/g, function (m) {
      if (m === "&") return "&amp;";
      if (m === "<") return "&lt;";
      if (m === ">") return "&gt;";
      return m;
    })
    .replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, function (c) {
      return c;
    });
}
/**
 * Wrapper around fetch() that:
 *  - always sends credentials
 *  - throws on non-2xx HTTP responses (so catch blocks actually fire)
 *  - logs the raw response text when JSON.parse fails
 */
async function fetchJSON(url, options = {}) {
  const res = await fetch(url, { credentials: "include", ...options });
  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    console.error(
      `[admin] Non-JSON response from ${url} (${res.status}):`,
      text.slice(0, 300),
    );
    throw new Error(`Non-JSON response (${res.status}) from ${url}`);
  }
  if (!res.ok) {
    console.error(`[admin] HTTP ${res.status} from ${url}:`, data);
    throw new Error(`HTTP ${res.status}: ${data?.error || res.statusText}`);
  }
  return data;
}

const API = "/styled/php/admin";

/* ─────────────────────────────────────────────
   NAVIGATION
───────────────────────────────────────────── */
let currentPage = "dashboard";

function navigate(page, btn) {
  document
    .querySelectorAll(".page")
    .forEach((p) => p.classList.remove("active"));
  document
    .querySelectorAll(".nav-item")
    .forEach((n) => n.classList.remove("active"));
  document.getElementById("page-" + page)?.classList.add("active");
  if (btn) btn.classList.add("active");
  currentPage = page;

  const titles = {
    dashboard: "Welcome back, <span>Admin</span>",
    orders: "Orders",
    products: "Products",
    customers: "Customers",
    analytics: "Analytics",
    promotions: "Promotions",
    inventory: "Inventory",
    settings: "Settings",
    users: "Users",
    contact: "Contact Messages",
  };
  document.getElementById("page-title").innerHTML = titles[page] || page;

  const showDateFilter = page === "dashboard" || page === "analytics";
  document.getElementById("topbar-date-filter").style.display = showDateFilter
    ? "flex"
    : "none";

  // Lazy-load page data
  if (page === "dashboard") renderDashboard();
  if (page === "orders") {
    ordersPage = 1;
    renderOrdersTable();
  }
  if (page === "products") {
    productsPage = 1;
    renderProductsTable();
  }
  if (page === "customers") {
    customersPage = 1;
    renderCustomersTable();
  }
  if (page === "analytics") renderAnalytics();
  if (page === "promotions") renderPromotions();
  if (page === "inventory") renderInventory();
  if (page === "users") renderUsers();
  if (page === "contact") renderContactMessages();

  initCharts(page);
}

/* ─────────────────────────────────────────────
   MODALS
───────────────────────────────────────────── */
function openModal(id) {
  document.getElementById(id)?.classList.add("open");
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove("open");
}
document.querySelectorAll(".modal-overlay").forEach((overlay) => {
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay) overlay.classList.remove("open");
  });
});

// ── Button loading state helper ─────────────────────────────────────
function setButtonLoading(btn, isLoading, originalText = null) {
  if (!btn) return;
  if (isLoading) {
    btn._originalText = btn.innerHTML;
    btn.disabled = true;
    btn.style.opacity = "0.7";
    btn.innerHTML = `
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation: spin 0.7s linear infinite; margin-right: 6px;">
        <circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="8"/>
      </svg>
      Updating...
    `;
    // Inject keyframes if not present
    if (!document.querySelector("#loading-spinner-style")) {
      const style = document.createElement("style");
      style.id = "loading-spinner-style";
      style.textContent =
        "@keyframes spin { to { transform: rotate(360deg); } }";
      document.head.appendChild(style);
    }
  } else {
    btn.disabled = false;
    btn.style.opacity = "";
    if (btn._originalText) btn.innerHTML = btn._originalText;
  }
}

/* ─────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────── */
function formatPrice(n) {
  return (
    "₱" +
    Number(n).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

function statusBadge(s) {
  const map = {
    paid: "paid",
    shipped: "shipped",
    delivered: "delivered",
    processing: "processing",
    pending: "pending",
    refunded: "refunded",
    cancelled: "cancelled",
    unpaid: "pending",
    active: "active",
    draft: "draft",
    "low stock": "low-stock",
    "in stock": "in-stock",
    "out of stock": "out",
    expired: "refunded",
    scheduled: "processing",
    unread: "pending",
    read: "delivered",
    replied: "shipped",
  };
  const key = (s || "").toLowerCase();
  return `<span class="badge badge-${map[key] || "delivered"}">${s}</span>`;
}

function initials(name) {
  return (name || "?")
    .split(" ")
    .map((w) => w[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();
}

function paginate(arr, page, perPage) {
  return arr.slice((page - 1) * perPage, page * perPage);
}

function renderPagination(containerId, total, cur, perPage, cb) {
  const pages = Math.ceil(total / perPage);
  const el = document.getElementById(containerId);
  if (!el) return;
  let html = `<button class="page-btn" ${cur === 1 ? "disabled" : ""} onclick="(${cb})(${cur - 1})">‹</button>`;
  for (let i = 1; i <= pages; i++) {
    html += `<button class="page-btn ${i === cur ? "active" : ""}" onclick="(${cb})(${i})">${i}</button>`;
  }
  html += `<button class="page-btn" ${cur === pages ? "disabled" : ""} onclick="(${cb})(${cur + 1})">›</button>`;
  el.innerHTML = html;
}

function tableLoading(tbodyId, cols) {
  const el = document.getElementById(tbodyId);
  if (el)
    el.innerHTML = `<tr><td colspan="${cols}" style="text-align:center;color:var(--brown-100);padding:24px">Loading…</td></tr>`;
}

function tableError(tbodyId, cols, msg = "Failed to load data.") {
  const el = document.getElementById(tbodyId);
  if (el)
    el.innerHTML = `<tr><td colspan="${cols}" style="text-align:center;color:var(--red);padding:24px">${msg}</td></tr>`;
}

function showToast(msg, type = "") {
  let t = document.getElementById("_toast");
  if (!t) {
    t = document.createElement("div");
    t.id = "_toast";
    t.className = "toast";
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.className = "toast" + (type ? " " + type : "");
  void t.offsetWidth;
  t.classList.add("show");
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove("show"), 3000);
}

function onAnalyticsDateChange(days) {
  if (currentPage === "dashboard") renderDashboard();
  if (currentPage === "analytics") renderAnalytics();
}

async function renderDashboard() {
  // ─────────────────────────────────────────────
  // 1. RECENT ORDERS (no early return)
  // ─────────────────────────────────────────────
  tableLoading("dashboard-orders-body", 7);
  try {
    const data = await fetchJSON(`${API}/orders.php?page=1&limit=5`);
    const body = document.getElementById("dashboard-orders-body");

    if (!body) {
      // element missing → skip, but don't break
    } else if (!data.success || !data.orders.length) {
      body.innerHTML = `<tr><td colspan="7" class="text-muted text-sm" style="padding:16px">No orders yet. </td></tr>`;
    } else {
      body.innerHTML = data.orders
        .map(
          (o) => `
                    <tr style="cursor:pointer" onclick="openOrderDetail('${o.order_number}')">
                        <td><span style="font-weight:500;color:var(--brown-400)">#${o.order_number}</span></td>
                        <td>${o.customer_name || "—"}</td>
                        <td class="text-muted">${new Date(o.created_at).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}</td>
                        <td>${statusBadge(o.status)}</td>
                        <td>${statusBadge(o.payment_method === "cod" ? "Pending" : "Paid")}</td>
                        <td style="font-weight:500">${formatPrice(o.total_amount)}</td>
                        <td><button class="ellipsis-btn">···</button></td>
                    </tr>`,
        )
        .join("");
    }
  } catch (err) {
    tableError("dashboard-orders-body", 7);
  }

  // ─────────────────────────────────────────────
  // 2. STAT CARDS + TOP PRODUCTS + LOW STOCK + CHARTS
  // ─────────────────────────────────────────────
  try {
    const data = await fetchJSON(`${API}/analytics.php`);
    if (!data) return;

    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };
    set("stat-revenue", formatPrice(data.total_revenue));
    set("stat-orders", data.total_orders);
    set("stat-avg-order", formatPrice(data.avg_order_value));
    set("stat-conversion", data.conversion_rate + "%");

    const revenueChangeEl = document.getElementById("stat-revenue-change");
    if (revenueChangeEl) {
      const revChange = data.revenue_change_percent ?? 0;
      const sign = revChange >= 0 ? "+" : "";
      revenueChangeEl.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg> ${sign}${revChange}% vs last 7 days`;
      revenueChangeEl.className = `stat-change ${revChange >= 0 ? "up" : "down"}`;
    }

    const ordersChangeEl = document.getElementById("stat-orders-change");
    if (ordersChangeEl) {
      const ordersChange = data.orders_change_percent ?? 0;
      const sign = ordersChange >= 0 ? "+" : "";
      ordersChangeEl.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg> ${sign}${ordersChange}% vs last 7 days`;
      ordersChangeEl.className = `stat-change ${ordersChange >= 0 ? "up" : "down"}`;
    }

    const topProds = document.getElementById("top-products-list");
    if (topProds && data.top_products) {
      topProds.innerHTML = data.top_products
        .slice(0, 5)
        .map(
          (p) => `
                    <div class="metric-row">
                        <div class="flex-center gap-12">
                            <div class="product-thumb" style="display:flex;align-items:center;justify-content:center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--brown-100)"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>
                            <div>
                                <div style="font-size:15px;font-weight:500;color:var(--brown-400)">${p.name}</div>
                                <div class="text-sm text-muted">${p.quantity_sold} sold</div>
                            </div>
                        </div>
                        <div class="text-sm text-muted">${formatPrice(p.revenue)}</div>
                    </div>`,
        )
        .join("");
    }

    const invRes = await fetch(`${API}/inventory.php?status=low`, {
      credentials: "include",
    });
    const invData = await invRes.json();
    const lowStock = document.getElementById("low-stock-list");
    if (lowStock && invData.inventory) {
      const low = invData.inventory.slice(0, 5);
      lowStock.innerHTML = low.length
        ? low
            .map(
              (i) => `
                          <div class="metric-row">
                              <div class="flex-center gap-12">
                                  <div class="product-thumb" style="display:flex;align-items:center;justify-content:center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--brown-100)"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>
                                  <div>
                                      <div style="font-size:15px;font-weight:500;color:var(--brown-400)">${i.product_name}</div>
                                      <div class="text-sm text-muted">${i.size} — ${i.category}</div>
                                  </div>
                              </div>
                              <div style="font-size:14px;font-weight:500;color:var(--red)">${i.stock_qty} left</div>
                          </div>`,
            )
            .join("")
        : `<div class="text-sm text-muted" style="padding:12px">All stock levels healthy.</div>`;
    }

    _dashboardChartData = data;
    initCharts("dashboard");
  } catch (err) {
    // silent fail – data will be missing but page stays usable
  }
}

let ordersPage = 1;
let ordersStatusFilter = "";
let ordersSearch = "";
const ORDERS_PER_PAGE = 8;

async function renderOrdersTable() {
  tableLoading("orders-body", 7);
  const params = new URLSearchParams({
    page: ordersPage,
    limit: ORDERS_PER_PAGE,
    status: ordersStatusFilter,
    search: ordersSearch,
  });
  try {
    const data = await fetchJSON(`${API}/orders.php?${params}`);
    const body = document.getElementById("orders-body");
    if (!body) return;

    if (!data.success || !data.orders.length) {
      body.innerHTML = `<tr><td colspan="7" class="text-muted text-sm" style="padding:16px">No orders found. </td></table>`;
    } else {
      body.innerHTML = data.orders
        .map(
          (o) => `
        <tr style="cursor:pointer" onclick="openOrderDetail('${o.order_number}')">
          <td><span style="font-weight:500;color:var(--brown-400)">#${o.order_number}</span></td>
          <td>
            <div class="flex-center gap-8">
              <div class="customer-avatar">${initials(o.customer_name)}</div>
              ${o.customer_name || "—"}
            </div>
          </td>
          <td class="text-muted">${new Date(o.created_at).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}</td>
          <td>${statusBadge(o.status)}</td>
          <td>${statusBadge(o.payment_method === "cod" ? "Pending" : "Paid")}</td>
          <td style="font-weight:500">${formatPrice(o.total_amount)}</td>
          <td><button class="ellipsis-btn">···</button></td>
        </td>`,
        )
        .join("");
    }

    renderPagination(
      "orders-pagination",
      data.total,
      ordersPage,
      ORDERS_PER_PAGE,
      `function(p){ordersPage=p;renderOrdersTable();}`,
    );
  } catch (err) {
    console.error("[admin]", err);
    tableError("orders-body", 7);
  }
}

function filterOrders(q) {
  ordersSearch = q;
  ordersPage = 1;
  renderOrdersTable();
}

function filterOrdersByStatus(s) {
  ordersStatusFilter = s;
  ordersPage = 1;
  renderOrdersTable();
}

function showOrdersList() {
  document.getElementById("orders-list-view").style.display = "";
  document.getElementById("orders-detail-view").style.display = "none";
}

async function openOrderDetail(orderNumber) {
  navigate("orders", document.querySelector("[onclick*=\"'orders'\"]"));
  document.getElementById("orders-list-view").style.display = "none";
  document.getElementById("orders-detail-view").style.display = "";

  document.getElementById("detail-order-id").textContent =
    "Order #" + orderNumber;

  try {
    const data = await fetchJSON(`${API}/orders.php?id=${orderNumber}`);
    if (!data.success) return;
    const o = data.order;

    document.getElementById("detail-order-id").textContent =
      "Order #" + o.order_number;

    const statusBadgeEl = document.getElementById("detail-status-badge");
    if (statusBadgeEl)
      statusBadgeEl.outerHTML = `<span id="detail-status-badge">${statusBadge(o.status)}</span>`;

    const payBadgeEl = document.getElementById("detail-payment-badge");
    if (payBadgeEl)
      payBadgeEl.outerHTML = `<span id="detail-payment-badge">${statusBadge(o.payment_method === "cod" ? "Pending" : "Paid")}</span>`;

    // Items — API returns `quantity` and `unit_price` from order_items
    document.getElementById("detail-items").innerHTML = (o.items || [])
      .map((item) => {
        const qty = item.quantity ?? item.qty ?? 1;
        const price = item.unit_price ?? item.price ?? 0;
        return `
      <div class="order-item">
        <div class="order-item-img" style="display:flex;align-items:center;justify-content:center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--brown-100)"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>
        <div style="flex:1">
          <div style="font-weight:500;font-size:15px;color:var(--brown-400)">${item.product_name}</div>
          <div class="text-sm text-muted">${item.size || ""} × ${qty}</div>
        </div>
        <div style="font-weight:500">${formatPrice(price * qty)}</div>
      </div>`;
      })
      .join("");

    const subtotal = (o.items || []).reduce((s, i) => {
      const qty = i.quantity ?? i.qty ?? 1;
      const price = i.unit_price ?? i.price ?? 0;
      return s + price * qty;
    }, 0);
    const elSub = document.getElementById("detail-subtotal");
    if (elSub) elSub.textContent = formatPrice(subtotal);
    const elTotal = document.getElementById("detail-total-price");
    if (elTotal) elTotal.textContent = formatPrice(o.total_amount);

    // ── Update discount ──────────────────────────────────────────────
    const discountAmount = o.discount || 0;
    const discountEl = document.getElementById("detail-discount");
    if (discountEl) {
      if (discountAmount > 0) {
        discountEl.innerHTML = `−${formatPrice(discountAmount)}`;
        discountEl.style.color = "var(--green)";
      } else {
        discountEl.innerHTML = "—";
        discountEl.style.color = "";
      }
    }

    // Customer
    document.getElementById("detail-customer").innerHTML = `
      <div class="flex-center gap-12" style="margin-bottom:12px">
        <div class="customer-avatar" style="width:38px;height:38px;font-size:16px">${initials(o.customer_name)}</div>
        <div>
          <div style="font-weight:500;font-size:15px;color:var(--brown-400)">${o.customer_name || "—"}</div>
          <div class="text-sm text-muted">${o.customer_email || ""}</div>
        </div>
      </div>`;

    const addr = [o.street, o.city, o.province, o.zip_code]
      .filter(Boolean)
      .join(", ");
    const elAddr = document.getElementById("detail-address");
    if (elAddr) elAddr.innerHTML = addr || "—";

    // Tracking number — HTML uses id="tracking-input"
    const elTrack = document.getElementById("tracking-input");
    if (elTrack) elTrack.value = o.tracking_number || "";

    // Status update controls — inject into the header action buttons area
    // (HTML has static buttons; replace with live controls)
    const headerActions = document.querySelector(
      "#orders-detail-view .btn.btn-primary.btn-sm",
    );
    const actionsContainer = headerActions?.closest("div[style*='gap: 8px']");
    if (actionsContainer) {
      actionsContainer.innerHTML = `
        <select class="filter-select" id="status-select" style="height:36px;font-size:14px">
          ${[
            "pending",
            "processing",
            "shipped",
            "delivered",
            "cancelled",
            "refunded",
          ]
            .map(
              (s) =>
                `<option value="${s}" ${s === o.status ? "selected" : ""}>${s.charAt(0).toUpperCase() + s.slice(1)}</option>`,
            )
            .join("")}
        </select>
        <button class="btn btn-primary btn-sm" onclick="updateOrderStatus(${o.order_id})">Update Status</button>
        <button class="btn btn-outline btn-sm" onclick="showOrdersList()">← Back</button>`;
    }

    // Timeline — API returns order.timeline with {step_label, occurred_at, note}
    const timelineEl = document.getElementById("detail-timeline");
    if (timelineEl) {
      const tl = o.timeline || [];
      if (tl.length === 0) {
        timelineEl.innerHTML = `<div class="text-sm text-muted">No timeline events yet.</div>`;
      } else {
        timelineEl.innerHTML = tl
          .map(
            (t) => `
          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="timeline-content">
              <div style="font-weight:500;font-size:15px;color:var(--brown-400)">${t.step_label}</div>
              <div class="text-sm text-muted">${t.occurred_at ? new Date(t.occurred_at).toLocaleString("en-PH", { month: "short", day: "numeric", year: "numeric", hour: "2-digit", minute: "2-digit" }) : ""}</div>
              ${t.note ? `<div class="text-sm text-muted" style="margin-top:2px">${t.note}</div>` : ""}
            </div>
          </div>`,
          )
          .join("");
      }
    }
  } catch (err) {
    console.error("[admin]", err);
  }
}

async function updateOrderStatus(orderId) {
  const status = document.getElementById("status-select")?.value;
  const tracking = document.getElementById("tracking-input")?.value || "";
  if (!status) return;

  // Find the Update button
  const updateBtn = document.querySelector(
    "#orders-detail-view .btn-primary.btn-sm",
  );
  if (!updateBtn) return;

  setButtonLoading(updateBtn, true);

  try {
    const res = await fetch(`${API}/orders.php`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        order_id: orderId,
        status,
        tracking_number: tracking,
      }),
    });
    const data = await res.json();
    if (data.success) {
      const el = document.getElementById("detail-status-badge");
      if (el)
        el.outerHTML = `<span id="detail-status-badge">${statusBadge(status)}</span>`;
      showToast("Order updated successfully.", "ok");
    } else {
      showToast(data.error || "Update failed.", "error");
    }
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  } finally {
    setButtonLoading(updateBtn, false);
  }
}

/* ─────────────────────────────────────────────
   PRODUCTS
───────────────────────────────────────────── */
let productsPage = 1;
let productsCategoryFilter = "";
let productsStatusFilter = "";
let productsSearch = "";
const PRODUCTS_PER_PAGE = 8;

async function renderProductsTable() {
  tableLoading("products-body", 6);
  const params = new URLSearchParams({
    page: productsPage,
    limit: PRODUCTS_PER_PAGE,
    category: productsCategoryFilter,
    status: productsStatusFilter,
    search: productsSearch,
  });
  try {
    const data = await fetchJSON(`${API}/products.php?${params}`);
    const body = document.getElementById("products-body");
    if (!body) return;

    if (!data.success || !data.products.length) {
      body.innerHTML = `<tr><td colspan="6" class="text-muted text-sm" style="padding:16px">No products found. </td></tr>`;
    } else {
      body.innerHTML = data.products
        .map((p) => {
          const stock = parseInt(p.stock) || 0;
          const statLbl =
            stock === 0
              ? "Out of Stock"
              : stock <= 5
                ? "Low Stock"
                : p.status || "Active";
          return `


        <tr>
          <td>
            <div class="flex-center gap-12">
              <div class="product-thumb" style="display:flex;align-items:center;justify-content:center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--brown-100)"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>
              <span style="font-weight:500;color:var(--brown-400)">${p.name}</span>
            </div>
          </td>
          <td class="text-muted">${p.category || "—"}</td>
          <td style="font-weight:500">${formatPrice(p.price)}</td>
          <td>
            <div class="stock-bar-wrap">
              <span class="stock-num" style="color:${stock === 0 ? "var(--red)" : stock <= 5 ? "var(--gold)" : "var(--brown-300)"}">${stock}</span>
              <div class="progress-bar" style="flex:1">
                <div class="progress-fill" style="width:${Math.min(100, (stock / 30) * 100)}%;background:var(--gold)"></div>
              </div>
            </div>
          </td>
          <td>${statusBadge(statLbl)}</td>
          <td>
            <div class="flex-center gap-8">
              <button class="btn btn-outline btn-sm" onclick="openProductEditor(${p.product_id})">Edit</button>
              <button class="btn btn-outline btn-sm" style="color:var(--red)" onclick="deleteProduct(${p.product_id},'${p.name.replace(/'/g, "\\'")}')">Delete</button>
            </div>
          </td>
        </tr>`;
        })
        .join("");
    }

    renderPagination(
      "products-pagination",
      data.total,
      productsPage,
      PRODUCTS_PER_PAGE,
      `function(p){productsPage=p;renderProductsTable();}`,
    );
  } catch (err) {
    console.error("[admin]", err);
    tableError("products-body", 6);
  }
}

function filterProducts(q) {
  productsSearch = q;
  productsPage = 1;
  renderProductsTable();
}
function filterProductsByStatus(s) {
  productsStatusFilter = s;
  productsPage = 1;
  renderProductsTable();
}
function filterProductsByCategory(c) {
  productsCategoryFilter = c;
  productsPage = 1;
  renderProductsTable();
}

function showProductsList() {
  document.getElementById("products-list-view").style.display = "";
  document.getElementById("products-editor-view").style.display = "none";
}

let editingProductId = null;

async function openProductEditor(id) {
  editingProductId = id || null;
  document.getElementById("products-list-view").style.display = "none";
  document.getElementById("products-editor-view").style.display = "";

  if (!id) {
    // New product form — clear fields
    document.getElementById("editor-product-name").textContent = "New Product";
    [
      "edit-product-name",
      "edit-price",
      "edit-description",
      "edit-category-id",
    ].forEach((f) => {
      const el = document.getElementById(f);
      if (el) el.value = "";
    });
    return;
  }

  try {
    const data = await fetchJSON(`${API}/products.php?id=${id}`);
    if (!data.success) return;
    const p = data.product;
    document.getElementById("editor-product-name").textContent = p.name;
    const nameEl = document.getElementById("edit-product-name");
    const priceEl = document.getElementById("edit-price");
    const descEl = document.getElementById("edit-description");
    const catEl = document.getElementById("edit-category-id");
    if (nameEl) nameEl.value = p.name;
    if (priceEl) priceEl.value = p.price;
    if (descEl) descEl.value = p.description || "";
    if (catEl) catEl.value = p.category_id || "";
    switchProductTab(
      "general",
      document.querySelector("#product-editor-tabs .pill-tab"),
    );
  } catch (err) {
    console.error("[admin]", err);
  }
}

function productThumb() {
  return `<div class="product-thumb" style="display:flex;align-items:center;justify-content:center">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--brown-100)">
      <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
      <line x1="7" y1="7" x2="7.01" y2="7"/>
    </svg>
  </div>`;
}

async function createProduct() {
  const name = document.getElementById("new-product-name")?.value.trim();
  const price = parseFloat(document.getElementById("new-product-price")?.value);
  const category_id = parseInt(
    document.getElementById("new-product-category")?.value,
  );
  const description =
    document.getElementById("new-product-description")?.value.trim() || "";
  const imagesInput = document.getElementById("new-product-images");
  const files = imagesInput ? Array.from(imagesInput.files) : [];

  if (!name || isNaN(price) || !category_id) {
    showToast("Name, price and category are required.", "error");
    return;
  }

  if (files.length === 0) {
    showToast("Please select at least one product image.", "error");
    return;
  }

  // Build FormData
  const formData = new FormData();
  formData.append("name", name);
  formData.append("price", price);
  formData.append("category_id", category_id);
  formData.append("description", description);
  for (let i = 0; i < files.length; i++) {
    formData.append("images[]", files[i]);
  }

  try {
    const res = await fetch(`${API}/products.php`, {
      method: "POST",
      credentials: "include",
      body: formData,
    });
    const data = await res.json();
    if (data.success) {
      closeModal("modal-add-product");
      const fieldsToClear = [
        "new-product-name",
        "new-product-price",
        "new-product-description",
      ];
      fieldsToClear.forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "";
      });
      if (imagesInput) imagesInput.value = "";
      const previewContainer = document.getElementById(
        "new-product-image-preview",
      );
      if (previewContainer) previewContainer.innerHTML = "";
      renderProductsTable();
      showToast("Product created successfully.", "ok");
    } else {
      showToast(data.error || "Failed to create product.", "error");
    }
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

async function saveProduct() {
  const name = document.getElementById("edit-product-name")?.value.trim();
  const price = parseFloat(document.getElementById("edit-price")?.value);
  const description =
    document.getElementById("edit-description")?.value.trim() || "";
  const category_id =
    parseInt(document.getElementById("edit-category-id")?.value) || 0;

  if (!name || isNaN(price)) {
    showToast("Name and price are required.", "error");
    return;
  }

  if (!editingProductId && !category_id) {
    showToast("Category is required for new products.", "error");
    return;
  }

  const body = { name, price, description };
  if (category_id) body.category_id = category_id;

  const method = editingProductId ? "PUT" : "POST";
  const url = editingProductId
    ? `${API}/products.php?id=${editingProductId}`
    : `${API}/products.php`;

  try {
    const res = await fetch(url, {
      method,
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (data.success) {
      showProductsList();
      renderProductsTable();
      showToast(
        editingProductId ? "Product updated." : "Product created.",
        "ok",
      );
    } else {
      showToast(data.error || "Save failed.", "error");
    }
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

async function deleteProduct(id, name) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;
  try {
    const res = await fetch(`${API}/products.php?id=${id}`, {
      method: "DELETE",
      credentials: "include",
    });
    const data = await res.json();
    if (data.success) {
      renderProductsTable();
      showToast("Product deleted.", "ok");
    } else showToast(data.error || "Delete failed.", "error");
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

function switchProductTab(tab, btn) {
  ["general", "variants", "inventory", "seo"].forEach((t) => {
    const el = document.getElementById("tab-" + t);
    if (el) el.style.display = t === tab ? "" : "none";
  });
  document
    .querySelectorAll("#product-editor-tabs .pill-tab")
    .forEach((b) => b.classList.remove("active"));
  if (btn) btn.classList.add("active");
}

/* ─────────────────────────────────────────────
   CUSTOMERS
───────────────────────────────────────────── */
let customersPage = 1;
let customersSearch = "";
const CUSTOMERS_PER_PAGE = 8;

async function renderCustomersTable() {
  tableLoading("customers-body", 7);
  const params = new URLSearchParams({
    page: customersPage,
    limit: CUSTOMERS_PER_PAGE,
    search: customersSearch,
  });
  try {
    const data = await fetchJSON(`${API}/customers.php?${params}`);
    const body = document.getElementById("customers-body");
    if (!body) return;

    if (!data.success || !data.customers.length) {
      body.innerHTML = `<tr><td colspan="7" class="text-muted text-sm" style="padding:16px">No customers found. </td></tr>`;
    } else {
      body.innerHTML = data.customers
        .map(
          (c) => `
        <tr style="cursor:pointer" onclick="openCustomerProfile(${c.user_id})">
          <td>
            <div class="flex-center gap-12">
              <div class="customer-avatar">${initials(c.full_name)}</div>
              <span style="font-weight:500;color:var(--brown-400)">${c.full_name}</span>
            </div>
          </td>
          <td class="text-muted">${c.email}</td>
          <td>${c.order_count}</td>
          <td style="font-weight:500">${formatPrice(c.total_spent)}</td>
          <td>${c.admin_notes ? `<span class="badge badge-processing">Has note</span>` : "—"}</td>
          <td class="text-muted">${new Date(c.created_at).toLocaleDateString("en-PH", { month: "short", year: "numeric" })}</td>
          <td><button class="ellipsis-btn">···</button></td>
        </tr>`,
        )
        .join("");
    }

    renderPagination(
      "customers-pagination",
      data.total,
      customersPage,
      CUSTOMERS_PER_PAGE,
      `function(p){customersPage=p;renderCustomersTable();}`,
    );
  } catch (err) {
    console.error("[admin]", err);
    tableError("customers-body", 7);
  }
}

function filterCustomers(q) {
  customersSearch = q;
  customersPage = 1;
  renderCustomersTable();
}

function showCustomersList() {
  document.getElementById("customers-list-view").style.display = "";
  document.getElementById("customer-profile-view").style.display = "none";
}

let viewingCustomerId = null;

async function openCustomerProfile(id) {
  viewingCustomerId = id;
  navigate("customers", document.querySelector("[onclick*=\"'customers'\"]"));
  document.getElementById("customers-list-view").style.display = "none";
  document.getElementById("customer-profile-view").style.display = "";

  try {
    const data = await fetchJSON(`${API}/customers.php?id=${id}`);
    if (!data.success) return;
    const c = data.customer;

    document.getElementById("profile-avatar").textContent = initials(
      c.full_name,
    );
    document.getElementById("profile-name").textContent = c.full_name;
    document.getElementById("profile-email").textContent = c.email;
    document.getElementById("profile-total-orders").textContent = c.order_count;
    document.getElementById("profile-total-spent").textContent = formatPrice(
      c.total_spent,
    );

    // ── Update shipping address using the dedicated ID ──
    const addrDiv = document.getElementById("customer-shipping-address");
    if (addrDiv) {
      if (c.address && c.address.street) {
        addrDiv.innerHTML = `${c.address.street}<br>${c.address.city}, ${c.address.province}<br>${c.address.zip_code}`;
      } else {
        addrDiv.innerHTML = "No default address on file.";
      }
    }

    const noteEl = document.getElementById("profile-admin-notes");
    if (noteEl) noteEl.value = c.admin_notes || "";

    document.getElementById("profile-orders").innerHTML = c.orders.length
      ? c.orders
          .map(
            (o) => `
          <tr>
            <td>#${o.order_number}</td>
            <td class="text-muted">${new Date(o.created_at).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}</td>
            <td>${statusBadge(o.status)}</td>
            <td style="font-weight:500">${formatPrice(o.total_amount)}</td>
          </table>
        `,
          )
          .join("")
      : `<table><td colspan="4" class="text-muted text-sm" style="padding:12px">No orders found </td></tr>`;
  } catch (err) {
    console.error("[admin]", err);
  }
}

async function saveCustomerNote() {
  if (!viewingCustomerId) return;
  const notes = document.getElementById("profile-admin-notes")?.value || "";
  try {
    const res = await fetch(`${API}/customers.php?id=${viewingCustomerId}`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ admin_notes: notes }),
    });
    const data = await res.json();
    showToast(
      data.success ? "Note saved." : data.error || "Failed.",
      data.success ? "ok" : "error",
    );
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

/* ─────────────────────────────────────────────
   ANALYTICS
───────────────────────────────────────────── */
let _analyticsData = null;

async function renderAnalytics() {
  try {
    const data = await fetchJSON(`${API}/analytics.php`);
    _analyticsData = data;

    // Update KPI cards using classes (since HTML has no IDs)
    const stats = document.querySelectorAll(
      "#page-analytics .stats-grid .stat-card",
    );
    if (stats.length >= 4) {
      stats[0].querySelector(".stat-value").innerText = formatPrice(
        data.total_revenue,
      );
      stats[1].querySelector(".stat-value").innerText = data.total_orders;
      stats[2].querySelector(".stat-value").innerText = formatPrice(
        data.avg_order_value,
      );
      stats[3].querySelector(".stat-value").innerText =
        data.conversion_rate + "%";
    }

    // Top products
    const topProds = document.getElementById("analytics-top-products");
    if (topProds) {
      topProds.innerHTML = data.top_products
        .slice(0, 5)
        .map(
          (p, i) => `
        <div class="metric-row">
          <div>
            <div style="font-size:15px;font-weight:500;color:var(--brown-400)">${i + 1}. ${p.name}</div>
            <div class="text-sm text-muted">${p.quantity_sold} sold</div>
          </div>
          <div class="text-sm text-bold">${formatPrice(p.revenue)}</div>
        </div>
      `,
        )
        .join("");
    }

    initCharts("analytics");
  } catch (err) {
    console.error("[admin] Analytics load error:", err);
  }
}

/* ─────────────────────────────────────────────
   PROMOTIONS
───────────────────────────────────────────── */
async function renderPromotions() {
  tableLoading("promotions-body", 8);
  try {
    // Fetch both promotions list and stats
    const [listData, statsData] = await Promise.all([
      fetchJSON(`${API}/promotions.php`),
      fetchJSON(`${API}/promotions-stats.php`),
    ]);
    // Inside renderPromotions(), after fetching statsData
    // promotions-stats.php returns the data directly, no 'success' wrapper
    if (statsData && typeof statsData.active_codes !== "undefined") {
      document.getElementById("promo-active-codes").innerText =
        statsData.active_codes;
      document.getElementById("promo-total-uses").innerText =
        statsData.total_uses;
      document.getElementById("promo-revenue").innerHTML = formatPrice(
        statsData.revenue_from_promos,
      );
    } else {
      console.warn("Promotions stats failed:", statsData);
    }

    const body = document.getElementById("promotions-body");
    if (!body) return;
    if (!listData.success || !listData.promotions.length) {
      body.innerHTML = `<tr><td colspan="8" class="text-muted text-sm" style="padding:16px">No promotions yet. </td></tr>`;
    } else {
      body.innerHTML = listData.promotions
        .map((p) => {
          const isExpired =
            p.expiry_date && new Date(p.expiry_date) < new Date();
          const status = !p.is_active
            ? "Inactive"
            : isExpired
              ? "Expired"
              : "Active";
          const discount =
            p.discount_type === "percent"
              ? `${p.discount_value}% off`
              : `₱${p.discount_value} off`;
          const minOrder =
            p.min_order > 0
              ? `Min. spend ₱${Number(p.min_order).toLocaleString()}`
              : "No minimum";
          const uses = p.usage_limit
            ? `${p.usage_count} / ${p.usage_limit}`
            : `${p.usage_count} / —`;
          const expiry = p.expiry_date
            ? new Date(p.expiry_date).toLocaleDateString("en-PH", {
                month: "short",
                day: "numeric",
                year: "numeric",
              })
            : "—";
          return `
          <tr>
            <td><span style="font-weight:600;letter-spacing:.05em;color:var(--brown-400)">${p.code}</span></td>
            <td class="text-muted">${p.discount_type === "percent" ? "Percentage" : "Fixed"}</td>
            <td style="font-weight:500">${discount}</td>
            <td class="text-muted text-sm">${minOrder}</td>
            <td class="text-muted">${uses}</td>
            <td class="text-muted">${expiry}</td>
            <td>${statusBadge(status)}</td>
            <td>
              <div class="flex-center gap-6">
                <button class="btn btn-outline btn-sm" onclick="togglePromo(${p.promo_id}, ${p.is_active})">${p.is_active ? "Deactivate" : "Activate"}</button>
                <button class="btn btn-outline btn-sm" style="color:var(--red)" onclick="deletePromo(${p.promo_id},'${p.code}')">Delete</button>
              </div>
            </td>
          <tr>`;
        })
        .join("");
    }
  } catch (err) {
    console.error("[admin]", err);
    tableError("promotions-body", 8);
  }
}

async function createPromotion() {
  const code = document
    .getElementById("promo-code")
    ?.value.trim()
    .toUpperCase();
  const type = document.getElementById("promo-type")?.value;
  const value = parseFloat(document.getElementById("promo-value")?.value);
  const minOrder = parseFloat(
    document.getElementById("promo-min-order")?.value || "0",
  );
  const limit = document.getElementById("promo-limit")?.value;
  const expiry = document.getElementById("promo-expiry")?.value;

  if (!code || !type || isNaN(value)) {
    showToast("Code, type and value are required.", "error");
    return;
  }

  try {
    const res = await fetch(`${API}/promotions.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        code,
        discount_type: type,
        discount_value: value,
        min_order: minOrder,
        usage_limit: limit || null,
        expiry_date: expiry || null,
      }),
    });
    const data = await res.json();
    if (data.success) {
      closeModal("modal-add-promo");
      renderPromotions();
      showToast("Promotion created.", "ok");
    } else showToast(data.error || "Failed.", "error");
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

async function togglePromo(id, currentActive) {
  try {
    const res = await fetch(`${API}/promotions.php?id=${id}`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ is_active: currentActive ? 0 : 1 }),
    });
    const data = await res.json();
    if (data.success) renderPromotions();
    else showToast(data.error || "Failed.", "error");
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

async function deletePromo(id, code) {
  if (!confirm(`Delete promo "${code}"?`)) return;
  try {
    const res = await fetch(`${API}/promotions.php?id=${id}`, {
      method: "DELETE",
      credentials: "include",
    });
    const data = await res.json();
    if (data.success) {
      renderPromotions();
      showToast("Promotion deleted.", "ok");
    } else showToast(data.error || "Failed.", "error");
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

/* ─────────────────────────────────────────────
   INVENTORY
───────────────────────────────────────────── */
let inventoryStatusFilter = "";
let inventorySearch = "";

async function renderInventory() {
  tableLoading("inventory-body", 7);
  const params = new URLSearchParams({
    status: inventoryStatusFilter,
    search: inventorySearch,
  });
  try {
    const data = await fetchJSON(`${API}/inventory.php?${params}`);
    const body = document.getElementById("inventory-body");
    if (!body) return;

    if (!data.success || !data.inventory.length) {
      body.innerHTML = `<tr><td colspan="7" class="text-muted text-sm" style="padding:16px">No inventory data found. </td></table>`;
    } else {
      body.innerHTML = data.inventory
        .map((item) => {
          const qty = parseInt(item.stock_qty) || 0;
          const isOut = qty === 0;
          const isLow = qty > 0 && qty <= 5;
          const status = isOut
            ? "Out of Stock"
            : isLow
              ? "Low Stock"
              : "In Stock";
          return `
          <tr>
            <td style="font-weight:500;color:var(--brown-400)">${item.product_name}</td>
            <td class="text-muted">${item.size || "—"}</td>
            <td><code style="font-size:13px;background:var(--beige-100);padding:2px 6px;border-radius:3px">${item.sku || "—"}</code></td>
            <td class="text-muted">${item.category}</td>
            <td>
              <div class="flex-center gap-8">
                <span style="font-weight:600;color:${isOut ? "var(--red)" : isLow ? "var(--gold)" : "var(--brown-400)"}">${qty}</span>
                <div class="progress-bar" style="width:60px"><div class="progress-fill" style="width:${Math.min(100, (qty / 20) * 100)}%;background:${isOut ? "var(--red)" : "var(--gold)"}"></div></div>
              </div>
            </td>
            <td>${statusBadge(status)}</td>
            <td>
              <div class="flex-center gap-6">
                <input class="form-input" type="number" value="${qty}" min="0" id="inv-qty-${item.size_id}" style="width:65px;height:30px;padding:0 8px;font-size:14px" />
                <button class="btn btn-outline btn-sm" onclick="updateStock(${item.size_id})">Update</button>
              </div>
            </td>
          </tr>`;
        })
        .join("");
    }
  } catch (err) {
    console.error("[admin]", err);
    tableError("inventory-body", 7);
  }
}

function filterInventory(q) {
  inventorySearch = q;
  renderInventory();
}
function filterInventoryByStatus(s) {
  inventoryStatusFilter = s;
  renderInventory();
}

async function updateStock(sizeId) {
  const input = document.getElementById(`inv-qty-${sizeId}`);
  const qty = parseInt(input?.value, 10);
  if (isNaN(qty) || qty < 0) {
    showToast("Enter a valid quantity.", "error");
    return;
  }
  const updateBtn = input?.closest("tr")?.querySelector(".btn-outline.btn-sm");
  if (updateBtn) setButtonLoading(updateBtn, true);

  try {
    const res = await fetch(`${API}/inventory.php`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ size_id: sizeId, stock_qty: qty }),
    });
    const data = await res.json();
    if (data.success) {
      renderInventory();
      showToast("Stock updated.", "ok");
    } else showToast(data.error || "Failed.", "error");
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  } finally {
    if (updateBtn) setButtonLoading(updateBtn, false);
  }
}

/* ─────────────────────────────────────────────
   USERS
───────────────────────────────────────────── */
async function renderUsers() {
  tableLoading("users-body", 5);
  try {
    const data = await fetchJSON(`${API}/users.php`);
    const body = document.getElementById("users-body");
    if (!body) return;

    if (!data.success || !data.users.length) {
      body.innerHTML = `<tr><td colspan="5" class="text-muted text-sm" style="padding:16px">No staff users found. </td></tr>`;
    } else {
      const roleClass = { admin: "role-admin", staff: "role-staff" };
      body.innerHTML = data.users
        .map(
          (u) => `
        <tr>
          <td>
            <div class="flex-center gap-12">
              <div class="customer-avatar">${initials(u.full_name)}</div>
              <span style="font-weight:500;color:var(--brown-400)">${u.full_name}</span>
            </div>
          </td>
          <td class="text-muted">${u.email}</td>
          <td><span class="role-badge ${roleClass[u.role] || "role-staff"}">${u.role}</span></td>
          <td class="text-muted">${new Date(u.created_at).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}</td>
          <td>
            <div class="flex-center gap-8">
              <button class="btn btn-outline btn-sm" onclick="deleteUser(${u.user_id},'${u.full_name.replace(/'/g, "\\'")}')">Remove</button>
            </div>
          </td>
        </tr>`,
        )
        .join("");
    }
  } catch (err) {
    console.error("[admin]", err);
    tableError("users-body", 5);
  }
}

async function addStaff() {
  const name = document.getElementById("new-staff-name")?.value.trim();
  const email = document.getElementById("new-staff-email")?.value.trim();
  const role = document.getElementById("new-staff-role")?.value || "staff";

  if (!name || !email) {
    showToast("Name and email are required.", "error");
    return;
  }

  // Validate email format
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(email)) {
    showToast("Please enter a valid email address.", "error");
    return;
  }

  try {
    const res = await fetch(`${API}/users.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ full_name: name, email, role }),
    });
    const data = await res.json();

    if (data.success) {
      // Close the modal (correct ID is 'modal-add-user')
      closeModal("modal-add-user");

      // Clear the form fields for next use
      document.getElementById("new-staff-name").value = "";
      document.getElementById("new-staff-email").value = "";
      document.getElementById("new-staff-role").value = "staff";

      // Refresh the users table
      renderUsers();

      // Show appropriate toast message
      if (data.email_sent) {
        showToast(`Staff added & Invite email sent to ${email}`, "ok");
      } else {
        showToast(
          `Staff added but invitation email failed. Please check SMTP settings.`,
          "error",
        );
      }
    } else {
      showToast(data.error || "Failed to add staff member.", "error");
    }
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

async function deleteUser(id, name) {
  if (!confirm(`Remove "${name}" from staff?`)) return;
  try {
    const res = await fetch(`${API}/users.php?id=${id}`, {
      method: "DELETE",
      credentials: "include",
    });
    const data = await res.json();
    if (data.success) {
      renderUsers();
      showToast("User removed.", "ok");
    } else showToast(data.error || "Failed.", "error");
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

/* ─────────────────────────────────────────────
   CONTACT MESSAGES
───────────────────────────────────────────── */
let contactStatusFilter = "";
let contactPage = 1;

async function renderContactMessages() {
  tableLoading("contact-body", 7);
  const params = new URLSearchParams({
    page: contactPage,
    limit: 20,
    status: contactStatusFilter,
  });
  try {
    const data = await fetchJSON(`${API}/contact-messages.php?${params}`);
    const body = document.getElementById("contact-body");
    if (!body) return;

    if (!data.success || !data.messages.length) {
      body.innerHTML = `<tr><td colspan="7" class="text-muted text-sm" style="padding:16px">No messages found. </td></tr>`;
    } else {
      body.innerHTML = data.messages
        .map(
          (m) => `
        <tr style="cursor:pointer;${m.status === "unread" ? "font-weight:600;" : ""}">
          <td style="color:var(--brown-400)">${escapeHtml(m.name)}</td>
          <td class="text-muted">${escapeHtml(m.email)}</td>
          <td>${escapeHtml(m.subject)}</td>
          <td class="text-muted text-sm" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(m.message)}</td>
          <td>${statusBadge(m.status)}</td>
          <td class="text-muted">${new Date(m.sent_at).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}</td>
          <td>
            <div class="flex-center gap-6">
              <button class="btn btn-outline btn-sm" onclick="viewContactMessage(${m.message_id})">View</button>
              <button class="btn btn-primary btn-sm reply-contact-btn" data-id="${m.message_id}" data-email="${escapeHtml(m.email)}" data-subject="${escapeHtml(m.subject)}">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Reply
              </button>
              ${m.status === "unread" ? `<button class="btn btn-outline btn-sm" onclick="markMessage(${m.message_id},'read')">Mark Read</button>` : ""}
            </div>
          </td>
        </tr>`,
        )
        .join("");

      // Attach event listeners to the dynamically created reply buttons
      document.querySelectorAll(".reply-contact-btn").forEach((btn) => {
        btn.addEventListener("click", (e) => {
          e.stopPropagation();
          const id = parseInt(btn.dataset.id);
          const email = btn.dataset.email;
          const subject = btn.dataset.subject;
          openReplyModal(id, email, subject);
        });
      });

      renderPagination(
        "contact-pagination",
        data.total,
        contactPage,
        20,
        `function(p){contactPage=p;renderContactMessages();}`,
      );
    }
  } catch (err) {
    console.error("[admin]", err);
    tableError("contact-body", 7);
  }
}

function filterContactMessages(s) {
  contactStatusFilter = s;
  contactPage = 1;
  renderContactMessages();
}

async function viewContactMessage(id) {
  try {
    const data = await fetchJSON(`${API}/contact-messages.php?id=${id}`);
    if (!data.success) return;
    const m = data.message;
    // Show in a simple overlay instead of alert
    const existing = document.getElementById("_msg-overlay");
    if (existing) existing.remove();
    const overlay = document.createElement("div");
    overlay.id = "_msg-overlay";
    overlay.style.cssText =
      "position:fixed;inset:0;background:rgba(28,17,9,.45);z-index:9000;display:flex;align-items:center;justify-content:center";
    overlay.innerHTML = `<div style="background:#fff;border-radius:4px;padding:28px 32px;max-width:520px;width:90%;box-shadow:0 8px 32px rgba(28,17,9,.18)">
      <div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--brown-100);margin-bottom:6px">Contact Message</div>
      <div style="font-weight:500;color:var(--brown-400);font-size:17px;margin-bottom:4px">${m.subject}</div>
      <div style="color:var(--brown-200);font-size:14px;margin-bottom:14px">${m.name} &lt;${m.email}&gt;</div>
      <div style="font-size:15px;color:var(--brown-300);line-height:1.7;white-space:pre-wrap;border-top:1px solid var(--beige-200);padding-top:12px">${m.message}</div>
      <div style="margin-top:20px;text-align:right">
        <button onclick="document.getElementById('_msg-overlay').remove()" style="background:var(--brown-400);color:#fff;border:none;border-radius:4px;padding:8px 18px;font-size:14px;cursor:pointer">Close</button>
      </div>
    </div>`;
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) overlay.remove();
    });
    document.body.appendChild(overlay);
    if (m.status === "unread") markMessage(id, "read");
  } catch (err) {
    console.error("[admin]", err);
  }
}

async function markMessage(id, status) {
  try {
    await fetch(`${API}/contact-messages.php?id=${id}`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ status }),
    });
    renderContactMessages();
  } catch (err) {
    console.error("[admin]", err);
  }
}

function openReplyModal(id, email, subject) {
  document.getElementById("reply-message-id").value = id;
  document.getElementById("reply-to").value = email;
  document.getElementById("reply-subject").value = "Re: " + subject;
  document.getElementById("reply-body").value = "";
  openModal("modal-reply-contact");
}

async function sendContactReply() {
  const id = document.getElementById("reply-message-id").value;
  const subject = document.getElementById("reply-subject").value.trim();
  const body = document.getElementById("reply-body").value.trim();

  if (!subject || !body) {
    showToast("Subject and message are required.", "error");
    return;
  }

  try {
    const response = await fetch("/styled/php/admin/reply-contact.php", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        message_id: id,
        reply_subject: subject,
        reply_body: body,
      }),
    });
    const data = await response.json();
    if (data.success) {
      closeModal("modal-reply-contact");
      showToast("Reply sent successfully.", "ok");
      renderContactMessages(); // refresh the list
    } else {
      showToast(data.error || "Failed to send reply.", "error");
    }
  } catch (err) {
    console.error("sendContactReply error:", err);
    showToast("Network error. Could not send reply.", "error");
  }
}

/* ─────────────────────────────────────────────
   SETTINGS
───────────────────────────────────────────── */
function switchSettings(section, btn) {
  document
    .querySelectorAll(".settings-section")
    .forEach((s) => s.classList.remove("active"));
  document
    .querySelectorAll(".settings-nav-item")
    .forEach((b) => b.classList.remove("active"));
  document.getElementById("settings-" + section)?.classList.add("active");
  if (btn) btn.classList.add("active");
}

async function saveSettings(section) {
  // Collect all inputs/selects/textareas within the active settings section
  const container = document.getElementById("settings-" + section);
  if (!container) return;
  const settings = {};
  container
    .querySelectorAll(
      "input[type='text'], input[type='email'], input[type='number'], input[type='tel'], textarea, select",
    )
    .forEach((el) => {
      if (el.id) settings[el.id] = el.value;
    });
  container.querySelectorAll("input[type='checkbox']").forEach((el) => {
    if (el.id) settings[el.id] = el.checked ? "1" : "0";
  });
  try {
    await fetchJSON(`${API}/settings.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ group: section, settings }),
    });
    showToast("Settings saved.", "ok");
  } catch (err) {
    console.error("[admin] saveSettings:", err);
    showToast("Failed to save settings.", "error");
  }
}

async function loadSettings() {
  const sections = ["store", "payment", "shipping", "tax", "domain"];
  await Promise.all(
    sections.map(async (section) => {
      try {
        const data = await fetchJSON(`${API}/settings.php?group=${section}`);
        if (!data.success || !data.settings) return;
        Object.entries(data.settings).forEach(([id, val]) => {
          const el = document.getElementById(id);
          if (!el) return;
          if (el.type === "checkbox") el.checked = val === "1" || val === true;
          else el.value = val ?? "";
        });
      } catch (err) {
        // Section may have no saved settings yet — silently ignore
        console.warn(`[admin] loadSettings(${section}):`, err);
      }
    }),
  );
}

/* ─────────────────────────────────────────────
   CHARTS
───────────────────────────────────────────── */
const chartInstances = {};
let _dashboardChartData = null;

function destroyChart(id) {
  if (chartInstances[id]) {
    chartInstances[id].destroy();
    delete chartInstances[id];
  }
}

const BRAND_GOLD = "rgba(184,146,74,1)";
const BRAND_GOLD_BG = "rgba(184,146,74,0.12)";
const COLORS_DONUT = ["#3a6b4a", "#2d4f7a", "#5c4433", "#c4b5a0", "#9a7a44"];

const baseOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: "#fff",
      titleColor: "#1c120a",
      bodyColor: "#9c826a",
      borderColor: "#e6ddd2",
      borderWidth: 1,
      padding: 10,
      titleFont: { family: "'Cormorant Garamond', serif", size: 15 },
      bodyFont: { family: "'DM Sans', sans-serif", size: 13 },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: {
        color: "#b8a090",
        font: { family: "'DM Sans', sans-serif", size: 12 },
      },
      border: { display: false },
    },
    y: {
      grid: { color: "rgba(230,221,210,0.6)" },
      ticks: {
        color: "#b8a090",
        font: { family: "'DM Sans', sans-serif", size: 12 },
      },
      border: { display: false },
    },
  },
};

function initCharts(page) {
  if (page === "dashboard") {
    destroyChart("revenueChart");
    destroyChart("statusChart");

    const days = _dashboardChartData?.revenue_by_day || [];
    const labels = days.map((d) =>
      new Date(d.date).toLocaleDateString("en-PH", {
        month: "short",
        day: "numeric",
      }),
    );
    const revenues = days.map((d) => d.revenue);

    const rCtx = document.getElementById("revenueChart");
    if (rCtx) {
      chartInstances["revenueChart"] = new Chart(rCtx, {
        type: "bar",
        data: {
          labels,
          datasets: [
            {
              data: revenues,
              backgroundColor: (ctx) => {
                const { ctx: c, chartArea } = ctx.chart;
                if (!chartArea) return "rgba(61,36,16,0.7)";
                const g = c.createLinearGradient(
                  0,
                  chartArea.top,
                  0,
                  chartArea.bottom,
                );
                g.addColorStop(0, "rgba(61,36,16,0.78)");
                g.addColorStop(1, "rgba(61,36,16,0.10)");
                return g;
              },
              borderRadius: 6,
              borderSkipped: false,
            },
          ],
        },
        options: { ...baseOptions },
      });
    }

    const statusData = _dashboardChartData?.orders_by_status || {};
    const sCtx = document.getElementById("statusChart");
    if (sCtx) {
      chartInstances["statusChart"] = new Chart(sCtx, {
        type: "doughnut",
        data: {
          labels: Object.keys(statusData).map(
            (k) => k.charAt(0).toUpperCase() + k.slice(1),
          ),
          datasets: [
            {
              data: Object.values(statusData),
              backgroundColor: COLORS_DONUT,
              borderWidth: 0,
              hoverOffset: 4,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: baseOptions.plugins.tooltip,
          },
          cutout: "65%",
        },
      });
    }
  }

  if (page === "analytics") {
    destroyChart("analyticsRevenueChart");
    destroyChart("categoryChart");
    destroyChart("customerTypeChart");

    const days = _analyticsData?.revenue_by_day || [];
    const labels = days.map((d) =>
      new Date(d.date).toLocaleDateString("en-PH", {
        month: "short",
        day: "numeric",
      }),
    );
    const revData = days.map((d) => d.revenue);

    const arCtx = document.getElementById("analyticsRevenueChart");
    if (arCtx) {
      chartInstances["analyticsRevenueChart"] = new Chart(arCtx, {
        type: "line",
        data: {
          labels,
          datasets: [
            {
              data: revData,
              borderColor: "rgba(61,36,16,0.65)",
              backgroundColor: "rgba(61,36,16,0.05)",
              fill: true,
              tension: 0.4,
              pointBackgroundColor: "rgba(61,36,16,0.65)",
              pointRadius: 4,
              pointHoverRadius: 6,
              borderWidth: 2,
            },
          ],
        },
        options: { ...baseOptions },
      });
    }

    const cats = _analyticsData?.sales_by_category || [];
    const catCtx = document.getElementById("categoryChart");
    if (catCtx && cats.length) {
      chartInstances["categoryChart"] = new Chart(catCtx, {
        type: "doughnut",
        data: {
          labels: cats.map((c) => c.category),
          datasets: [
            {
              data: cats.map((c) => c.percentage),
              backgroundColor: [
                "#1c1109",
                "#3d2410",
                "#9a7a44",
                "#c4b5a0",
                "#b8a090",
              ],
              borderWidth: 0,
              hoverOffset: 4,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true,
              position: "right",
              labels: {
                font: { family: "'DM Sans'", size: 12 },
                color: "#7d6450",
                padding: 12,
              },
            },
            tooltip: baseOptions.plugins.tooltip,
          },
          cutout: "60%",
        },
      });
    }

    const ctCtx = document.getElementById("customerTypeChart");
    if (ctCtx) {
      chartInstances["customerTypeChart"] = new Chart(ctCtx, {
        type: "doughnut",
        data: {
          labels: ["New", "Returning"],
          datasets: [
            {
              data: [63, 37],
              backgroundColor: ["#3d2410", "#9a7a44"],
              borderWidth: 0,
              hoverOffset: 4,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: baseOptions.plugins.tooltip,
          },
          cutout: "65%",
        },
      });
    }
  }
}

/* ─────────────────────────────────────────────
   LOGOUT / USER INFO
───────────────────────────────────────────── */
async function handleLogout() {
  try {
    await fetch("/styled/php/auth/logout.php", {
      method: "POST",
      credentials: "include",
    });
  } catch (err) {
    console.error("[admin]", err);
  }
  localStorage.removeItem("styled_user");
  window.location.replace("auth.html");
}
document.getElementById("logout-btn")?.addEventListener("click", handleLogout);

async function populateUserInfo() {
  try {
    const res = await fetch("/styled/php/auth/check.php", {
      credentials: "include",
      cache: "no-store",
    });
    const data = await res.json();
    if (data.logged_in && data.user) {
      const u = data.user;
      const el = (id) => document.getElementById(id);
      if (el("sidebar-avatar"))
        el("sidebar-avatar").textContent = initials(u.full_name);
      if (el("sidebar-name")) el("sidebar-name").textContent = u.full_name;
      if (el("sidebar-email")) el("sidebar-email").textContent = u.email;
      if (el("page-title") && currentPage === "dashboard") {
        el("page-title").innerHTML =
          `Welcome back, <span>${u.full_name.split(" ")[0]}</span>`;
      }
    }
  } catch (err) {
    console.error("[admin]", err);
  }
}

// ─────────────────────────────────────────────
// PRODUCT IMAGES MANAGEMENT
// ─────────────────────────────────────────────
let productImagesState = []; // { image_id, image_url, is_primary }
let imagesToDelete = [];
let newPrimaryId = null;

function displayProductImages(images) {
  const container = document.getElementById("product-images-container");
  if (!container) return;

  productImagesState = images.map((img) => ({ ...img }));
  imagesToDelete = [];
  newPrimaryId = null;

  if (!productImagesState.length) {
    container.innerHTML =
      '<div class="text-muted text-sm" style="padding: 12px; text-align: center;">No images yet. Upload some above.</div>';
    return;
  }

  container.innerHTML = productImagesState
    .map(
      (img) => `
        <div class="product-image-thumb" data-image-id="${img.image_id}" data-primary="${img.is_primary}">
            <img src="/styled/${img.image_url}" alt="Product image" style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
            <div class="image-overlay">
                <button class="image-btn make-primary-btn" onclick="markImageAsPrimary(${img.image_id})" ${img.is_primary ? "disabled" : ""}>
                    ★
                </button>
                <button class="image-btn delete-image-btn" onclick="markImageForDelete(${img.image_id})">
                    ✕
                </button>
            </div>
            ${img.is_primary ? '<span class="primary-badge">Primary</span>' : ""}
        </div>
    `,
    )
    .join("");
}

function markImageAsPrimary(imageId) {
  newPrimaryId = imageId;
  // Update UI
  document.querySelectorAll(".product-image-thumb").forEach((el) => {
    const id = parseInt(el.dataset.imageId);
    if (id === imageId) {
      el.dataset.primary = "1";
      el.classList.add("is-primary");
      const badge =
        el.querySelector(".primary-badge") || document.createElement("span");
      if (!badge.classList.contains("primary-badge")) {
        badge.className = "primary-badge";
        badge.textContent = "Primary";
        el.appendChild(badge);
      }
      const makePrimaryBtn = el.querySelector(".make-primary-btn");
      if (makePrimaryBtn) makePrimaryBtn.disabled = true;
    } else {
      el.dataset.primary = "0";
      el.classList.remove("is-primary");
      const badge = el.querySelector(".primary-badge");
      if (badge) badge.remove();
      const btn = el.querySelector(".make-primary-btn");
      if (btn) btn.disabled = false;
    }
  });
}

function markImageForDelete(imageId) {
  if (!imagesToDelete.includes(imageId)) {
    imagesToDelete.push(imageId);
  }
  // Remove from UI
  const thumb = document.querySelector(
    `.product-image-thumb[data-image-id="${imageId}"]`,
  );
  if (thumb) thumb.remove();
  // Remove from state
  productImagesState = productImagesState.filter(
    (img) => img.image_id !== imageId,
  );
}

async function saveProductImages() {
  if (!editingProductId) {
    showToast("Save the product first before managing images.", "error");
    return;
  }

  const fileInput = document.getElementById("new-product-images");
  const files = fileInput ? Array.from(fileInput.files) : [];

  // Build actions array
  const actions = [];

  // Deletions
  imagesToDelete.forEach((imgId) => {
    actions.push({ action: "delete", image_id: imgId });
  });

  // Primary change
  if (newPrimaryId !== null) {
    actions.push({ action: "set_primary", image_id: newPrimaryId });
  }

  const formData = new FormData();
  formData.append("images_actions", JSON.stringify(actions));

  // Append new files
  files.forEach((file) => {
    formData.append("new_images[]", file);
  });

  try {
    const res = await fetch(`${API}/products.php?id=${editingProductId}`, {
      method: "PUT",
      credentials: "include",
      body: formData,
    });
    const data = await res.json();
    if (data.success) {
      showToast("Product images updated.", "ok");
      // Refresh images display
      const refreshData = await fetchJSON(
        `${API}/products.php?id=${editingProductId}`,
      );
      if (refreshData.success) {
        displayProductImages(refreshData.product.images || []);
        // Clear file input
        if (fileInput) fileInput.value = "";
        imagesToDelete = [];
        newPrimaryId = null;
      }
    } else {
      showToast(data.error || "Failed to update images.", "error");
    }
  } catch (err) {
    console.error("[admin]", err);
    showToast("Network error.", "error");
  }
}

function renderVariants(sizes) {
  const tbody = document.getElementById("variants-table-body");
  if (!tbody) return;
  tbody.innerHTML = "";
  (sizes || []).forEach((size, idx) => {
    const row = `
      <tr>
        <td>
          <select class="form-select" data-variant-idx="${idx}" data-field="size" style="width:80px">
            <option value="XS" ${size.size === "XS" ? "selected" : ""}>XS</option>
            <option value="S" ${size.size === "S" ? "selected" : ""}>S</option>
            <option value="M" ${size.size === "M" ? "selected" : ""}>M</option>
            <option value="L" ${size.size === "L" ? "selected" : ""}>L</option>
            <option value="XL" ${size.size === "XL" ? "selected" : ""}>XL</option>
            <option value="XXL" ${size.size === "XXL" ? "selected" : ""}>XXL</option>
          </select>
        </td>
        <td><input type="number" class="form-input" data-variant-idx="${idx}" data-field="stock" value="${size.stock_qty || 0}" style="width:80px"></td>
        <td><input type="text" class="form-input" data-variant-idx="${idx}" data-field="sku" value="${size.sku || ""}" placeholder="SKU"></td>
        <td><button class="btn btn-ghost btn-sm" onclick="removeVariant(${idx})">✕</button></td>
      </tr>
    `;
    tbody.insertAdjacentHTML("beforeend", row);
  });
}

function addVariantRow() {
  currentVariants.push({ size: "M", stock_qty: 0, sku: "" });
  renderVariants(currentVariants);
}

function removeVariant(idx) {
  currentVariants.splice(idx, 1);
  renderVariants(currentVariants);
}

async function saveVariants() {
  if (!editingProductId) {
    showToast("Save product first.", "error");
    return;
  }
  const saveBtn = document.querySelector("#tab-variants .btn-primary.btn-sm");
  if (saveBtn) setButtonLoading(saveBtn, true);

  const rows = document.querySelectorAll("#variants-table-body tr");
  const variants = [];
  rows.forEach((row) => {
    const sizeSelect = row.querySelector('[data-field="size"]');
    const stockInput = row.querySelector('[data-field="stock"]');
    const skuInput = row.querySelector('[data-field="sku"]');
    if (sizeSelect && stockInput) {
      variants.push({
        size: sizeSelect.value,
        stock_qty: parseInt(stockInput.value) || 0,
        sku: skuInput ? skuInput.value : "",
      });
    }
  });

  try {
    const res = await fetch(`${API}/products.php?id=${editingProductId}`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ variants }),
    });
    const data = await res.json();
    if (data.success) {
      showToast("Variants saved.", "ok");
      currentVariants = variants;
      renderProductsTable();
    } else showToast(data.error || "Failed", "error");
  } catch (err) {
    showToast("Network error", "error");
  } finally {
    if (saveBtn) setButtonLoading(saveBtn, false);
  }
}

// Override openProductEditor to load and display images
const originalOpenProductEditor = openProductEditor;
openProductEditor = async function (id) {
  editingProductId = id || null;
  document.getElementById("products-list-view").style.display = "none";
  document.getElementById("products-editor-view").style.display = "";

  if (!id) {
    document.getElementById("editor-product-name").textContent = "New Product";
    [
      "edit-product-name",
      "edit-price",
      "edit-description",
      "edit-category-id",
    ].forEach((f) => {
      const el = document.getElementById(f);
      if (el) el.value = "";
    });
    // Clear images container
    const container = document.getElementById("product-images-container");
    if (container)
      container.innerHTML =
        '<div class="text-muted text-sm">Save product first to add images.</div>';
    // Clear variants
    currentVariants = [];
    renderVariants(currentVariants);
    return;
  }

  try {
    const data = await fetchJSON(`${API}/products.php?id=${id}`);
    if (!data.success) return;
    const p = data.product;
    document.getElementById("editor-product-name").textContent = p.name;
    const nameEl = document.getElementById("edit-product-name");
    const priceEl = document.getElementById("edit-price");
    const descEl = document.getElementById("edit-description");
    const catEl = document.getElementById("edit-category-id");
    if (nameEl) nameEl.value = p.name;
    if (priceEl) priceEl.value = p.price;
    if (descEl) descEl.value = p.description || "";
    if (catEl) catEl.value = p.category_id || "";

    // Display images
    displayProductImages(p.images || []);

    // Load variants into currentVariants and render
    if (p.sizes && p.sizes.length) {
      currentVariants = p.sizes.map((s) => ({
        size: s.size,
        stock_qty: s.stock_qty,
        sku: s.sku || "",
      }));
    } else {
      currentVariants = [];
    }
    renderVariants(currentVariants);

    switchProductTab(
      "general",
      document.querySelector("#product-editor-tabs .pill-tab"),
    );
  } catch (err) {
    console.error("[admin]", err);
    showToast("Failed to load product details.", "error");
  }
};

/* ─────────────────────────────────────────────
   INIT
───────────────────────────────────────────── */
function init() {
  populateUserInfo();
  loadSettings();
  renderDashboard();

  // Preview selected images in the "Add Product" modal
  const imageInput = document.getElementById("new-product-images");
  if (imageInput) {
    imageInput.addEventListener("change", function (e) {
      const container = document.getElementById("new-product-image-preview");
      if (!container) return;
      container.innerHTML = "";
      const files = Array.from(e.target.files);
      files.forEach((file, idx) => {
        const reader = new FileReader();
        reader.onload = function (ev) {
          const img = document.createElement("img");
          img.src = ev.target.result;
          img.style.width = "60px";
          img.style.height = "60px";
          img.style.objectFit = "cover";
          img.style.borderRadius = "4px";
          img.style.margin = "4px";
          img.style.border =
            idx === 0 ? "2px solid var(--gold)" : "1px solid var(--beige-300)";
          img.title = idx === 0 ? "Primary image" : "";
          container.appendChild(img);
        };
        reader.readAsDataURL(file);
      });
    });
  }
}

document.addEventListener("DOMContentLoaded", init);
