function getStatusClass(status) {
  const map = {
    Delivered: "status-delivered",
    Processing: "status-processing",
    Shipped: "status-shipped",
    Cancelled: "status-cancelled",
    Pending: "status-pending",
  };
  return map[status] || "status-processing";
}

// Small suffix on the status pill so an unpaid / failed order is never
// mistaken for a confirmed one. A cancelled order already says "Cancelled".
function paymentSuffix(order) {
  if ((order.status || "").toLowerCase() === "cancelled") return "";
  if (order.payment_status === "failed") return " · Payment failed";
  if (order.payment_status === "unpaid" || order.payment_status === "processing") return " · Unpaid";
  return "";
}

// ── INIT USER INFO ───────────────────────────
function initUserInfo() {
  const user = getCurrentUser();
  if (!user) {
    window.location.href = "auth.html";
    return;
  }
  const nameEl = document.getElementById("os-name");
  const avatarEl = document.getElementById("os-avatar");
  if (nameEl) {
    const fullName = user.full_name || user.name || "Guest";
    nameEl.innerHTML = `<em>${escapeHtml(fullName.split(" ")[0])}</em>`;
  }
  if (avatarEl) {
    const fullName = user.full_name || user.name;
    let initials = "?";
    if (fullName) {
      const nameParts = fullName.split(" ");
      initials = nameParts
        .map(part => part.charAt(0))
        .join("")
        .toUpperCase()
        .slice(0, 2);
    }
    avatarEl.textContent = initials;
  }
}

// ── FETCH ORDERS (list) ─────────────────────
async function fetchOrders() {
  try {
    const res = await fetch("php/orders.php", { credentials: "include" });
    if (res.status === 401) {
      window.location.href = "auth.html";
      return [];
    }
    const data = await res.json();
    return data.orders || [];
  } catch (err) {
    console.error("fetchOrders error:", err);
    return [];
  }
}

// ── FETCH SINGLE ORDER (full detail) ────────
async function fetchOrder(orderId) {
  try {
    const res = await fetch(`php/orders.php?id=${encodeURIComponent(orderId)}`, { credentials: "include" });
    if (res.status === 401) {
      window.location.href = "auth.html";
      return null;
    }
    const data = await res.json();
    return data.order || null;
  } catch (err) {
    console.error("fetchOrder error:", err);
    return null;
  }
}

// ── RENDER ORDER LIST ───────────────────────
async function renderOrderList() {
  const container = document.getElementById("orders-container");
  const emptyEl = document.getElementById("orders-empty");
  if (container) {
    container.style.display = "flex";
    container.innerHTML = `<p style="color:#8c6d57;padding:2rem 0;text-align:center;width:100%">Loading orders…</p>`;
  }
  const orders = await fetchOrders();
  if (!orders || orders.length === 0) {
    if (container) container.style.display = "none";
    if (emptyEl) emptyEl.style.display = "flex";
    return;
  }
  if (container) container.style.display = "flex";
  if (emptyEl) emptyEl.style.display = "none";

  container.innerHTML = orders
    .map((order) => {
      const firstItem = order.items?.[0];
      const thumbSrc = firstItem?.img || "";
      const itemCount = order.items?.length || 1;
      const thumbHTML = thumbSrc
        ? `<img class="order-thumb" src="${escapeHtml(thumbSrc)}" alt="${escapeHtml(firstItem?.name || "")}" onerror="this.style.display='none'" />`
        : `<div class="order-thumb-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg></div>`;
      return `
      <div class="order-row" data-order-id="${escapeHtml(order.id)}">
        ${thumbHTML}
        <div class="order-info">
          <p class="order-num">${escapeHtml(order.id)}</p>
          <p class="order-meta">${escapeHtml(order.date_time || order.date)} &bull; ${itemCount} item${itemCount !== 1 ? "s" : ""}</p>
        </div>
        <p class="order-price">${escapeHtml(order.total)}</p>
        <span class="order-status ${getStatusClass(order.status)}">${escapeHtml(order.status)}${paymentSuffix(order)}</span>
        <div class="order-arrow">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </div>
      </div>
    `;
    })
    .join("");

  document.querySelectorAll(".order-row").forEach((row) => {
    row.addEventListener("click", async () => {
      const orderId = row.dataset.orderId;
      const fullOrder = await fetchOrder(orderId);
      if (fullOrder) {
        openOrderDetail(fullOrder);
      } else {
        console.error("Failed to load order details for", orderId);
      }
    });
  });
}

