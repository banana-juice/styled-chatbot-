"use strict";

// ========== GLOBAL VARIABLES ==========
let currentPage = "dashboard";
let ordersPage = 1,
  ordersStatusFilter = "",
  ordersSearch = "";
let productsPage = 1,
  productsCategoryFilter = "",
  productsStatusFilter = "",
  productsSearch = "";
let customersPage = 1,
  customersSearch = "";
let contactPage = 1,
  contactStatusFilter = "";
let viewingCustomerId = null;
let inventorySearch = "";
let inventoryStockFilter = "";

const API = "/styled/php/admin";
const ORDERS_PER_PAGE = 8;
const PRODUCTS_PER_PAGE = 8;
const CUSTOMERS_PER_PAGE = 8;

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
// ========== HELPER FUNCTIONS ==========
function escapeHtml(str) {
  if (!str) return "";
  return str.replace(/[&<>]/g, function (m) {
    if (m === "&") return "&amp;";
    if (m === "<") return "&lt;";
    if (m === ">") return "&gt;";
    return m;
  });
}

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

async function fetchJSON(url, options = {}) {
  const res = await fetch(url, { credentials: "include", ...options });
  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    console.error(`Non-JSON response from ${url}:`, text.slice(0, 300));
    throw new Error(`Non-JSON response (${res.status}) from ${url}`);
  }
  if (!res.ok)
    throw new Error(`HTTP ${res.status}: ${data?.error || res.statusText}`);
  return data;
}

// ========== TOAST ==========
function showToast(msg, type = "") {
  let t = document.getElementById("_staff_toast");
  if (!t) {
    t = document.createElement("div");
    t.id = "_staff_toast";
    t.className = "toast";
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.className = "toast" + (type ? " " + type : "");
  t.classList.add("show");
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove("show"), 3000);
}
const toast = showToast;

// ========== MODALS ==========
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

// ========== NAVIGATION ==========
const ALLOWED_PAGES = [
  "dashboard",
  "orders",
  "products",
  "customers",
  "promotions",
  "inventory",
  "contact",
];

function navigate(page, btn) {
  if (!ALLOWED_PAGES.includes(page)) {
    toast("Access restricted.", "error");
    return;
  }
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
    dashboard: "Welcome back, <span>Staff</span>",
    orders: "Orders",
    products: "Products",
    customers: "Customers",
    promotions: "Promotions",
    inventory: "Inventory",
    contact: "Contact Messages",
  };
  document.getElementById("page-title").innerHTML = titles[page] || page;
  document.getElementById("topbar-date-filter").style.display =
    page === "dashboard" ? "flex" : "none";

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
  if (page === "promotions") renderPromotions();
  if (page === "inventory") renderInventory();
  if (page === "contact") renderContactMessages();

  initCharts(page);
}

