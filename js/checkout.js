let appliedPromo = null;
let activePaymentMethod = "card";
let checkoutSettings = {
  shipping: {},
  tax: {},
  payment: {},
};
let settingsLoaded = false;

// ── Load settings from public API ───────────────────────────────────────────
async function loadCheckoutSettings() {
  try {
    const [shippingRes, taxRes, paymentRes] = await Promise.all([
      fetch(`${API_BASE}/php/settings.php?group=shipping`).then((r) =>
        r.json(),
      ),
      fetch(`${API_BASE}/php/settings.php?group=tax`).then((r) => r.json()),
      fetch(`${API_BASE}/php/settings.php?group=payment`).then((r) => r.json()),
    ]);
    if (shippingRes.success) checkoutSettings.shipping = shippingRes.settings;
    if (taxRes.success) checkoutSettings.tax = taxRes.settings;
    if (paymentRes.success) checkoutSettings.payment = paymentRes.settings;
    settingsLoaded = true;
    updatePaymentTabs();
  } catch (e) {
    console.warn("Could not load admin settings, using defaults", e);
  }
}

function updatePaymentTabs() {
  const tabContainer = document.querySelector(".payment-tabs");
  if (!tabContainer) return;

  const methods = [
    {
      id: "card",
      label: "Credit / Debit Card",
      enabled: checkoutSettings.payment?.["payment-card"] === "1",
    },
    {
      id: "gcash",
      label: "GCash",
      enabled: checkoutSettings.payment?.["payment-gcash"] === "1",
    },
    {
      id: "cod",
      label: "Cash on Delivery",
      enabled: checkoutSettings.payment?.["payment-cod"] === "1",
    },
  ];

  const enabledMethods = methods.filter((m) => m.enabled);
  if (enabledMethods.length === 0) {
    tabContainer.innerHTML = `<button class="payment-tab active" data-method="card">Credit / Debit Card</button>`;
    activePaymentMethod = "card";
    showPaymentPanel("card");
    return;
  }

  tabContainer.innerHTML = enabledMethods
    .map(
      (m, idx) => `
    <button class="payment-tab ${idx === 0 ? "active" : ""}" data-method="${m.id}" type="button">${m.label}</button>
  `,
    )
    .join("");

  document.querySelectorAll(".payment-tab").forEach((tab) => {
    tab.addEventListener("click", () => {
      document
        .querySelectorAll(".payment-tab")
        .forEach((t) => t.classList.remove("active"));
      tab.classList.add("active");
      activePaymentMethod = tab.dataset.method;
      showPaymentPanel(activePaymentMethod);
    });
  });

  const firstMethod = enabledMethods[0].id;
  activePaymentMethod = firstMethod;
  showPaymentPanel(firstMethod);
}

function showPaymentPanel(method) {
  document.getElementById("payment-panel-card").style.display =
    method === "card" ? "block" : "none";
  document.getElementById("payment-panel-gcash").style.display =
    method === "gcash" ? "block" : "none";
  document.getElementById("payment-panel-cod").style.display =
    method === "cod" ? "block" : "none";
}