function buildFallbackSteps(status) {
  const statusMap = {
    pending: 1,
    processing: 2,
    shipped: 3,
    delivered: 5,
    cancelled: 1,
  };
  const doneCount = statusMap[(status || "pending").toLowerCase()] ?? 1;
  const labels = ["Order Placed", "Processing", "Shipped", "Out for Delivery", "Delivered"];
  return labels.map((label, i) => ({
    label,
    date: "—",
    done: i + 1 <= doneCount,
    active: i + 1 === doneCount && doneCount !== 5,
  }));
}

function renderTrackingStepper(trackingData) {
  const stepper = document.getElementById("tracking-stepper");
  if (!stepper) return;
  const steps = trackingData.steps || [];
  const trackingNumber = trackingData.tracking_number;
  const estimatedDelivery = trackingData.estimated_delivery;

  let activeIdx = -1;
  steps.forEach((s, i) => {
    if (s.done || s.active) activeIdx = i;
  });

  const stepsHTML = steps
    .map((step, i) => {
      const isDone = i < activeIdx;
      const isActive = i === activeIdx;
      const cls = isDone ? "ts-step done" : isActive ? "ts-step active" : "ts-step";
      return `<div class="${cls}"><div class="ts-dot"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div><p class="ts-label">${escapeHtml(step.label)}</p><p class="ts-date">${escapeHtml(step.datetime || (step.date !== "—" ? step.date : ""))}</p></div>`;
    })
    .join("");

  let metaHTML = "";
  if (trackingNumber || estimatedDelivery) {
    metaHTML = `<div class="ts-meta-bar">${trackingNumber ? `<span class="ts-meta-item"><span class="ts-meta-label">Tracking #</span><strong>${escapeHtml(trackingNumber)}</strong></span>` : ""}${estimatedDelivery ? `<span class="ts-meta-item"><span class="ts-meta-label">Est. Delivery</span><strong>${escapeHtml(estimatedDelivery)}</strong></span>` : ""}</div>`;
  }
  stepper.innerHTML = metaHTML + stepsHTML;
}

// ── Live payment status ─────────────────────────────────────────────────────
// After PayMongo sends the customer back, the order can still read "Payment
// Processing" for a few seconds. The server asks PayMongo directly every time
// the order is loaded, so we just reload it every few seconds and flip the
// screen to Paid (or Failed) the moment it changes — no manual refresh needed.
let _payWatch = null;

function stopPaymentWatch() {
  if (_payWatch) clearInterval(_payWatch);
  _payWatch = null;
}

function startPaymentWatch(orderNumber) {
  stopPaymentWatch();
  const startedAt = Date.now();
  _payWatch = setInterval(async () => {
    if (Date.now() - startedAt > 3 * 60 * 1000) return stopPaymentWatch();
    const fresh = await fetchOrder(orderNumber);
    if (!fresh || fresh.payment_status === "processing") return;
    stopPaymentWatch();
    openOrderDetail(fresh, { silent: true });
    renderOrderList();
    if (fresh.payment_status === "paid") {
      showToast?.("Payment confirmed — thank you! Your order is being prepared.", "ok");
    }
  }, 4000);
}