// ========== DASHBOARD ==========
async function renderDashboard() {
  // Recent orders
  tableLoading("dashboard-orders-body", 6);
  try {
    const data = await fetchJSON(`${API}/orders.php?page=1&limit=5`);
    const body = document.getElementById("dashboard-orders-body");
    if (!body) return;
    if (!data.success || !data.orders.length) {
      body.innerHTML = `<tr><td colspan="6" class="text-muted text-sm" style="padding:16px">No orders yet.</td></tr>`;
    } else {
      body.innerHTML = data.orders
        .map(
          (o) => `
        <tr style="cursor:pointer" onclick="openOrderDetail('${o.order_number}')">
          <td><span style="font-weight:500">#${o.order_number}</span></td>
          <td>${o.customer_name || "—"}</td>
          <td class="text-muted">${new Date(o.created_at).toLocaleDateString()}</td>
          <td>${statusBadge(o.status)}</td>
          <td style="font-weight:500">${formatPrice(o.total_amount)}</td>
          <td><button class="ellipsis-btn">···</button></td>
         </tr>
      `,
        )
        .join("");
    }
  } catch (err) {
    tableError("dashboard-orders-body", 6);
  }

  // Low stock alerts
  try {
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
          <div>${i.product_name} ${i.size ? `(${i.size})` : ""}</div>
          <div style="color:var(--red)">${i.stock_qty} left</div>
        </div>
      `,
            )
            .join("")
        : `<div class="text-sm text-muted">All stock levels healthy.</div>`;
    }
  } catch (err) {
    console.error(err);
  }

  // Staff dashboard stats
  try {
    const stats = await fetchJSON(`${API}/staff-analytics.php`);
    console.log("Dashboard stats:", stats);

    // Update by ID (foolproof)
    document.getElementById("stat-total-revenue").innerText = formatPrice(
      stats.total_revenue,
    );
    document.getElementById("stat-total-orders").innerText = stats.total_orders;
    document.getElementById("stat-avg-order").innerText = formatPrice(
      stats.avg_order_value,
    );
    document.getElementById("stat-conversion-rate").innerText =
      stats.conversion_rate + "%";

    const revChange = stats.revenue_change_percent;
    const ordersChange = stats.orders_change_percent;
    const revenueChangeEl = document
      .querySelector("#stat-total-revenue")
      ?.closest(".stat-card")
      ?.querySelector(".stat-change");
    const ordersChangeEl = document
      .querySelector("#stat-total-orders")
      ?.closest(".stat-card")
      ?.querySelector(".stat-change");
    if (revenueChangeEl) {
      revenueChangeEl.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg> ${revChange >= 0 ? "+" : ""}${revChange}% vs last 7 days`;
      revenueChangeEl.className = `stat-change ${revChange >= 0 ? "up" : "down"}`;
    }
    if (ordersChangeEl) {
      ordersChangeEl.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg> ${ordersChange >= 0 ? "+" : ""}${ordersChange}% vs last 7 days`;
      ordersChangeEl.className = `stat-change ${ordersChange >= 0 ? "up" : "down"}`;
    }
  } catch (err) {
    console.warn("Could not load staff dashboard stats", err);
  }
}

// ========== ORDERS ==========
async function renderOrdersTable() {
  tableLoading("orders-body", 6);
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
      body.innerHTML = `<tr><td colspan="6" class="text-muted">No orders found.</td></tr>`;
    } else {
      body.innerHTML = data.orders
        .map(
          (o) => `
        <tr style="cursor:pointer" onclick="openOrderDetail('${o.order_number}')">
          <td><span style="font-weight:500">#${o.order_number}</span></td>
          <td><div class="flex-center gap-8"><div class="customer-avatar">${initials(o.customer_name)}</div>${o.customer_name || "—"}</div></td>
          <td class="text-muted">${new Date(o.created_at).toLocaleDateString()}</td>
          <td>${statusBadge(o.status)}</td>
          <td style="font-weight:500">${formatPrice(o.total_amount)}</td>
          <td><button class="ellipsis-btn">···</button></td>
         </tr>
      `,
        )
        .join("");
    }
    renderPagination(
      "orders-pagination",
      data.total,
      ordersPage,
      ORDERS_PER_PAGE,
      "function(p){ordersPage=p;renderOrdersTable();}",
    );
  } catch (err) {
    tableError("orders-body", 6);
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
  try {
    const data = await fetchJSON(`${API}/orders.php?id=${orderNumber}`);
    if (!data.success) return;
    const o = data.order;
    document.getElementById("detail-order-id").textContent =
      "#" + o.order_number;
    document.getElementById("detail-status-badge").innerHTML = statusBadge(
      o.status,
    );
    document.getElementById("detail-payment-badge").innerHTML = statusBadge(
      o.payment_method === "cod" ? "Pending" : "Paid",
    );
    const customerDiv = document.getElementById("detail-customer");
    if (customerDiv)
      customerDiv.innerHTML = `<div class="flex-center gap-12"><div class="customer-avatar">${initials(o.customer_name)}</div><div><div style="font-weight:500">${o.customer_name || "—"}</div><div class="text-sm text-muted">${o.customer_email || ""}</div></div></div>`;
    const addrEl = document.getElementById("detail-address");
    if (addrEl)
      addrEl.innerHTML =
        [o.street, o.city, o.province, o.zip_code].filter(Boolean).join(", ") ||
        "No address provided";
    const itemsEl = document.getElementById("detail-items");
    if (itemsEl)
      itemsEl.innerHTML = (o.items || [])
        .map(
          (item) =>
            `<div class="metric-row"><div>${item.product_name}${item.size ? " (" + item.size + ")" : ""} × ${item.qty}</div><div>${formatPrice(item.unit_price * item.qty)}</div></div>`,
        )
        .join("");
    const subtotal = (o.items || []).reduce(
      (s, i) => s + i.unit_price * i.qty,
      0,
    );
    document.getElementById("detail-subtotal").innerHTML =
      formatPrice(subtotal);
    document.getElementById("detail-total-price").innerHTML = formatPrice(
      o.total_amount,
    );

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

    // ── Timeline (moved inside try block) ─────────────────────────────
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
                  <div class="timeline-label">${escapeHtml(t.step_label)}</div>
                  <div class="timeline-time">${new Date(t.occurred_at).toLocaleString()}</div>
                  ${t.note ? `<div class="text-sm text-muted">${escapeHtml(t.note)}</div>` : ""}
                </div>
              </div>
            `,
          )
          .join("");
      }
    }

    let actionsDiv = document.getElementById("detail-actions");
    if (!actionsDiv) {
      const container = document.querySelector(
        "#orders-detail-view .order-detail-grid",
      );
      if (container) {
        const newDiv = document.createElement("div");
        newDiv.id = "detail-actions";
        newDiv.className = "card";
        newDiv.style.marginBottom = "16px";
        newDiv.innerHTML = `<div class="card-title mb-16">Update Status</div>`;
        container.parentNode.insertBefore(newDiv, container);
        actionsDiv = newDiv;
      }
    }
    if (actionsDiv) {
      actionsDiv.innerHTML = `
        <div class="card-title mb-16">Update Status</div>
        <div style="display:flex; gap:8px; flex-wrap:wrap">
          <select class="filter-select" id="status-select" style="flex:1">
            ${["processing", "shipped", "delivered"].map((s) => `<option value="${s}" ${s === o.status ? "selected" : ""}>${s.charAt(0).toUpperCase() + s.slice(1)}</option>`).join("")}
          </select>
          <input class="form-input" type="text" id="tracking-input" placeholder="Tracking number" value="${o.tracking_number || ""}" style="width:180px" />
          <button class="btn btn-primary btn-sm" onclick="updateOrderStatus(${o.order_id})">Update</button>
        </div>
      `;
    }
  } catch (err) {
    toast("Failed to load order details.", "error");
  }
}