// ── Render cart (uses settings for shipping & tax) ──────────────────────────
async function renderCart() {
  const cart = await getCart();
  const tbody = document.getElementById("cart-table-body");
  const emptyEl = document.getElementById("cart-empty");
  const totalsEl = document.getElementById("order-totals");
  const tipBox = document.getElementById("style-tip-box");

  tbody.innerHTML = "";

  if (cart.length === 0) {
    emptyEl.style.display = "block";
    totalsEl.style.display = "none";
    tipBox.style.display = "none";
    document.querySelector(".btn-place-order")?.classList.add("disabled");
    return;
  }

  emptyEl.style.display = "none";
  totalsEl.style.display = "flex";
  tipBox.style.display = "block";
  document.querySelector(".btn-place-order")?.classList.remove("disabled");

  const firstItem = cart[0];
  document.getElementById("style-tip-img").src =
    firstItem.img || "assets/images/logo_styled.png";
  const freeThreshold = parseFloat(
    checkoutSettings.shipping?.["shipping-free-threshold"] || 1000,
  );
  document.getElementById("style-tip-text").textContent =
    `You've added ${cart.length} item${cart.length > 1 ? "s" : ""} to your bag. Complete your look by pairing your selections with our curated accessories — free shipping on orders over ₱${freeThreshold.toFixed(0)}!`;

  let subtotal = 0;
  let totalQty = 0;

  cart.forEach((item, idx) => {
    const unitPrice = parsePrice(item.price);
    const qty = item.qty || 1;
    const lineTotal = unitPrice * qty;
    subtotal += lineTotal;
    totalQty += qty;

    const sizeLabel = item.size
      ? `<span style="margin-left:6px;font-size:13px;color:var(--text-muted);">Size: ${escapeHtml(item.size)}</span>`
      : "";
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td><div class="item-cell"><div class="item-thumb-placeholder"><img class="item-thumb" src="${escapeHtml(item.img)}" alt="${escapeHtml(item.name)}" onerror="this.style.display='none'" style="width:56px;height:64px;object-fit:cover;" /></div><div><p class="item-name">${escapeHtml(item.name)}</p><p class="item-meta">${escapeHtml(item.category || "")}${sizeLabel}</p></div></div></td>
      <td data-label="Qty"><div class="qty-control"><button class="qty-btn" data-idx="${idx}" data-delta="-1">−</button><span class="qty-num">${qty}</span><button class="qty-btn" data-idx="${idx}" data-delta="1">+</button></div></td>
      <td data-label="Price">${formatPrice(unitPrice)}</td>
      <td data-label="Total">${formatPrice(lineTotal)}</td>
      <td><button class="remove-btn" data-idx="${idx}">Remove</button></td>
    `;
    tbody.appendChild(tr);
  });

  const badge = document.getElementById("nav-cart-badge");
  if (badge) badge.textContent = totalQty;

  updateTotals(subtotal);

  // Re-attach events
  document.querySelectorAll(".qty-btn").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const idx = parseInt(btn.dataset.idx);
      const delta = parseInt(btn.dataset.delta);
      const cart = await getCart();
      cart[idx].qty = Math.min(99, Math.max(1, (cart[idx].qty || 1) + delta));
      await saveCart(cart);
      renderCart();
    });
  });

  document.querySelectorAll(".remove-btn").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const idx = parseInt(btn.dataset.idx);
      const row = btn.closest("tr");

      btn.disabled = true;
      row?.classList.add("removing");

      await new Promise((resolve) => setTimeout(resolve, 220));

      const cart = await getCart();
      const removed = cart.splice(idx, 1)[0];

      await fetch(`${API_BASE}/php/cart.php`, {
        method: "DELETE",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          product_id: removed.product_id,
          size: removed.size || "",
        }),
      });

      await getCart();
      renderCart();
    });
  });
}

function updateTotals(subtotal) {
  const freeThreshold = parseFloat(
    checkoutSettings.shipping?.["shipping-free-threshold"] || 1000,
  );
  const standardFee = parseFloat(
    checkoutSettings.shipping?.["shipping-standard-fee"] || 150,
  );
  const shipping = subtotal >= freeThreshold ? 0 : standardFee;

  let discount = 0;
  if (appliedPromo) {
    if (appliedPromo.discount_type === "percent") {
      discount = subtotal * (appliedPromo.discount_value / 100);
    } else {
      discount = appliedPromo.discount_value;
    }
    discount = Math.min(discount, subtotal);
  }

  const taxRate = parseFloat(checkoutSettings.tax?.["tax-vat-rate"] || 0);
  const taxable = subtotal - discount;
  const tax = taxable * (taxRate / 100);
  const grand = subtotal - discount + shipping + tax;

  document.getElementById("subtotal-val").textContent = formatPrice(subtotal);
  document.getElementById("shipping-val").textContent =
    shipping === 0 ? "FREE" : formatPrice(shipping);

  // Show/hide tax row
  let taxRow = document.getElementById("tax-row");
  if (tax > 0) {
    if (!taxRow) {
      const shippingRow = document
        .getElementById("shipping-val")
        ?.closest(".total-row");
      if (shippingRow) {
        taxRow = document.createElement("div");
        taxRow.id = "tax-row";
        taxRow.className = "total-row";
        taxRow.style.fontSize = "15px";
        taxRow.innerHTML = `<span>VAT (${taxRate}%)</span><span id="tax-val"></span>`;
        shippingRow.insertAdjacentElement("afterend", taxRow);
      }
    }
    const taxVal = document.getElementById("tax-val");
    if (taxVal) taxVal.textContent = formatPrice(tax);
  } else if (taxRow) taxRow.remove();

  // Show/hide discount row
  let discountRow = document.getElementById("discount-row");
  if (appliedPromo && discount > 0) {
    if (!discountRow) {
      const subtotalRow = document
        .getElementById("subtotal-val")
        ?.closest(".total-row");
      if (subtotalRow) {
        discountRow = document.createElement("div");
        discountRow.id = "discount-row";
        discountRow.className = "total-row";
        discountRow.style.color = "var(--accent, #27ae60)";
        discountRow.innerHTML = `<span>Discount (${appliedPromo.code})</span><span id="discount-val"></span>`;
        subtotalRow.insertAdjacentElement("afterend", discountRow);
      }
    }
    const discountVal = document.getElementById("discount-val");
    if (discountVal) discountVal.textContent = `−${formatPrice(discount)}`;
  } else if (discountRow) discountRow.remove();

  const grandTotalEl = document.getElementById("grand-total-val");
  if (grandTotalEl) {
    grandTotalEl.textContent = formatPrice(grand);
    grandTotalEl.classList.remove("total-pulse");
    void grandTotalEl.offsetWidth;
    grandTotalEl.classList.add("total-pulse");
  }
}

async function applyPromo() {
  const code = document.getElementById("co-promo").value.trim().toUpperCase();
  if (!code) {
    showToast("Please enter a promo code.", "error");
    return;
  }
  const cart = await getCart();
  let subtotal = 0;
  cart.forEach(
    (item) => (subtotal += parsePrice(item.price) * (item.qty || 1)),
  );
  try {
    const res = await fetch(
      `${API_BASE}/php/promotions.php?code=${encodeURIComponent(code)}&subtotal=${subtotal}`,
    );
    const data = await res.json();
    if (data.valid) {
      appliedPromo = {
        code: data.code,
        discount_type: data.discount_type,
        discount_value: data.discount_value,
      };
      updateTotals(subtotal);
      showToast(data.message, "ok");
    } else {
      appliedPromo = null;
      updateTotals(subtotal);
      showToast(data.message, "error");
    }
  } catch (err) {
    showToast("Could not validate promo code. Please try again.", "error");
  }
}

let placingOrder = false; // blocks a second click before the first request returns

async function placeOrder() {
  if (placingOrder) return;
  placingOrder = true;
  try {
    await placeOrderInner();
  } finally {
    placingOrder = false;
  }
}

async function placeOrderInner() {
  const cart = await getCart();
  if (cart.length === 0) {
    alert("Your cart is empty!");
    return;
  }

  if (deliveryMode && !deliveryReady) {
    alert("Please set up your delivery address first.");
    window.location.href = "orders.html?next=checkout#address";
    return;
  }

  const baseRequired = [
    { id: "co-name", label: "Full Name" },
    { id: "co-email", label: "Email Address" },
    { id: "co-phone", label: "Phone Number" },
    { id: "co-address", label: "Street Address" },
    { id: "co-city", label: "City" },
    { id: "co-zip", label: "ZIP Code" },
    { id: "co-province", label: "Province / Region" },
  ];

  const paymentRequired = {
    card: [],
    gcash: [],
    cod: [],
  };

  const required = [
    ...baseRequired,
    ...(paymentRequired[activePaymentMethod] || []),
  ];
  for (const field of deliveryMode ? [] : required) {
    const el = document.getElementById(field.id);
    if (!el) continue;
    if (!el.value.trim()) {
      alert(`Please fill in: ${field.label}`);
      el.focus();
      return;
    }
  }

  const email = document.getElementById("co-email").value;
  if (!deliveryMode && (!email.includes("@") || !email.includes("."))) {
    alert("Please enter a valid email address.");
    return;
  }

  // Prices are deliberately NOT sent: the server prices every line from the
  // catalog, so what the customer is charged can't be edited in the browser.
  const items = cart.map((item) => ({
    product_id: item.product_id,
    size: item.size || "",
    qty: item.qty || 1,
  }));

  const shipping_address = {
    street: document.getElementById("co-address").value.trim(),
    city: document.getElementById("co-city").value.trim(),
    province: document.getElementById("co-province").value.trim(),
    zip_code: document.getElementById("co-zip").value.trim(),
    phone: document.getElementById("co-phone").value.trim(),
  };

  const btn = document.getElementById("place-order-btn");
  if (btn) {
    btn.disabled = true;
    btn.textContent = "Placing order…";
  }
  document.querySelector(".btn-place-order")?.classList.add("disabled");

  try {
    const res = await fetch(`${API_BASE}/php/checkout.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        items,
        // Saved address: the server reads it itself. The inputs are only used
        // when nobody is signed in.
        ...(deliveryMode ? { use_saved_address: true } : { shipping_address }),
        payment_method: activePaymentMethod,
        promo_code: appliedPromo?.code || "",
      }),
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      alert(data.error || "Something went wrong. Please try again.");
      return;
    }

    // COD: no gateway involved, order is placed and confirmed as-is.
    if (!data.requires_payment) {
      document.getElementById("success-order-num").textContent =
        "Order #" + data.order_number;
      document.getElementById("success-overlay").classList.add("show");

      appliedPromo = null;
      await saveCart([]);
      renderCart();
      return;
    }

    // Card / GCash: the order exists but is "unpaid" until PayMongo
    // confirms it. Ask our backend to open a PayMongo checkout session,
    // then send the customer there to actually pay.
    if (btn) btn.textContent = "Redirecting to secure payment…";

    const payRes = await fetch(`${API_BASE}/php/create_payment.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ order_id: data.order_id }),
    });
    const payData = await payRes.json();

    if (!payRes.ok || !payData.success || !payData.checkout_url) {
      alert(
        payData.error ||
          "Could not start payment. Your order was saved — you can retry payment from Order History.",
      );
      appliedPromo = null;
      await saveCart([]);
      renderCart();
      return;
    }

    await saveCart([]);
    window.location.href = payData.checkout_url;
  } catch (err) {
    console.error("placeOrder error:", err);
    alert(
      "A network error occurred. Please check your connection and try again.",
    );
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = "Place Order";
    }
    document.querySelector(".btn-place-order")?.classList.remove("disabled");
  }
}

// ── Delivery details come from the customer's own saved address ─────────────
// Nothing is typed or pre-filled here. The customer sets their address up once
// under My Address; checkout just shows it ("Deliver to ...") with a Change link,
// and the server reads the saved address itself when the order is placed.
let deliveryMode = false; // signed in: checkout uses the saved address
let deliveryReady = false; // ...and that address is complete (incl. a phone)

async function loadDeliveryDetails() {
  const box = document.getElementById("delivery-summary");
  const inputs = document.getElementById("address-inputs");
  if (!box || !inputs) return;
  try {
    const res = await fetch(`${API_BASE}/php/address.php`, {
      credentials: "include",
      cache: "no-store",
    });
    const data = res.ok ? await res.json() : null;
    if (!data || !data.success) {
      inputs.style.display = "block"; // not signed in: nothing saved to show
      return;
    }

    deliveryMode = true;
    deliveryReady = !!data.complete;
    const a = data.address || {};
    const p = data.profile || {};
    box.style.display = "block";

    if (deliveryReady) {
      box.innerHTML = `
        <p class="form-section-title">Deliver to</p>
        <div class="delivery-card" style="border:1px solid var(--border);padding:16px 18px;margin-bottom:20px;line-height:1.7;font-size:15px;">
          <strong>${escapeHtml(p.full_name || "")}</strong><br />
          ${escapeHtml(a.street)}<br />
          ${escapeHtml(a.city)}, ${escapeHtml(a.province)} ${escapeHtml(a.zip_code)}<br />
          <span style="color:var(--text-muted)">${escapeHtml(a.phone)} &middot; ${escapeHtml(p.email || "")}</span>
          <div style="margin-top:10px"><a href="orders.html#address" style="font-size:13px;letter-spacing:1px;text-transform:uppercase;">Change address</a></div>
        </div>`;
    } else {
      box.innerHTML = `
        <p class="form-section-title">Deliver to</p>
        <div class="delivery-card" style="border:1px solid var(--border);padding:16px 18px;margin-bottom:20px;line-height:1.7;font-size:15px;">
          Set up your delivery address${a.street ? " (add your phone number)" : ""} to place an order.
          <div style="margin-top:10px"><a class="btn-primary" href="orders.html?next=checkout#address" style="display:inline-block;padding:11px 20px;font-size:12px;">Set up address</a></div>
        </div>`;
    }
  } catch (e) {
    console.warn("Could not load delivery details", e);
    inputs.style.display = "block";
  }
}

// ── Initialise ───────────────────────────────────────────────────────────────
async function initCheckout() {
  await loadCheckoutSettings();
  renderCart();
  loadDeliveryDetails();
}

// Event listeners
document.querySelectorAll(".payment-tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    document
      .querySelectorAll(".payment-tab")
      .forEach((t) => t.classList.remove("active"));
    tab.classList.add("active");
    activePaymentMethod = tab.dataset.method;
    showPaymentPanel(activePaymentMethod);
  });
});

const promoBtn = document.getElementById("apply-promo-btn");
if (promoBtn) promoBtn.addEventListener("click", applyPromo);

const placeOrderBtn = document.getElementById("place-order-btn");
if (placeOrderBtn) placeOrderBtn.addEventListener("click", placeOrder);

document.querySelector(".success-overlay")?.addEventListener("click", (e) => {
  if (e.target.classList.contains("success-overlay"))
    e.target.classList.remove("show");
});

initCheckout();

// If the customer cancelled/backed out of PayMongo's hosted checkout, they
// land back here with ?payment=cancelled. Cancel the order server-side so the
// units it was holding go straight back on sale (instead of staying locked
// away from other customers). They can still reopen and pay it from Order
// History while those items remain in stock.
const checkoutParams = new URLSearchParams(window.location.search);
if (checkoutParams.get("payment") === "cancelled") {
  const orderNum = checkoutParams.get("order");
  if (orderNum) {
    fetch(`${API_BASE}/php/cancel_payment.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ order_number: orderNum }),
    }).catch(() => {});
  }
  showToast?.(
    orderNum
      ? `Payment for order #${orderNum} was cancelled. You can retry it from Order History.`
      : "Payment was cancelled.",
    "error",
  );
  // Clean the URL so a refresh doesn't repeat the cancel / toast.
  window.history.replaceState({}, "", window.location.pathname);
}