function openOrderDetail(order, opts = {}) {
  document.getElementById("view-list").style.display = "none";
  document.getElementById("view-detail").style.display = "block";
  if (!opts.silent) window.scrollTo({ top: 0, behavior: "smooth" });

  document.getElementById("od-num").textContent = order.id;
  const statusBadge = document.getElementById("od-status-badge");
  statusBadge.textContent = order.status;
  statusBadge.className = `od-status-badge ${getStatusClass(order.status)}`;

  document.getElementById("od-date").textContent = order.date_time || order.date;
  renderOrderTimes(order);
  const itemCount = order.items?.length || 1;
  document.getElementById("od-items-count").textContent = `${itemCount} item${itemCount !== 1 ? "s" : ""}`;
  document.getElementById("od-total").textContent = order.total;

  const paymentLabel = order.payment_status_display
    ? `${order.payment || "—"} — ${order.payment_status_display}`
    : order.payment || "—";
  document.getElementById("od-payment").textContent = paymentLabel;

  renderRetryPaymentButton(order);

  let steps = order.tracking?.steps;
  if (!steps || steps.length === 0) {
    steps = buildFallbackSteps(order.status.toLowerCase());
  }
  const trackingData = {
    steps: steps,
    tracking_number: order.tracking?.tracking_number || null,
    estimated_delivery: order.tracking?.estimated_delivery || null,
  };
  renderTrackingStepper(trackingData);

  renderOrderItems(order.items || []);
  document.getElementById("od-address").innerHTML = escapeHtml(order.shipping?.address || "—").replace(/\n/g, "<br>");
  document.getElementById("od-subtotal").textContent = order.subtotal || "₱0.00";
  document.getElementById("od-shipping").textContent = order.shipping?.cost_display || "FREE";
  const discRow = document.getElementById("od-discount-row");
  const taxRow = document.getElementById("od-tax-row");
  if (discRow) {
    discRow.style.display = order.discount_display ? "" : "none";
    document.getElementById("od-discount").textContent = order.discount_display || "";
  }
  if (taxRow) {
    taxRow.style.display = order.tax_display ? "" : "none";
    document.getElementById("od-tax").textContent = order.tax_display || "";
  }
  document.getElementById("od-grand").textContent = order.total;

  // Still waiting on PayMongo? Keep this screen current until it settles.
  if (order.payment_status === "processing") startPaymentWatch(order.id);
  else stopPaymentWatch();
}

// Every payment event carries its own server-set timestamp (Asia/Manila).
function renderOrderTimes(order) {
  document.getElementById("od-times")?.remove();
  const rows = [
    ["Paid", order.paid_at],
    ["Payment failed", order.failed_at],
    ["Cancelled", order.cancelled_at],
  ].filter(([, v]) => v);
  if (!rows.length) return;
  const anchor = document.getElementById("od-date");
  if (!anchor) return;
  const el = document.createElement("p");
  el.id = "od-times";
  el.className = "od-meta-val";
  el.style.cssText = "font-size:12px;opacity:.75;margin-top:4px;line-height:1.6";
  el.innerHTML = rows.map(([k, v]) => `${escapeHtml(k)}: ${escapeHtml(v)}`).join("<br>");
  anchor.insertAdjacentElement("afterend", el);
}