async function updateOrderStatus(orderId) {
  const status = document.getElementById("status-select")?.value;
  const tracking = document.getElementById("tracking-input")?.value || "";
  if (!status) return;

  const updateBtn = document.querySelector(
    "#orders-detail-view .btn-primary.btn-sm",
  );
  if (updateBtn) setButtonLoading(updateBtn, true);

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
      document.getElementById("detail-status-badge").innerHTML =
        statusBadge(status);
      toast("Order status updated to " + status, "ok");
      const orderNumber = document
        .getElementById("detail-order-id")
        ?.textContent.replace("#", "");
      if (orderNumber) openOrderDetail(orderNumber);
    } else {
      toast(data.error || "Update failed.", "error");
    }
  } catch (err) {
    toast("Network error.", "error");
  } finally {
    if (updateBtn) setButtonLoading(updateBtn, false);
  }
}

// ========== PRODUCTS ==========
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
      body.innerHTML = `<tr><td colspan="6" class="text-muted">No products found.</td></tr>`;
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
          return `<tr>
          <td><div class="flex-center gap-12"><div class="product-thumb" style="display:flex;align-items:center;justify-content:center">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--brown-100)">
    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
    <line x1="7" y1="7" x2="7.01" y2="7"/>
  </svg>
</div><span style="font-weight:500">${p.name}</span></div></td>
          <td class="text-muted">${p.category || "—"}</td>
          <td>${formatPrice(p.price)}</td>
          <td><div class="stock-bar-wrap"><span class="stock-num">${stock}</span><div class="progress-bar"><div class="progress-fill" style="width:${Math.min(100, (stock / 30) * 100)}%"></div></div></div></td>
          <td>${statusBadge(statLbl)}</td>
          <td><button class="btn btn-outline btn-sm" onclick="openStockEditor(${p.product_id},'${p.name.replace(/'/g, "\\'")}',${stock})">Update Stock</button></td>
         </tr>`;
        })
        .join("");
    }
    renderPagination(
      "products-pagination",
      data.total,
      productsPage,
      PRODUCTS_PER_PAGE,
      "function(p){productsPage=p;renderProductsTable();}",
    );
  } catch (err) {
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

let stockEditSizeId = null;

function openStockEditor(productId, productName) {
  stockEditSizeId = productId;
  fetchJSON(`${API}/products.php?id=${productId}`)
    .then((data) => {
      if (!data.success) throw new Error();
      const sizes = data.product.sizes || [];
      if (!sizes.length) {
        toast("No size variants found for this product.", "error");
        return;
      }
      let modal = document.getElementById("modal-stock-edit-per-size");
      if (!modal) {
        modal = document.createElement("div");
        modal.className = "modal-overlay";
        modal.id = "modal-stock-edit-per-size";
        modal.innerHTML = `
        <div class="modal" style="max-width:500px">
          <div class="modal-header"><div class="modal-title">Update Stock – <span id="stock-product-name"></span></div><button class="btn btn-ghost btn-icon" onclick="closeModal('modal-stock-edit-per-size')">✕</button></div>
          <div class="modal-body">
            <table style="width:100%"><thead><tr><th>Size</th><th>Current Stock</th><th>New Stock</th></tr></thead><tbody id="stock-sizes-tbody"></tbody></table>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modal-stock-edit-per-size')">Cancel</button>
            <button class="btn btn-primary" onclick="savePerSizeStock()">Save All</button>
          </div>
        </div>`;
        document.body.appendChild(modal);
        modal.addEventListener("click", (e) => {
          if (e.target === modal) closeModal("modal-stock-edit-per-size");
        });
      }
      document.getElementById("stock-product-name").innerText = productName;
      const tbody = document.getElementById("stock-sizes-tbody");
      tbody.innerHTML = sizes
        .map(
          (s) => `
      <tr>
        <td>${s.size}</td>
        <td>${s.stock_qty}</td>
        <td><input type="number" class="form-input" data-size-id="${s.size_id}" value="${s.stock_qty}" style="width:80px"></td>
       </tr>
    `,
        )
        .join("");
      openModal("modal-stock-edit-per-size");
    })
    .catch(() => toast("Could not load product sizes.", "error"));
}

async function savePerSizeStock() {
  const inputs = document.querySelectorAll("#stock-sizes-tbody input");
  const updates = [];
  inputs.forEach((inp) => {
    const sizeId = parseInt(inp.dataset.sizeId);
    const newQty = parseInt(inp.value);
    if (!isNaN(newQty) && newQty >= 0)
      updates.push({ size_id: sizeId, stock_qty: newQty });
  });
  if (!updates.length) return;

  const saveBtn = document.querySelector(
    "#modal-stock-edit-per-size .btn-primary",
  );
  if (saveBtn) setButtonLoading(saveBtn, true);

  try {
    await Promise.all(
      updates.map((u) =>
        fetch(`${API}/inventory.php`, {
          method: "PUT",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(u),
        }),
      ),
    );
    closeModal("modal-stock-edit-per-size");
    renderProductsTable();
    renderInventory();
    toast("Stock updated.", "ok");
  } catch (err) {
    toast("Network error.", "error");
  } finally {
    if (saveBtn) setButtonLoading(saveBtn, false);
  }
}
async function saveStock() {
  const qty = parseInt(document.getElementById("stock-qty-input")?.value, 10);
  if (isNaN(qty) || qty < 0) {
    toast("Enter a valid stock quantity.", "error");
    return;
  }
  try {
    const prodRes = await fetch(`${API}/products.php?id=${stockEditSizeId}`, {
      credentials: "include",
    });
    const prodData = await prodRes.json();
    if (!prodData.success) {
      toast("Could not load product sizes.", "error");
      return;
    }
    const sizes = prodData.product.sizes || [];
    if (!sizes.length) {
      toast("No size variants found.", "error");
      return;
    }
    await Promise.all(
      sizes.map((s) =>
        fetch(`${API}/inventory.php`, {
          method: "PUT",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ size_id: s.size_id, stock_qty: qty }),
        }),
      ),
    );
    closeModal("modal-stock-edit");
    renderProductsTable();
    renderInventory();
    toast("Stock updated.", "ok");
  } catch (err) {
    toast("Network error.", "error");
  }
}

