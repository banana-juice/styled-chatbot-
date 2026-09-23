let currentSlide = 1;
let activePriceRange = "all";
let currentCategory = "tops";

// Hero Slider
function goToSlide(n) {
  const prevSlide = document.getElementById("slide-" + currentSlide);
  const newSlide = document.getElementById("slide-" + n);
  if (prevSlide) prevSlide.classList.remove("active");
  if (newSlide) newSlide.classList.add("active");

  const dots = document.querySelectorAll(".slide-dot");
  if (dots[currentSlide - 1]) dots[currentSlide - 1].classList.remove("active");
  if (dots[n - 1]) dots[n - 1].classList.add("active");

  currentSlide = n;
}

setInterval(() => {
  goToSlide(currentSlide === 3 ? 1 : currentSlide + 1);
}, 5000);

document.querySelectorAll(".slide-dot").forEach((dot) => {
  dot.addEventListener("click", () => goToSlide(parseInt(dot.dataset.slide)));
});

// "View Collection" → scroll to categories section
document.querySelectorAll(".btn-outline").forEach((btn) => {
  if (btn.textContent.trim() === "View Collection") {
    btn.textContent = "Explore Lookbook";
    btn.href = "#categories";
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      const target = document.getElementById("categories");
      if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
});

// Show Category
function showCategory(cat, el) {
  currentCategory = cat;
  document
    .querySelectorAll(".category-item")
    .forEach((i) => i.classList.remove("selected"));
  if (el) el.classList.add("selected");
  document
    .getElementById("products-section")
    .scrollIntoView({ behavior: "smooth", block: "start" });
  renderProducts(cat);
}

// ── Loading / error helpers ───────────────────────────────────────────────────
function showProductsSpinner() {
  const grid = document.getElementById("products-grid");
  if (!grid) return;
  grid.innerHTML = `
    <div style="grid-column:1/-1;display:flex;flex-direction:column;align-items:center;
                justify-content:center;padding:80px 0;gap:16px;color:var(--text-muted);">
      <div style="width:32px;height:32px;border:2px solid #e0d6cc;
                  border-top-color:#8c7b6e;border-radius:50%;
                  animation:spin .75s linear infinite;"></div>
      <span style="font-size:14px;letter-spacing:1.5px;text-transform:uppercase;">
        Loading products…
      </span>
    </div>
    <style>@keyframes spin{to{transform:rotate(360deg)}}</style>`;
}

function showProductsError(message) {
  const grid = document.getElementById("products-grid");
  if (!grid) return;
  grid.innerHTML = `
    <div style="grid-column:1/-1;text-align:center;padding:60px 0;
                color:var(--text-muted);font-size:15px;letter-spacing:1px;">
      <svg style="width:32px;height:32px;margin-bottom:12px;opacity:.4;"
           viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <br>${message || "Failed to load products."}
      <br><button onclick="renderProducts(currentCategory)"
            style="margin-top:14px;padding:6px 18px;border:1px solid currentColor;
                   background:none;cursor:pointer;font-size:14px;letter-spacing:1px;">
        Try again
      </button>
    </div>`;

  grid.querySelectorAll(".product-card").forEach((card) => {
    card.addEventListener("click", async (e) => {
      if (e.target.closest(".wish-btn") || e.target.closest(".btn-cart"))
        return;
      const productId = card.dataset.productId;
      if (!productId) return;

      try {
        const res = await fetch(`/styled/php/products.php?id=${productId}`);
        const data = await res.json();
        if (data.success && data.product) {
          openProductModal(data.product, data.product.category_slug);
        } else {
          showToast("Product details not available.");
        }
      } catch (err) {
        console.error("Failed to load product:", err);
        showToast("Error loading product.");
      }
    });
  });
}

async function renderProducts(cat) {
  const grid = document.getElementById("products-grid");
  const titleEl = document.getElementById("products-cat-title");

  const staticCat = CATEGORIES[cat];
  if (titleEl && staticCat) titleEl.textContent = staticCat.label;

  showProductsSpinner();

  let products = [];
  let usedFallback = false;

  try {
    let min_price, max_price;
    if (activePriceRange !== "all") {
      [min_price, max_price] = activePriceRange.split("-").map(Number);
    }

    const raw = await fetchProducts({ category: cat, min_price, max_price });
    products = raw.map(normaliseProduct);

    _updateProductCache(products);
    searchIndex = buildSearchIndex();

    if (products.length > 0 && titleEl) {
      titleEl.textContent = "Category: " + products[0].category_name;
    }
  } catch (err) {
    console.warn("products.php fetch failed — using static fallback:", err);
    usedFallback = true;
    if (!staticCat) {
      showProductsError("Category not found.");
      return;
    }
    products = staticCat.products.map((p) => ({
      ...p,
      img: p.img,
    }));
  }

  // Client-side price filter (for fallback)
  const filtered = products.filter((p) => {
    const priceValue = p.price_num != null ? p.price_num : parsePrice(p.price);
    return productMatchesPrice(String(priceValue), activePriceRange);
  });

  if (filtered.length === 0) {
    grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:60px 0;color:var(--text-muted);">No products match this price range.</div>`;
    return;
  }

  grid.innerHTML = filtered
    .map((p) => {
      const imgSrc = p.primary_image || p.img || p.image || "";
      // Format price with ₱
      let formattedPrice = p.price;
      if (typeof formattedPrice === "number") {
        formattedPrice = `₱${formattedPrice.toFixed(2)}`;
      } else if (
        typeof formattedPrice === "string" &&
        !formattedPrice.startsWith("₱")
      ) {
        formattedPrice = `₱${parseFloat(formattedPrice).toFixed(2)}`;
      }
      return `
       <div class="product-card" data-product-id="${p.product_id}" data-product-name="${p.name.replace(/"/g, "&quot;")}" data-category="${cat}">
          <div class="product-img-wrap">
            <button class="wish-btn ${isInWishlist(p.name) ? "active" : ""}" 
                    data-product-name="${p.name.replace(/"/g, "&quot;")}" 
                    data-product='${JSON.stringify({ name: p.name, price: formattedPrice, img: imgSrc, description: p.description }).replace(/'/g, "&#39;")}'>
              <svg class="wish-icon" viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6z" fill="currentColor"/></svg>
            </button>
            <img src="${imgSrc}" alt="${p.name}" loading="lazy" onerror="this.src='/styled/assets/images/placeholder.jpg'" />
          </div>
          <p class="product-name">${p.name}</p>
          <p class="product-price">${formattedPrice}</p>
          <button class="btn-cart" data-product-id="${p.product_id}" data-product-category="${cat}" data-product='${JSON.stringify({ ...p, price: formattedPrice }).replace(/'/g, "&#39;")}'>
            <svg class="cart-btn-icon" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" fill="currentColor"/><line x1="3" y1="6" x2="21" y2="6" stroke="white" stroke-width="1.5"/><path d="M16 10a4 4 0 0 1-8 0" fill="none" stroke="white" stroke-width="1.5"/></svg>
            Add to Cart
          </button>
        </div>
      `;
    })
    .join("");

  // ── Wishlist button handler ─────────────────────────────
  const wishlistHandler = (e) => {
    e.stopPropagation();
    const btn = e.currentTarget;
    const productData = JSON.parse(btn.dataset.product.replace(/&#39;/g, "'"));
    toggleWishlistItem(productData);
    btn.classList.toggle("active", isInWishlist(productData.name));
  };

  grid.querySelectorAll(".wish-btn").forEach((btn) => {
    btn.removeEventListener("click", wishlistHandler);
    btn.addEventListener("click", wishlistHandler);
  });

  // ── Product card click (opens modal) ────────────────────
  grid.querySelectorAll(".product-card").forEach((card) => {
    card.addEventListener("click", async (e) => {
      if (e.target.closest(".wish-btn") || e.target.closest(".btn-cart"))
        return;
      const productId = card.dataset.productId;
      if (!productId) return;
      try {
        const res = await fetch(`/styled/php/products.php?id=${productId}`);
        const data = await res.json();
        if (data.success && data.product) {
          const prod = data.product;
          let modalPrice = prod.price;
          if (typeof modalPrice === "number") {
            modalPrice = `₱${modalPrice.toFixed(2)}`;
          } else if (
            typeof modalPrice === "string" &&
            !modalPrice.startsWith("₱")
          ) {
            modalPrice = `₱${parseFloat(modalPrice).toFixed(2)}`;
          }
          const modalProduct = {
            product_id: prod.product_id,
            name: prod.name,
            price: modalPrice,
            img:
              prod.images?.find((img) => img.is_primary)?.image_url ||
              prod.primary_image ||
              "",
            description: prod.description,
          };
          openProductModal(modalProduct, data.product.category_slug);
        } else {
          showToast("Product details not available.");
        }
      } catch (err) {
        console.error("Failed to load product:", err);
        showToast("Error loading product.");
      }
    });
  });

  // ── Add to Cart button handler ──────────────────────────
  const cartButtonHandler = async (e) => {
    e.stopPropagation();
    const btn = e.currentTarget;
    const productId = btn.dataset.productId;
    const category = btn.dataset.productCategory;
    const user = getCurrentUser();

    if (!user) {
      showToast("Please login to add to cart");
      return;
    }

    // If the category has size variants, open the modal to let user choose a size.
    if (!NO_SIZE_CATEGORIES.has(category)) {
      // Re‑fetch the full product from API to ensure we have all details (including available sizes)
      try {
        const res = await fetch(`/styled/php/products.php?id=${productId}`);
        const data = await res.json();
        if (data.success && data.product) {
          const prod = data.product;
          let modalPrice = prod.price;
          if (typeof modalPrice === "number") {
            modalPrice = `₱${modalPrice.toFixed(2)}`;
          } else if (
            typeof modalPrice === "string" &&
            !modalPrice.startsWith("₱")
          ) {
            modalPrice = `₱${parseFloat(modalPrice).toFixed(2)}`;
          }
          const modalProduct = {
            product_id: prod.product_id,
            name: prod.name,
            price: modalPrice,
            img:
              prod.images?.find((img) => img.is_primary)?.image_url ||
              prod.primary_image ||
              "",
            description: prod.description,
          };
          openProductModal(modalProduct, category);
        } else {
          showToast("Could not load product details.");
        }
      } catch (err) {
        console.error("Failed to load product for modal:", err);
        showToast("Error loading product details.");
      }
    } else {
      // Accessories: add directly to cart (no size needed)
      await saveCart({ product_id: productId, size: "", qty: 1 });
      await getCart();
      showToast("Added to cart!");
      btn.innerHTML = `<svg style="width:14px;height:14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Added!`;
      btn.style.background = "#3a6b4a";
      setTimeout(() => {
        btn.innerHTML = `<svg class="cart-btn-icon" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" fill="currentColor"/><line x1="3" y1="6" x2="21" y2="6" stroke="white" stroke-width="1.5"/><path d="M16 10a4 4 0 0 1-8 0" fill="none" stroke="white" stroke-width="1.5"/></svg>Add to Cart`;
        btn.style.background = "";
      }, 1500);
    }
  };

  grid.querySelectorAll(".btn-cart").forEach((btn) => {
    btn.removeEventListener("click", cartButtonHandler);
    btn.addEventListener("click", cartButtonHandler);
  });
}

function wishlistClickHandler(e) {
  e.stopPropagation();
  const btn = e.currentTarget;
  const productData = JSON.parse(btn.dataset.product.replace(/&#39;/g, "'"));
  toggleWishlistItem(productData);
  // Update button active state immediately (optimistic)
  btn.classList.toggle("active", isInWishlist(productData.name));
}

// Price Filter
function togglePriceFilter(e) {
  e.stopPropagation();
  document.getElementById("price-filter").classList.toggle("open");
}

const PRICE_LABELS = {
  all: "Price Range",
  "0-300": "Under ₱300",
  "300-600": "₱300 – ₱600",
  "600-900": "₱600 – ₱900",
  "900-99999": "₱900 & Above",
};

function applyPriceFilter() {
  const selected = document.querySelector('input[name="price-range"]:checked');
  activePriceRange = selected ? selected.value : "all";
  document.getElementById("price-filter").classList.remove("open");
  const btn = document.querySelector(".price-filter-btn");
  if (btn)
    btn.innerHTML =
      PRICE_LABELS[activePriceRange] + ' <span class="pf-arrow">▼</span>';
  renderProducts(currentCategory);
}

function productMatchesPrice(price, range) {
  if (range === "all") return true;
  const [min, max] = range.split("-").map(Number);
  const p = parsePrice(price);
  return p >= min && p <= max;
}

document.querySelectorAll(".category-item").forEach((item) => {
  item.addEventListener("click", () =>
    showCategory(item.dataset.category, item),
  );
});

const priceFilterBtn = document.getElementById("price-filter-btn");
if (priceFilterBtn) priceFilterBtn.addEventListener("click", togglePriceFilter);

const applyPriceFilterBtn = document.getElementById("apply-price-filter");
if (applyPriceFilterBtn)
  applyPriceFilterBtn.addEventListener("click", applyPriceFilter);

document.addEventListener("click", (e) => {
  const pf = document.getElementById("price-filter");
  if (pf && !pf.contains(e.target)) pf.classList.remove("open");
});

// Initialize
renderProducts("tops");