function renderRetryPaymentButton(order) {
  // Remove any existing button first (re-render on every detail open).
  document.getElementById("od-retry-payment-btn")?.remove();

  if (!order.can_retry_payment || !order.order_id) return;

  const paymentSection = document.getElementById("od-payment")?.closest("div");
  if (!paymentSection) return;

  const btn = document.createElement("button");
  btn.id = "od-retry-payment-btn";
  btn.className = "btn-primary";
  btn.style.marginTop = "10px";
  btn.style.fontSize = "12px";
  btn.style.padding = "10px 20px";
  const retryLabel =
    order.payment_status === "unpaid" || order.payment_status === "processing"
      ? "Complete Payment"
      : "Retry Payment";
  btn.textContent = retryLabel;

  btn.addEventListener("click", async () => {
    btn.disabled = true;
    btn.textContent = "Redirecting…";
    try {
      const res = await fetch(`${API_BASE}/php/create_payment.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ order_id: order.order_id }),
      });
      const data = await res.json();
      if (!res.ok || !data.success || !data.checkout_url) {
        alert(data.error || "Could not start payment. Please try again.");
        btn.disabled = false;
        btn.textContent = retryLabel;
        return;
      }
      window.location.href = data.checkout_url;
    } catch (err) {
      console.error("retry payment error:", err);
      alert("A network error occurred. Please try again.");
      btn.disabled = false;
      btn.textContent = retryLabel;
    }
  });

  paymentSection.appendChild(btn);
}

function renderOrderItems(items) {
  const list = document.getElementById("od-items-list");
  if (!list) return;
  if (!items.length) {
    list.innerHTML = '<div class="od-item-row">No items found.</div>';
    return;
  }
  list.innerHTML = items
    .map(
      (item) => `
    <div class="od-item-row">
      <img class="od-item-img" src="${escapeHtml(item.img || "")}" alt="${escapeHtml(item.product_name || "Product")}" onerror="this.style.opacity='0'" />
      <div class="od-item-info">
        <p class="od-item-name">${escapeHtml(item.product_name || "Product")}</p>
        <div class="od-item-meta">
          ${item.size && item.size !== "—" ? `<span>Size: ${escapeHtml(item.size)}</span>` : ""}
          <span>Qty: ${Number(item.qty) || 1}</span>
        </div>
      </div>
      <p class="od-item-price">${escapeHtml(item.price || "₱0.00")}</p>
    </div>
  `,
    )
    .join("");
}

document.getElementById("back-btn")?.addEventListener("click", () => {
  stopPaymentWatch();
  document.getElementById("view-detail").style.display = "none";
  document.getElementById("view-list").style.display = "block";
  window.scrollTo({ top: 0, behavior: "smooth" });
});

function handleSignOut() {
  fetch("/styled/php/auth/logout.php", { method: "GET", credentials: "include" }).finally(() => {
    localStorage.removeItem("styled_user");
    window.location.href = "auth.html";
  });
}
document.getElementById("os-signout")?.addEventListener("click", handleSignOut);

const tabOrders = document.getElementById("tab-orders");
const tabWishlist = document.getElementById("tab-wishlist");
function setActiveTab(activeEl) {
  document.querySelectorAll(".os-nav-item").forEach((el) => el.classList.remove("active"));
  if (activeEl) activeEl.classList.add("active");
}
tabOrders?.addEventListener("click", (e) => {
  e.preventDefault();
  setActiveTab(tabOrders);
  document.getElementById("view-list").style.display = "block";
  document.getElementById("view-detail").style.display = "none";
});
tabWishlist?.addEventListener("click", (e) => {
  e.preventDefault();
  setActiveTab(tabWishlist);
  openWishlistPanel();
});

window.initOrdersPage = function () {
  initUserInfo();
  renderOrderList();
  setActiveTab(tabOrders);
  setTimeout(() => setActiveTab(document.getElementById("tab-orders")), 300);
  handlePaymentReturnParams();
};

// PayMongo redirects the customer back here after checkout. success_url
// includes ?payment=success&order=STY-...; cancel_url on checkout.html
// includes ?payment=cancelled instead (see checkout.js). The actual
// paid/failed status still comes from the webhook — this is just a
// friendly landing message and a shortcut to the relevant order.
async function handlePaymentReturnParams() {
  const params = new URLSearchParams(window.location.search);
  const paymentResult = params.get("payment");
  const orderNumber = params.get("order");
  if (!paymentResult) return;

  if (paymentResult === "success" && orderNumber) {
    showToast?.(
      "Payment received! We're confirming it now — your order status will update shortly.",
      "ok",
    );
    const fullOrder = await fetchOrder(orderNumber);
    if (fullOrder) openOrderDetail(fullOrder);
  }

  // Clean the query string so a page refresh doesn't re-trigger this.
  window.history.replaceState({}, "", window.location.pathname);
}