// ========== CUSTOMERS ==========
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
      body.innerHTML = `<tr><td colspan="7" class="text-muted">No customers found.</td></tr>`;
    } else {
      body.innerHTML = data.customers
        .map(
          (c) => `
        <tr style="cursor:pointer" onclick="openCustomerProfile(${c.user_id})">
          <td><div class="flex-center gap-12"><div class="customer-avatar">${initials(c.full_name)}</div><span style="font-weight:500">${c.full_name}</span></div></td>
          <td class="text-muted">${c.email}</td>
          <td>${c.order_count}</td>
          <td>${formatPrice(c.total_spent)}</td>
          <td>${c.admin_notes ? `<span class="badge badge-processing">Has note</span>` : "—"}</td>
          <td class="text-muted">${new Date(c.created_at).toLocaleDateString()}</td>
          <td><button class="ellipsis-btn">···</button></td>
         </tr>
      `,
        )
        .join("");
    }
    renderPagination(
      "customers-pagination",
      data.total,
      customersPage,
      CUSTOMERS_PER_PAGE,
      "function(p){customersPage=p;renderCustomersTable();}",
    );
  } catch (err) {
    tableError("customers-body", 7);
  }
}
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
    document.getElementById("profile-total-spent").innerHTML = formatPrice(
      c.total_spent,
    );

    // ── Internal notes section (create if not exists) ──
    let notesDiv = document.getElementById("profile-admin-notes");
    if (!notesDiv) {
      const card = document.querySelector(
        "#customer-profile-view .card:first-child",
      );
      if (card) {
        card.insertAdjacentHTML(
          "beforeend",
          `<div class="form-field" style="margin-top:16px">
             <label class="form-label">Internal Notes</label>
             <textarea class="form-textarea" id="profile-admin-notes" rows="2" placeholder="Add internal note…"></textarea>
             <button class="btn btn-outline btn-sm" style="margin-top:8px" onclick="saveCustomerNote()">Save Note</button>
           </div>`,
        );
        notesDiv = document.getElementById("profile-admin-notes");
      }
    }
    if (notesDiv) notesDiv.value = c.admin_notes || "";

    // ── Update shipping address using the dedicated ID ──
    const addrDiv = document.getElementById("customer-shipping-address");
    if (addrDiv) {
      if (c.address && c.address.street) {
        addrDiv.innerHTML = `${c.address.street}<br>${c.address.city}, ${c.address.province}<br>${c.address.zip_code}`;
      } else {
        addrDiv.innerHTML = "No default address on file.";
      }
    }

    // ── Order history ──
    const ordersBody = document.getElementById("profile-orders");
    if (ordersBody) {
      ordersBody.innerHTML = c.orders.length
        ? c.orders
            .map(
              (o) =>
                `<tr onclick="openOrderDetail('${o.order_number}')" style="cursor:pointer">
                    <td>#${o.order_number}</td>
                    <td class="text-muted">${new Date(o.created_at).toLocaleDateString()}</td>
                    <td>${statusBadge(o.status)}</td>
                    <td style="font-weight:500">${formatPrice(o.total_amount)}</td>
                   </tr>`,
            )
            .join("")
        : `<tr><td colspan="4" class="text-muted">No orders found</td></tr>`;
    }
  } catch (err) {
    toast("Failed to load customer profile.", "error");
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
    toast(
      data.success ? "Note saved." : data.error || "Failed.",
      data.success ? "ok" : "error",
    );
  } catch (err) {
    toast("Network error.", "error");
  }
}

// ========== PROMOTIONS ==========
async function renderPromotions() {
  tableLoading("promotions-body", 8);
  try {
    const [listData, statsData] = await Promise.all([
      fetchJSON(`${API}/promotions.php`),
      fetchJSON(`${API}/promotions-stats.php`),
    ]);

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
      body.innerHTML = `<tr><td colspan="8" class="text-muted">No promotions found.</td></tr>`;
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
            ? new Date(p.expiry_date).toLocaleDateString()
            : "—";
          return `
          <tr>
            <td><span style="font-weight:600">${p.code}</span></td>
            <td>${p.discount_type === "percent" ? "Percentage" : "Fixed"}</td>
            <td>${discount}</td>
            <td>${minOrder}</td>
            <td>${uses}</td>
            <td>${expiry}</td>
            <td>${statusBadge(status)}</td>
            <td>—</td>
           </tr>`;
        })
        .join("");
    }
  } catch (err) {
    tableError("promotions-body", 8);
  }
}

// ========== INVENTORY ==========
async function renderInventory() {
  tableLoading("inventory-body", 7);
  const params = new URLSearchParams({ search: inventorySearch });
  if (inventoryStockFilter) params.set("status", inventoryStockFilter);
  try {
    const data = await fetchJSON(`${API}/inventory.php?${params}`);
    const body = document.getElementById("inventory-body");
    if (!body) return;
    if (!data.success || !data.inventory.length) {
      body.innerHTML = `</table><td colspan="7" class="text-muted">No inventory data found.</td></tr>`;
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
            <td style="font-weight:500;color:var(--brown-400)">${escapeHtml(item.product_name)}</td>
            <td>${escapeHtml(item.size || "—")}</td>
            <td><code style="font-size:13px">${escapeHtml(item.sku || "—")}</code></td>
            <td>${escapeHtml(item.category || "—")}</td>
            <td style="color:${isOut ? "var(--red)" : isLow ? "var(--gold)" : "var(--brown-300)"}">${qty}</td>
            <td>${statusBadge(status)}</td>
            <td><button class="btn btn-outline btn-sm" onclick="openSizeStockModal(${item.size_id}, '${escapeHtml(item.product_name)}', '${escapeHtml(item.size)}', ${qty})">Update</button></td>
           </tr>`;
        })
        .join("");
    }
  } catch (err) {
    tableError("inventory-body", 7);
  }
}

function openSizeStockModal(sizeId, productName, size, currentStock) {
  let modal = document.getElementById("modal-size-stock");
  if (!modal) {
    modal = document.createElement("div");
    modal.className = "modal-overlay";
    modal.id = "modal-size-stock";
    modal.innerHTML = `
      <div class="modal" style="max-width:360px">
        <div class="modal-header">
          <div class="modal-title">Update Stock</div>
          <button class="btn btn-ghost btn-icon" onclick="closeModal('modal-size-stock')">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-field">
            <label class="form-label">Product: <strong id="size-stock-product"></strong> (Size <span id="size-stock-size"></span>)</label>
            <input class="form-input" type="number" id="size-stock-qty" min="0" />
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline" onclick="closeModal('modal-size-stock')">Cancel</button>
          <button class="btn btn-primary" onclick="saveSizeStock()">Save</button>
        </div>
      </div>`;
    document.body.appendChild(modal);
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal("modal-size-stock");
    });
  }
  document.getElementById("size-stock-product").innerText = productName;
  document.getElementById("size-stock-size").innerText = size;
  document.getElementById("size-stock-qty").value = currentStock;
  window._currentSizeId = sizeId;
  openModal("modal-size-stock");
}

async function saveSizeStock() {
  const sizeId = window._currentSizeId;
  const newQty = parseInt(document.getElementById("size-stock-qty").value, 10);
  if (isNaN(newQty) || newQty < 0) {
    toast("Enter a valid quantity.", "error");
    return;
  }
  const saveBtn = document.querySelector("#modal-size-stock .btn-primary");
  if (saveBtn) setButtonLoading(saveBtn, true);

  try {
    const res = await fetch(`${API}/inventory.php`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ size_id: sizeId, stock_qty: newQty }),
    });
    const data = await res.json();
    if (data.success) {
      closeModal("modal-size-stock");
      renderInventory();
      renderProductsTable();
      toast("Stock updated.", "ok");
    } else {
      toast(data.error || "Update failed.", "error");
    }
  } catch (err) {
    toast("Network error.", "error");
  } finally {
    if (saveBtn) setButtonLoading(saveBtn, false);
  }
}
function filterInventoryByStatus(status) {
  inventoryStockFilter = status;
  renderInventory();
}

// ========== CONTACT MESSAGES ==========
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
      body.innerHTML = `<tr><td colspan="7" class="text-muted text-sm" style="padding:16px">No messages found.</td></tr>`;
    } else {
      body.innerHTML = data.messages
        .map(
          (m) => `
        <tr style="${m.status === "unread" ? "font-weight:600;" : ""}">
          <td>${escapeHtml(m.name)}</td>
          <td class="text-muted">${escapeHtml(m.email)}</td>
          <td>${escapeHtml(m.subject)}</td>
          <td class="text-muted text-sm" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(m.message)}</td>
          <td>${statusBadge(m.status)}</td>
          <td class="text-muted">${new Date(m.sent_at).toLocaleDateString()}</td>
          <td>
            <div class="flex-center gap-6">
              <button class="btn btn-outline btn-sm" onclick="viewContactMessage(${m.message_id})">View</button>
              <button class="btn btn-primary btn-sm reply-contact-btn" data-id="${m.message_id}" data-email="${escapeHtml(m.email)}" data-subject="${escapeHtml(m.subject)}">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Reply
              </button>
              ${m.status === "unread" ? `<button class="btn btn-outline btn-sm" onclick="markMessage(${m.message_id},'read')">Mark Read</button>` : ""}
            </div>
           </td>
         </tr>
      `,
        )
        .join("");
      // Attach reply listeners without duplicates (clone & replace)
      document.querySelectorAll(".reply-contact-btn").forEach((btn) => {
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        newBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          openReplyModal(
            parseInt(newBtn.dataset.id),
            newBtn.dataset.email,
            newBtn.dataset.subject,
          );
        });
      });
    }
    renderPagination(
      "contact-pagination",
      data.total,
      contactPage,
      20,
      "function(p){contactPage=p;renderContactMessages();}",
    );
  } catch (err) {
    console.error("[staff]", err);
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
    const existing = document.getElementById("_msg-overlay");
    if (existing) existing.remove();
    const overlay = document.createElement("div");
    overlay.id = "_msg-overlay";
    overlay.style.cssText =
      "position:fixed;inset:0;background:rgba(28,17,9,.45);z-index:9000;display:flex;align-items:center;justify-content:center";
    overlay.innerHTML = `<div style="background:#fff;border-radius:4px;padding:28px 32px;max-width:520px;width:90%"><div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--brown-100);margin-bottom:6px">Contact Message</div><div style="font-weight:500;font-size:17px;margin-bottom:4px">${escapeHtml(m.subject)}</div><div style="color:var(--brown-200);font-size:14px;margin-bottom:14px">${escapeHtml(m.name)} &lt;${escapeHtml(m.email)}&gt;</div><div style="font-size:15px;color:var(--brown-300);line-height:1.7;white-space:pre-wrap;border-top:1px solid var(--beige-200);padding-top:12px">${escapeHtml(m.message)}</div><div style="margin-top:20px;text-align:right"><button onclick="document.getElementById('_msg-overlay').remove()" style="background:var(--brown-400);color:#fff;border:none;border-radius:4px;padding:8px 18px;cursor:pointer">Close</button></div></div>`;
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) overlay.remove();
    });
    document.body.appendChild(overlay);
    if (m.status === "unread") markMessage(id, "read");
  } catch (err) {
    console.error(err);
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
    console.error(err);
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
    toast("Subject and message are required.", "error");
    return;
  }
  try {
    const res = await fetch("/styled/php/admin/reply-contact.php", {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        message_id: id,
        reply_subject: subject,
        reply_body: body,
      }),
    });
    const data = await res.json();
    if (data.success) {
      closeModal("modal-reply-contact");
      toast("Reply sent successfully.", "ok");
      renderContactMessages();
    } else {
      toast(data.error || "Failed to send reply.", "error");
    }
  } catch (err) {
    toast("Network error. Could not send reply.", "error");
  }
}

// ========== CHARTS ==========
const chartInstances = {};
const BRAND_GOLD = "rgba(184,146,74,1)";
const BRAND_GOLD_BG = "rgba(184,146,74,0.12)";
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
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: "#b8a090", font: { size: 12 } },
      border: { display: false },
    },
    y: {
      grid: { color: "rgba(230,221,210,0.6)" },
      ticks: { color: "#b8a090", font: { size: 12 } },
      border: { display: false },
    },
  },
};
function destroyChart(id) {
  if (chartInstances[id]) {
    chartInstances[id].destroy();
    delete chartInstances[id];
  }
}
function initCharts(page) {
  if (page === "dashboard") {
    destroyChart("statusChart");
    const sCtx = document.getElementById("statusChart");
    if (sCtx) {
      chartInstances["statusChart"] = new Chart(sCtx, {
        type: "line",
        data: {
          labels: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
          datasets: [
            {
              label: "Orders",
              data: [0, 0, 0, 0, 0, 0, 0],
              borderColor: BRAND_GOLD,
              backgroundColor: BRAND_GOLD_BG,
              fill: true,
              tension: 0.4,
              pointBackgroundColor: "rgba(61,36,16,0.65)",
              pointRadius: 4,
              borderWidth: 2,
            },
          ],
        },
        options: baseOptions,
      });
    }
  }
}

// ========== RESTRICTIONS & LOGOUT ==========
function applyStaffRestrictions() {
  document
    .querySelectorAll('[onclick*="modal-add-product"]')
    .forEach((el) => (el.style.display = "none"));
  document
    .querySelectorAll('[onclick*="modal-add-promo"]')
    .forEach((el) => (el.style.display = "none"));
  document
    .querySelectorAll(".revenue-chart-card, #revenueChart")
    .forEach((el) => {
      const card = el.closest(".card") || el;
      if (card) card.style.display = "none";
    });
}
async function handleLogout() {
  try {
    await fetch("/styled/php/auth/logout.php", {
      method: "POST",
      credentials: "include",
    });
  } catch (e) {}
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
      if (document.getElementById("sidebar-avatar"))
        document.getElementById("sidebar-avatar").textContent = initials(
          u.full_name,
        );
      if (document.getElementById("sidebar-name"))
        document.getElementById("sidebar-name").textContent = u.full_name;
      if (document.getElementById("sidebar-email"))
        document.getElementById("sidebar-email").textContent = u.email;
      if (
        document.getElementById("page-title") &&
        currentPage === "dashboard"
      ) {
        document.getElementById("page-title").innerHTML =
          `Welcome back, <span>${u.full_name.split(" ")[0]}</span>`;
      }
    }
  } catch (err) {
    console.error(err);
  }
}

// ========== INIT ==========
function init() {
  applyStaffRestrictions();
  populateUserInfo();
  renderDashboard();
}
document.addEventListener("DOMContentLoaded", init);
