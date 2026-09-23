const API_BASE = "/styled";
// PRODUCTS DATA (must be available for search on all pages)
const CATEGORIES = {
  tops: {
    label: "Category: Tops",
    displayName: "Tops",
    products: [
      {
        name: "Mocha Wrap Tank",
        price: "₱320.00",
        img: "Assets/Images/Tops/Mocha Wrap Tank.png",
        description:
          "An elevated essential designed with a graceful wrap silhouette and smooth, breathable fabric. Thoughtfully tailored to balance comfort and elegance.",
      },
      {
        name: "Coca Knot Tee",
        price: "₱299.00",
        img: "Assets/Images/Tops/Coca Knot Tee.png",
        description:
          "A chic knotted tee crafted from soft, lightweight fabric. Features a flattering front-knot detail that adds effortless dimension to any outfit.",
      },
      {
        name: "Cream Off-Shoulder",
        price: "₱350.00",
        img: "Assets/Images/Tops/Cream Off-Shoulder.png",
        description:
          "A timeless off-shoulder top in a soft cream hue. The relaxed drape and clean neckline make it a versatile wardrobe staple.",
      },
      {
        name: "Wine Halter Blouse",
        price: "₱330.00",
        img: "Assets/Images/Tops/Wine Halter Blouse.png",
        description:
          "A rich wine-toned halter blouse with a refined silhouette. Perfect for transitioning from day to evening with minimal effort.",
      },
      {
        name: "Midnight Peplum Top",
        price: "₱380.00",
        img: "Assets/Images/Tops/Midnight Peplum Top.png",
        description:
          "A structured peplum top in deep midnight that flatters every figure. The subtle flare adds a polished, feminine touch.",
      },
      {
        name: "Black Lace Tank",
        price: "₱310.00",
        img: "Assets/Images/Tops/Black Lace Tank.png",
        description:
          "A delicate lace-trimmed tank that blends understated elegance with everyday wearability. Layer it or wear it alone.",
      },
      {
        name: "Rust Ruffle Top",
        price: "₱340.00",
        img: "Assets/Images/Tops/Rust Ruffle Top.png",
        description:
          "An elevated essential designed with a graceful wrap silhouette and smooth, breathable fabric. Making it a versatile staple in any wardrobe.",
      },
      {
        name: "Ivory Flowy Top",
        price: "₱370.00",
        img: "Assets/Images/Tops/Ivory Flowy Top.png",
        description:
          "A breezy ivory top with a fluid, relaxed fit. Effortlessly elegant for warm days and casual evenings alike.",
      },
      {
        name: "Sheer Chocolate Top",
        price: "₱300.00",
        img: "Assets/Images/Tops/Sheer Chocolate Top.png",
        description:
          "A subtly sheer top in a rich chocolate tone. Light, breathable, and beautifully layerable for a modern minimal look.",
      },
      {
        name: "Textured Puff Sleeve Blouse",
        price: "₱370.00",
        img: "Assets/Images/Tops/Textured Puff Sleeve Blouse.png",
        description:
          "A statement blouse featuring textured fabric and voluminous puff sleeves. Effortlessly elevated for any occasion.",
      },
    ],
  },
  bottoms: {
    label: "Category: Bottoms",
    displayName: "Bottoms",
    products: [
      {
        name: "Midnight Lounge Shorts",
        price: "₱350.00",
        img: "Assets/Images/Bottoms/Midnight Lounge Shorts.png",
        description:
          "Relaxed lounge shorts in a sleek midnight finish. Comfortable enough for lounging, polished enough for errands.",
      },
      {
        name: "Sand Tailored Shorts",
        price: "₱270.00",
        img: "Assets/Images/Bottoms/Sand Tailored Shorts.png",
        description:
          "Crisp tailored shorts in a warm sand tone. A refined take on casual dressing with a clean, structured cut.",
      },
      {
        name: "Striped Wrap Skirt",
        price: "₱490.00",
        img: "Assets/Images/Bottoms/Striped Wrap Skirt.png",
        description:
          "A classic striped wrap skirt with a graceful flow. Timeless patterning meets modern silhouette for effortless style.",
      },
      {
        name: "Bush Floral Skirt",
        price: "₱380.00",
        img: "Assets/Images/Bottoms/Bush Floral Skirt.png",
        description:
          "A romantic floral skirt with a soft, feminine drape. The delicate print adds a botanical charm to your everyday look.",
      },
      {
        name: "Cream Mini Skirt",
        price: "₱330.00",
        img: "Assets/Images/Bottoms/Cream Mini Skirt.png",
        description:
          "A clean, minimal mini skirt in a soft cream finish. Pairs effortlessly with any top for a polished, put-together look.",
      },
      {
        name: "Pastel Bloom Skirt",
        price: "₱370.00",
        img: "Assets/Images/Bottoms/Pastel Bloom Skirt.png",
        description:
          "A dreamy pastel skirt adorned with delicate bloom prints. Light, flowy, and utterly feminine.",
      },
      {
        name: "Sunshine Skirt",
        price: "₱460.00",
        img: "Assets/Images/Bottoms/Sunshine Skirt.png",
        description:
          "A bright and cheerful skirt that brings warmth to any outfit. The fluid cut moves beautifully with every step.",
      },
      {
        name: "Black Maxi Skirt",
        price: "₱390.00",
        img: "Assets/Images/Bottoms/Black Maxi Skirt.png",
        description:
          "A sleek black maxi skirt with timeless appeal. Floor-grazing length and a fluid drape for an effortlessly chic look.",
      },
      {
        name: "Wide-Leg Trousers",
        price: "₱420.00",
        img: "Assets/Images/Bottoms/Wide-Leg Trousers.png",
        description:
          "Relaxed wide-leg trousers with a sophisticated drape. Comfortable and polished for the modern woman on the move.",
      },
      {
        name: "Chocolate Satin Pants",
        price: "₱420.00",
        img: "Assets/Images/Bottoms/Chocolate Satin Pants.png",
        description:
          "Luxurious satin pants in a rich chocolate hue. The smooth sheen adds an elevated touch to any outfit.",
      },
    ],
  },
  dresses: {
    label: "Category: Dresses",
    displayName: "Dresses",
    products: [
      {
        name: "Rosy Gingham Dress",
        price: "₱470.00",
        img: "Assets/Images/Dresses/Rosy Gingham Dress.png",
        description:
          "A charming gingham dress in a soft rosy palette. The classic pattern meets a fresh, feminine silhouette.",
      },
      {
        name: "Olive Halter Dress",
        price: "₱450.00",
        img: "Assets/Images/Dresses/Olive Halter Dress.png",
        description:
          "A sleek halter dress in a muted olive tone. Minimal and modern with a confident, refined edge.",
      },
      {
        name: "Tie-Strap Dress",
        price: "₱650.00",
        img: "Assets/Images/Dresses/Tie-Strap Dress.png",
        description:
          "An elegant tie-strap dress with delicate detail at the shoulders. Effortlessly graceful for any occasion.",
      },
      {
        name: "Mocha Bodycon",
        price: "₱470.00",
        img: "Assets/Images/Dresses/Mocha Bodycon.png",
        description:
          "A figure-hugging bodycon dress in a warm mocha tone. Smooth, sleek, and undeniably chic.",
      },
      {
        name: "Blush Lace Dress",
        price: "₱570.00",
        img: "Assets/Images/Dresses/Blush Lace Dress.png",
        description:
          "A romantic blush dress featuring delicate lace detailing. Feminine, timeless, and utterly stunning.",
      },
      {
        name: "Slate Blue Dress",
        price: "₱440.00",
        img: "Assets/Images/Dresses/Slate Blue Dress.png",
        description:
          "A sophisticated slate blue dress with a clean, minimal silhouette. Understated elegance at its finest.",
      },
      {
        name: "Crimson Dress",
        price: "₱490.00",
        img: "Assets/Images/Dresses/Crimson Dress.png",
        description:
          "A bold crimson dress that commands attention. The rich hue and refined cut make it a true statement piece.",
      },
      {
        name: "Wine Satin Dress",
        price: "₱500.00",
        img: "Assets/Images/Dresses/Wine Satin Dress.png",
        description:
          "A luxurious satin dress in a deep wine shade. The fluid drape and lustrous finish create an effortlessly glamorous look.",
      },
      {
        name: "Night Bodycon",
        price: "₱490.00",
        img: "Assets/Images/Dresses/Night Bodycon.png",
        description:
          "A sleek night bodycon dress that embraces your silhouette. Perfect for an evening out with confidence.",
      },
      {
        name: "Soft Pink Dress",
        price: "₱470.00",
        img: "Assets/Images/Dresses/Soft Pink Dress.png",
        description:
          "A gentle soft pink dress with an airy, feminine feel. Light and graceful for warm days and special moments.",
      },
    ],
  },
  outerwear: {
    label: "Category: Outerwear",
    displayName: "Outerwear",
    products: [
      {
        name: "Two-Tone Bomber",
        price: "₱670.00",
        img: "Assets/Images/Outerwear/Two-Tone Bomber.png",
        description:
          "A contemporary two-tone bomber jacket with a relaxed, urban edge. The contrasting panels add a modern graphic quality.",
      },
      {
        name: "Corduroy Jacket",
        price: "₱690.00",
        img: "Assets/Images/Outerwear/Corduroy Jacket.png",
        description:
          "A textured corduroy jacket with timeless character. Rich in detail and warmth, it layers beautifully over any outfit.",
      },
      {
        name: "Minimalist Shacket",
        price: "₱550.00",
        img: "Assets/Images/Outerwear/Minimalist Shacket.png",
        description:
          "A clean-lined shacket that works as both a shirt and a jacket. Versatile, effortless, and endlessly wearable.",
      },
      {
        name: "Zip Windbreaker",
        price: "₱800.00",
        img: "Assets/Images/Outerwear/Zip Windbreaker.png",
        description:
          "A sleek zip windbreaker built for movement and style. Lightweight protection with a polished, athletic silhouette.",
      },
      {
        name: "Utility Jacket",
        price: "₱570.00",
        img: "Assets/Images/Outerwear/Utility Jacket.png",
        description:
          "A practical yet stylish utility jacket with functional detailing. Effortlessly cool for everyday wear.",
      },
      {
        name: "Shearling Trucker",
        price: "₱800.00",
        img: "Assets/Images/Outerwear/Shearling Trucker.png",
        description:
          "A cozy shearling trucker jacket with plush texture and rugged appeal. Warmth and style in perfect balance.",
      },
      {
        name: "Leather Jacket",
        price: "₱990.00",
        img: "Assets/Images/Outerwear/Leather Jacket.png",
        description:
          "A classic leather jacket that never goes out of style. Sleek, structured, and effortlessly cool for any season.",
      },
      {
        name: "Patterned Bomber",
        price: "₱670.00",
        img: "Assets/Images/Outerwear/Patterned Bomber.png",
        description:
          "A bold patterned bomber that makes a statement. Eye-catching print meets relaxed silhouette for a modern edge.",
      },
      {
        name: "Leather Moto",
        price: "₱890.00",
        img: "Assets/Images/Outerwear/Leather Moto.png",
        description:
          "An iconic leather moto jacket with an edgy, refined attitude. A wardrobe investment that only gets better with time.",
      },
      {
        name: "Oversized Utility Shacket",
        price: "₱670.00",
        img: "Assets/Images/Outerwear/Oversized Utility Shacket.png",
        description:
          "An oversized utility shacket with a relaxed, layered feel. Functional pockets and a roomy fit for effortless style.",
      },
    ],
  },
  accessories: {
    label: "Category: Accessories",
    displayName: "Accessories",
    products: [
      {
        name: "Beaded Ocean Necklace",
        price: "₱160.00",
        img: "Assets/Images/Accessories/Beaded Ocean Necklace.png",
        description:
          "A delicate beaded necklace inspired by the ocean's palette. Light, layerable, and full of natural charm.",
      },
      {
        name: "Pearl Floral Earrings",
        price: "₱170.00",
        img: "Assets/Images/Accessories/Pearl Floral Earrings.png",
        description:
          "Elegant floral earrings adorned with lustrous pearls. A timeless accent that elevates any look with effortless grace.",
      },
      {
        name: "Gold Pearl Drops",
        price: "₱270.00",
        img: "Assets/Images/Accessories/Gold Pearl Drops.png",
        description:
          "Classic gold drop earrings with a single pearl detail. Sophisticated and refined for any occasion.",
      },
      {
        name: "Crochet Lace Bandana",
        price: "₱180.00",
        img: "Assets/Images/Accessories/Crochet Lace Bandana.png",
        description:
          "A charming crochet lace bandana with artisan texture. Style it in your hair, around your neck, or on your bag.",
      },
      {
        name: "Braid Tail Chain",
        price: "₱670.00",
        img: "Assets/Images/Accessories/Braid Tail Chain.png",
        description:
          "A sculptural braided chain with a luxurious, handcrafted quality. A bold, artful statement piece.",
      },
      {
        name: "Gold Arm Cuff",
        price: "₱130.00",
        img: "Assets/Images/Accessories/Gold Arm Cuff.png",
        description:
          "A sleek gold arm cuff with a minimal, architectural design. Effortlessly cool on its own or stacked.",
      },
      {
        name: "Hibiscus Hair Clips",
        price: "₱150.00",
        img: "Assets/Images/Accessories/Hibiscus Hair Clips.png",
        description:
          "Playful hibiscus-shaped hair clips that add a tropical touch to any hairstyle. Feminine and fun.",
      },
      {
        name: "Sun Stacking Rings",
        price: "₱270.00",
        img: "Assets/Images/Accessories/Sun Stacking Rings.png",
        description:
          "A set of delicate sun-motif stacking rings in warm gold. Mix, match, and layer for a personalized look.",
      },
      {
        name: "Starfish Layered Necklace",
        price: "₱160.00",
        img: "Assets/Images/Accessories/Starfish Layered Necklace.png",
        description:
          "A whimsical layered necklace featuring a starfish charm. Coastal-inspired and effortlessly charming.",
      },
      {
        name: "Oval Retro Sunglasses",
        price: "₱370.00",
        img: "Assets/Images/Accessories/Oval Retro Sunglasses.png",
        description:
          "Retro oval sunglasses with a timeless, sophisticated frame. A finishing touch that ties any look together.",
      },
    ],
  },
};

// ============================================
// PRODUCTS API
// ============================================

/**
 * Fetch products from the server.
 * @param {Object} filters - { category, min_price, max_price, search }
 * @returns {Promise<Array>} Array of raw API product objects.
 */
async function fetchProducts(filters = {}) {
  const params = new URLSearchParams();
  if (filters.category) params.set("category", filters.category);
  if (filters.min_price != null) params.set("min_price", filters.min_price);
  if (filters.max_price != null) params.set("max_price", filters.max_price);
  if (filters.search) params.set("search", filters.search);

  const url = `${API_BASE}/php/products.php?${params.toString()}`;
  const response = await fetch(url);
  if (!response.ok) throw new Error(`Server responded with ${response.status}`);
  const json = await response.json();
  if (!Array.isArray(json.products))
    throw new Error("Unexpected API response format");
  return json.products;
}

// ============================================
// SETTINGS (shipping, tax, payment methods)
// ============================================

let _settingsCache = {};

/**
 * Fetch a settings group from the public API.
 * @param {string} group - store, payment, shipping, tax
 * @returns {Promise<Object>}
 */
async function fetchSettingsGroup(group) {
  if (_settingsCache[group]) return _settingsCache[group];
  try {
    const res = await fetch(`${API_BASE}/php/settings.php?group=${group}`);
    const data = await res.json();
    if (data.success) {
      _settingsCache[group] = data.settings;
      return _settingsCache[group];
    }
  } catch (e) {
    console.warn(`Failed to load settings group "${group}":`, e);
  }
  return {};
}

/**
 * Preload all relevant settings (call once on checkout page).
 */
async function loadAllSettings() {
  const [store, payment, shipping, tax] = await Promise.all([
    fetchSettingsGroup("store"),
    fetchSettingsGroup("payment"),
    fetchSettingsGroup("shipping"),
    fetchSettingsGroup("tax"),
  ]);
  return { store, payment, shipping, tax };
}
/**
 * Normalise an API product into the legacy shape used everywhere
 * (cart, wishlist, modal) which expects `img` not `image`.
 */
function normaliseProduct(p) {
  const primaryImg = p.primary_image || p.image || "";
  return {
    product_id: p.product_id,
    name: p.name,
    price: p.price,
    price_num: p.price_num,
    img: primaryImg,
    image: primaryImg,
    description: p.description || "",
    category: p.category,
    category_name: p.category_name,
    sizes: p.sizes || ["XS", "S", "M", "L", "XL"],
    stock: p.stock || {},
  };
}

// Flat cache of normalised products populated after each successful fetch.
let _productCache = [];

function _updateProductCache(products) {
  products.forEach((p) => {
    const idx = _productCache.findIndex((c) => c.product_id === p.product_id);
    if (idx >= 0) _productCache[idx] = p;
    else _productCache.push(p);
  });
}

/**
 * Find a product by name+category from the cache, falling back to static data.
 */
function findCachedProduct(name, categoryKey) {
  const hit = _productCache.find(
    (p) => p.name === name && p.category === categoryKey,
  );
  if (hit) return hit;
  const catData = CATEGORIES[categoryKey];
  if (!catData) return null;
  return catData.products.find((p) => p.name === name) || null;
}

// ============================================
// CART FUNCTIONS  (API-backed)
// ============================================

// In-memory cart cache so sync callers (renderCart etc.) still work.
let _cartCache = [];

/**
 * Returns the in-memory cart cache synchronously.
 * The cache is populated by getCart() and saveCart().
 */
function getCartSync() {
  return _cartCache;
}

/**
 * Fetch the cart from the server.
 * Falls back to the in-memory cache on network / auth errors.
 * @returns {Promise<Array>}
 */
async function getCart() {
  try {
    const res = await fetch(`${API_BASE}/php/cart.php`, {
      credentials: "include",
      cache: "no-store",
    });
    if (res.status === 401) {
      // Not logged in — return empty and let callers show a toast if needed
      _cartCache = [];
      return [];
    }
    if (!res.ok) throw new Error(`Cart API ${res.status}`);
    const data = await res.json();
    _cartCache = Array.isArray(data.items) ? data.items : [];
    updateBadgeCount(_cartCache);
    return _cartCache;
  } catch (e) {
    console.warn("getCart() fell back to cache:", e);
    return _cartCache;
  }
}

/**
 * POST a cart item (or full cart array) to the server.
 *  - If `cart` is an Array  → replaces the server cart by diffing against the
 *    current cache (handles saveCart([])) which clears the cart on order).
 *  - If `cart` is an Object → treated as a single { product_id, size, qty } add/update.
 *
 * Always keeps _cartCache in sync and refreshes the badge.
 * @param {Array|Object} cart
 * @returns {Promise<Array>} updated cart items
 */
async function saveCart(cart) {
  try {
    // ── Clearing the cart (e.g. after placeOrder) ─────────────────────────
    if (Array.isArray(cart) && cart.length === 0) {
      // Delete every item currently in the cache
      await Promise.all(
        _cartCache.map((item) =>
          fetch(`${API_BASE}/php/cart.php`, {
            method: "DELETE",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              product_id: item.product_id,
              size: item.size || "",
            }),
          }),
        ),
      );
      _cartCache = [];
      updateBadgeCount([]);
      return [];
    }

    // ── Saving a full updated cart array (qty change / remove) ────────────
    if (Array.isArray(cart)) {
      // Items that were removed from the array vs. the old cache
      const removed = _cartCache.filter(
        (old) =>
          !cart.find(
            (n) => n.product_id === old.product_id && n.size === old.size,
          ),
      );
      await Promise.all(
        removed.map((item) =>
          fetch(`${API_BASE}/php/cart.php`, {
            method: "DELETE",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              product_id: item.product_id,
              size: item.size || "",
            }),
          }),
        ),
      );

      // Upsert every remaining item (handles qty changes)
      const results = await Promise.all(
        cart.map((item) =>
          fetch(`${API_BASE}/php/cart.php`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              product_id: item.product_id,
              size: item.size || "",
              qty: item.qty || 1,
            }),
          }).then((r) => r.json()),
        ),
      );

      // Use the last successful response to refresh the cache
      const last = results.filter(Boolean).pop();
      _cartCache = last?.items ?? cart;
      updateBadgeCount(_cartCache);
      return _cartCache;
    }

    // ── Single-item add/update object (legacy path) ───────────────────────
    const res = await fetch(`${API_BASE}/php/cart.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(cart),
    });
    if (res.status === 401) {
      showToast("Please login to add to cart");
      return _cartCache;
    }
    if (!res.ok) throw new Error(`Cart API ${res.status}`);
    const data = await res.json();
    _cartCache = data.items ?? _cartCache;
    updateBadgeCount(_cartCache);
    return _cartCache;
  } catch (e) {
    console.warn("saveCart() error:", e);
    return _cartCache;
  }
}

/**
 * Add a single product to the cart.
 * Checks login first and shows a toast if the user is not authenticated.
 * @param {{ product_id, name, price, img, color, size, category, qty }} item
 */
async function addToCart(item) {
  const user = getCurrentUser();
  if (!user) {
    showToast("Please login to add to cart");
    return;
  }
  await saveCart({
    product_id: item.product_id,
    size: item.size || "",
    qty: item.qty || 1,
  });
  // Refresh local badge with a full GET so the cache is accurate
  await getCart();
  showToast("Added to cart!");
}

function updateBadgeCount(items) {
  const total = items.reduce((s, i) => s + (i.qty || 1), 0);
  document.querySelectorAll(".cart-badge").forEach((b) => {
    if (b) b.textContent = total;
  });
}

async function updateBadge() {
  const cart = await getCart();
  updateBadgeCount(cart);
}
function parsePrice(str) {
  return parseFloat(str.replace(/[^0-9.]/g, "")) || 0;
}
function formatPrice(num) {
  return "₱" + num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// ============================================
// WISHLIST FUNCTIONS
// ============================================

// In-memory cache so sync callers (isInWishlist, badge) still work instantly.
let _wishlistCache = null;

function _wishlistFromStorage() {
  try {
    return JSON.parse(localStorage.getItem("styled_wishlist")) || [];
  } catch (e) {
    return [];
  }
}
function _saveWishlistToStorage(list) {
  try {
    localStorage.setItem("styled_wishlist", JSON.stringify(list));
  } catch (e) {}
}

/** Synchronous read — in-memory cache or localStorage fallback. */
function getWishlist() {
  if (_wishlistCache !== null) return _wishlistCache;
  return _wishlistFromStorage();
}

/** Fetch from server, update cache + localStorage. Falls back silently. */
async function fetchWishlist() {
  try {
    const res = await fetch(`${API_BASE}/php/wishlist.php`, {
      credentials: "include",
      cache: "no-store",
    });
    if (res.status === 401) {
      _wishlistCache = _wishlistFromStorage();
      return _wishlistCache;
    }
    if (!res.ok) throw new Error(`Wishlist API ${res.status}`);
    const data = await res.json();
    const items = Array.isArray(data.items) ? data.items : [];
    _wishlistCache = items;
    _saveWishlistToStorage(items);
    return items;
  } catch (e) {
    console.warn("fetchWishlist() fell back to localStorage:", e);
    _wishlistCache = _wishlistFromStorage();
    return _wishlistCache;
  }
}

/** Sync local cache + localStorage immediately. */
async function saveWishlist(list) {
  _wishlistCache = list;
  _saveWishlistToStorage(list);
}

function isInWishlist(name) {
  return getWishlist().some((i) => i.name === name);
}

/** Toggle a product in/out of the wishlist; hits the API in background. */
async function toggleWishlistItem(product) {
  const list = getWishlist();
  const idx = list.findIndex((i) => i.name === product.name);
  const removing = idx >= 0;

  // Optimistic local update
  if (removing) list.splice(idx, 1);
  else list.push(product);
  await saveWishlist(list);
  updateWishlistBadge();

  // Resolve product_id for API if missing
  let productId = product.product_id || 0;
  if (!productId) {
    try {
      const categoryKey = product.category || "";
      const raw = await fetchProducts(
        categoryKey ? { category: categoryKey } : {},
      );
      const norm = raw.map(normaliseProduct);
      _updateProductCache(norm);
      const match = norm.find(
        (p) =>
          p.name.trim().toLowerCase() === product.name.trim().toLowerCase(),
      );
      if (match) productId = match.product_id;
    } catch (_) {}
  }

  if (productId) {
    try {
      await fetch(`${API_BASE}/php/wishlist.php`, {
        method: removing ? "DELETE" : "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ product_id: productId }),
      });
    } catch (e) {
      console.warn("toggleWishlistItem API call failed:", e);
    }
  }

  updateWishlistBadge();
  refreshWishlistPanel();
  syncWishButtons();
}
function updateWishlistBadge() {
  const count = getWishlist().length;
  document.querySelectorAll(".wishlist-badge").forEach((b) => {
    b.textContent = count;
    b.style.display = count > 0 ? "flex" : "none";
  });
}
function syncWishButtons() {
  document.querySelectorAll(".wish-btn[data-product-name]").forEach((btn) => {
    const name = btn.dataset.productName;
    btn.classList.toggle("active", isInWishlist(name));
  });
}

// ============================================
// AUTH HELPERS
// ============================================

/**
 * Returns the cached user object from localStorage.
 * localStorage is written on login and cleared on logout — it is
 * only used for UI state (avatar initials, name in dropdown).
 * The real auth source of truth is the PHP session (check.php).
 */
function getCurrentUser() {
  try {
    return JSON.parse(localStorage.getItem("styled_user"));
  } catch (e) {
    return null;
  }
}

/**
 * Signs the user out:
 *  1. Calls the PHP session-destroy endpoint.
 *  2. Clears the localStorage UI cache.
 *  3. Refreshes the profile dropdown to the guest state.
 */
async function signOut() {
  try {
    await fetch(API_BASE + "/php/auth/logout.php", {
      method: "GET",
      credentials: "include", // send session cookie so PHP can destroy it
    });
  } catch (_) {
    // Even if the network fails, clear local state
  }
  localStorage.removeItem("styled_user");
  updateProfileUI();
  closeProfileDropdown();
}

/**
 * checkAuthStatus()
 * Hits /php/auth/check.php to verify the PHP session is still alive.
 * - Keeps localStorage in sync (in case the session expired server-side).
 * - Updates the profile dropdown UI.
 * - If `redirectIfGuest` is true, sends unauthenticated users to auth.html.
 * - If `adminOnly` is true, non-admins are redirected to index.html.
 *
 * Called from DOMContentLoaded on every page so the navbar always reflects
 * the real session state, even after a browser restart or session timeout.
 */
async function checkAuthStatus({
  redirectIfGuest = false,
  adminOnly = false,
} = {}) {
  try {
    const res = await fetch(API_BASE + "/php/auth/check.php", {
      method: "GET",
      credentials: "include",
      cache: "no-store",
    });
    const data = await res.json();

    if (data.logged_in) {
      const u = data.user;
      // Refresh the localStorage UI cache with the latest server data
      localStorage.setItem(
        "styled_user",
        JSON.stringify({ name: u.full_name, email: u.email, role: u.role }),
      );
      updateProfileUI();

      // Enforce admin-only pages
      if (adminOnly && u.role !== "admin") {
        window.location.href = "index.html";
        return false;
      }
      return true;
    } else {
      // Session is gone — clear stale localStorage
      localStorage.removeItem("styled_user");
      updateProfileUI();

      if (redirectIfGuest) {
        window.location.href = "auth.html";
        return false;
      }
      return false;
    }
  } catch (_) {
    // If the network request fails, fall back to localStorage state
    // so the UI does not flicker on slow connections
    updateProfileUI();
    return false;
  }
}

// ============================================
// PROFILE DROPDOWN
// ============================================
function buildNavIcons() {
  const navIcons = document.querySelector(".nav-icons");
  if (!navIcons) return;

  // Assign IDs to Account and Wishlist buttons
  navIcons.querySelectorAll("button").forEach((btn) => {
    const title = btn.getAttribute("title");
    if (title === "Account" && !btn.id) btn.id = "account-btn";
    if (title === "Wishlist" && !btn.id) btn.id = "wishlist-btn";
  });

  // Wishlist button — add badge + click handler
  const wishBtn = document.getElementById("wishlist-btn");
  if (wishBtn && !wishBtn.querySelector(".wishlist-badge")) {
    const badge = document.createElement("span");
    badge.className = "wishlist-badge";
    wishBtn.style.position = "relative";
    wishBtn.appendChild(badge);
    wishBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      openWishlistPanel();
    });
  }

  // Account button — build dropdown + click handler
  const acctBtn = document.getElementById("account-btn");
  if (acctBtn && !document.getElementById("profile-dropdown")) {
    const wrapper = acctBtn.parentElement;
    wrapper.style.position = "relative";
    const dropdown = document.createElement("div");
    dropdown.id = "profile-dropdown";
    dropdown.className = "profile-dropdown";
    wrapper.appendChild(dropdown);
    acctBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      toggleProfileDropdown();
    });
  }

  updateProfileUI();
  updateWishlistBadge();
}

function toggleProfileDropdown() {
  const dd = document.getElementById("profile-dropdown");
  if (!dd) return;
  const isOpen = dd.classList.contains("open");
  closeProfileDropdown();
  if (!isOpen) {
    dd.classList.add("open");
    document.addEventListener("click", outsideProfileClick);
  }
}

function closeProfileDropdown() {
  const dd = document.getElementById("profile-dropdown");
  if (dd) dd.classList.remove("open");
  document.removeEventListener("click", outsideProfileClick);
}

function outsideProfileClick(e) {
  const dd = document.getElementById("profile-dropdown");
  const btn = document.getElementById("account-btn");
  if (
    dd &&
    !dd.contains(e.target) &&
    e.target !== btn &&
    !btn?.contains(e.target)
  ) {
    closeProfileDropdown();
  }
}

function updateProfileUI() {
  const user = getCurrentUser();
  const dd = document.getElementById("profile-dropdown");
  if (!dd) return;

  if (user) {
    const initials = user.name
      ? user.name
          .split(" ")
          .map((w) => w[0])
          .join("")
          .toUpperCase()
          .slice(0, 2)
      : "?";
    const isAdmin = user.role === "admin";
    const adminBadge = isAdmin
      ? `<span style="
            display:inline-block;
            font-size:11px;
            font-weight:600;
            letter-spacing:.06em;
            background:#2c1f14;
            color:#f5f0ea;
            border-radius:3px;
            padding:1px 6px;
            margin-left:6px;
            vertical-align:middle;
          ">ADMIN</span>`
      : "";
    const adminLink = isAdmin
      ? `<a class="pd-item" href="admin.html">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          Admin Dashboard
        </a>`
      : "";
    dd.innerHTML = `
      <div class="pd-header">
        <div class="pd-avatar">${initials}</div>
        <div>
          <p class="pd-name">Hello, ${user.name?.split(" ")[0] || "there"}${adminBadge}</p>
          <p class="pd-email">${user.email || ""}</p>
        </div>
      </div>
      <div class="pd-divider"></div>
      ${adminLink}
      <a class="pd-item" href="#" id="pd-wishlist-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6z"/></svg>
        My Wishlist
      </a>
      <a class="pd-item" href="orders.html">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
        My Orders
      </a>
      <div class="pd-divider"></div>
      <button class="pd-signout" id="pd-signout-btn">Sign Out</button>
    `;
    document
      .getElementById("pd-wishlist-link")
      ?.addEventListener("click", (e) => {
        e.preventDefault();
        closeProfileDropdown();
        openWishlistPanel();
      });
    document
      .getElementById("pd-signout-btn")
      ?.addEventListener("click", signOut);
  } else {
    dd.innerHTML = `
      <div class="pd-guest">
        <p class="pd-guest-title">Welcome to Styled</p>
        <p class="pd-guest-sub">Sign in to access your wishlist and orders.</p>
        <a class="pd-login-btn" href="auth.html">Log In / Sign Up</a>
      </div>
    `;
  }
}

// ============================================
// WISHLIST PANEL (slide-in drawer)
// ============================================
function buildWishlistPanel() {
  if (document.getElementById("wishlist-panel")) return;
  const panel = document.createElement("div");
  panel.id = "wishlist-panel";
  panel.className = "wishlist-panel";
  panel.innerHTML = `
    <div class="wl-overlay" id="wl-overlay"></div>
    <div class="wl-drawer" id="wl-drawer">
      <div class="wl-header">
        <div>
          <p class="wl-eyebrow">Your Collection</p>
          <h2 class="wl-title">Wishlist</h2>
        </div>
        <button class="wl-close" id="wl-close">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="wl-body" id="wl-body"></div>
    </div>
  `;
  document.body.appendChild(panel);
  document
    .getElementById("wl-overlay")
    .addEventListener("click", closeWishlistPanel);
  document
    .getElementById("wl-close")
    .addEventListener("click", closeWishlistPanel);
}

function openWishlistPanel() {
  buildWishlistPanel();
  refreshWishlistPanel(); // render immediately from cache
  document.getElementById("wishlist-panel")?.classList.add("open");
  document.body.style.overflow = "hidden";
  // Then fetch fresh data from server and re-render
  fetchWishlist().then(() => {
    refreshWishlistPanel();
    updateWishlistBadge();
  });
}

function closeWishlistPanel() {
  document.getElementById("wishlist-panel")?.classList.remove("open");
  document.body.style.overflow = "";
}

function refreshWishlistPanel() {
  const body = document.getElementById("wl-body");
  if (!body) return;
  const list = getWishlist();

  if (list.length === 0) {
    body.innerHTML = `
      <div class="wl-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6z"/></svg>
        <p class="wl-empty-title">Your wishlist is empty</p>
        <p class="wl-empty-sub">Save items you love and come back to them anytime.</p>
        <a href="index.html#categories" class="wl-browse-btn" id="wl-browse-btn">Browse Products</a>
      </div>
    `;
    document
      .getElementById("wl-browse-btn")
      ?.addEventListener("click", closeWishlistPanel);
    return;
  }

  body.innerHTML = `
    <div class="wl-grid">
      ${list
        .map(
          (item, idx) => `
        <div class="wl-item" data-idx="${idx}">
          <div class="wl-img-wrap">
            <img src="${item.img}" alt="${item.name}" onerror="this.style.opacity='0'" />
            <button class="wl-remove" data-name="${item.name.replace(/"/g, "&quot;")}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <p class="wl-item-name">${item.name}</p>
          <p class="wl-item-price">${item.price}</p>
          <button class="wl-add-cart" data-name="${item.name.replace(/"/g, "&quot;")}">Add to Cart</button>
        </div>
      `,
        )
        .join("")}
    </div>
  `;

  // Attach handlers
  body.querySelectorAll(".wl-remove").forEach((btn) => {
    btn.addEventListener("click", () => {
      const name = btn.dataset.name;
      const list = getWishlist();
      const item = list.find((i) => i.name === name);
      if (item) toggleWishlistItem(item);
    });
  });

  body.querySelectorAll(".wl-add-cart").forEach((btn) => {
    btn.addEventListener("click", () => {
      const name = btn.dataset.name;
      const item = getWishlist().find((i) => i.name === name);
      if (item) addWishlistItemToCart(item);
    });
  });
}

async function addWishlistItemToCart(product) {
  const user = getCurrentUser();
  if (!user) {
    showToast("Please login to add to cart");
    return;
  }

  let productId = product.product_id || 0;

  // Wishlist items saved from localStorage may not carry product_id —
  // resolve it from the API by matching on name, same as the product modal does.
  if (!productId) {
    try {
      const categoryKey = product.category || "";
      const raw = await fetchProducts(
        categoryKey ? { category: categoryKey } : {},
      );
      const norm = raw.map(normaliseProduct);
      _updateProductCache(norm);
      const match = norm.find(
        (p) =>
          p.name.trim().toLowerCase() === product.name.trim().toLowerCase(),
      );
      if (match) productId = match.product_id;
    } catch (err) {
      console.error("addWishlistItemToCart: failed to resolve product_id", err);
    }
  }

  if (!productId) {
    showToast("Could not add to cart — product not found.");
    return;
  }

  await saveCart({ product_id: productId, size: product.size || "", qty: 1 });
  await getCart();
  showToast("Added to cart!");
}

function showToast(msg) {
  let toast = document.getElementById("shared-toast");
  if (!toast) {
    toast = document.createElement("div");
    toast.id = "shared-toast";
    toast.className = "shared-toast";
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.classList.add("show");
  clearTimeout(toast._t);
  toast._t = setTimeout(() => toast.classList.remove("show"), 2500);
}

// ============================================
// PRODUCT MODAL
// ============================================
function buildProductModal() {
  if (document.getElementById("product-modal")) return;
  const modal = document.createElement("div");
  modal.id = "product-modal";
  modal.className = "product-modal";
  modal.innerHTML = `
    <div class="pm-overlay" id="pm-overlay"></div>
    <div class="pm-box" id="pm-box">
      <button class="pm-close" id="pm-close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
      <div class="pm-left">
        <img class="pm-img" id="pm-img" src="" alt="" />
      </div>
      <div class="pm-right">
        <p class="pm-category" id="pm-category"></p>
        <h2 class="pm-name" id="pm-name"></h2>
        <div class="pm-stars">★★★★★ <span class="pm-reviews">(99 Reviews)</span></div>
        <p class="pm-price" id="pm-price"></p>
        <p class="pm-desc" id="pm-desc"></p>
        <div class="pm-section-label">SIZE</div>
        <div class="pm-sizes" id="pm-sizes">
          <button class="pm-size" data-size="S">S</button>
          <button class="pm-size active" data-size="M">M</button>
          <button class="pm-size" data-size="L">L</button>
          <button class="pm-size" data-size="XL">XL</button>
          <button class="pm-size" data-size="XXL">XXL</button>
        </div>
        <div class="pm-section-label">QUANTITY</div>
        <div class="pm-qty-wrap">
          <button class="pm-qty-btn" id="pm-qty-minus">−</button>
          <span class="pm-qty-num" id="pm-qty-num">1</span>
          <button class="pm-qty-btn" id="pm-qty-plus">+</button>
        </div>
        <button class="pm-add-cart" id="pm-add-cart">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          Add to Cart
        </button>
        <button class="pm-wishlist-btn" id="pm-wishlist-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6z"/></svg>
          Add to Wishlist
        </button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  document
    .getElementById("pm-overlay")
    .addEventListener("click", closeProductModal);
  document
    .getElementById("pm-close")
    .addEventListener("click", closeProductModal);
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeProductModal();
  });

  let qty = 1;
  document.getElementById("pm-qty-minus").addEventListener("click", () => {
    qty = Math.max(1, qty - 1);
    document.getElementById("pm-qty-num").textContent = qty;
  });
  document.getElementById("pm-qty-plus").addEventListener("click", () => {
    qty = Math.min(10, qty + 1);
    document.getElementById("pm-qty-num").textContent = qty;
  });

  modal.querySelectorAll(".pm-size").forEach((btn) => {
    btn.addEventListener("click", () => {
      modal
        .querySelectorAll(".pm-size")
        .forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
    });
  });

  document.getElementById("pm-add-cart").addEventListener("click", async () => {
    const user = getCurrentUser();
    if (!user) {
      showToast("Please login to add to cart");
      return;
    }

    const modalEl = document.getElementById("product-modal");
    const hasSize = modalEl.dataset.hasSize !== "0";
    const size = hasSize
      ? modal.querySelector(".pm-size.active")?.dataset.size || "M"
      : "";
    const qtyNum =
      parseInt(document.getElementById("pm-qty-num").textContent) || 1;

    // Read the product stored on the modal when it was opened
    let currentProduct = {};
    try {
      currentProduct = JSON.parse(modalEl.dataset.product || "{}");
    } catch (_) {}

    let productId = currentProduct.product_id || 0;
    console.log(
      "[cart] product from modal:",
      currentProduct.name,
      "| product_id:",
      productId,
    );

    // If product_id is missing (static fallback path), fetch it from the API by name
    if (!productId) {
      const productName =
        currentProduct.name || document.getElementById("pm-name").textContent;
      const categoryKey = modalEl.dataset.categoryKey || "";
      try {
        const raw = await fetchProducts(
          categoryKey ? { category: categoryKey } : {},
        );
        const norm = raw.map(normaliseProduct);
        _updateProductCache(norm);
        const match = norm.find(
          (p) =>
            p.name.trim().toLowerCase() === productName.trim().toLowerCase(),
        );
        if (match) {
          productId = match.product_id;
          // Update the stored product so future clicks are instant
          modalEl.dataset.product = JSON.stringify({
            ...currentProduct,
            ...match,
          });
        }
      } catch (fetchErr) {
        console.error("Failed to fetch product_id:", fetchErr);
      }
    }

    if (!productId) {
      showToast("Could not add to cart — product not found in database.");
      return;
    }

    await saveCart({ product_id: productId, size, qty: qtyNum });
    await getCart();

    const addBtn = document.getElementById("pm-add-cart");
    addBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Added!`;
    addBtn.style.background = "#3a6b4a";
    setTimeout(() => {
      addBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg> Add to Cart`;
      addBtn.style.background = "";
    }, 1500);
  });
}

// Categories that have no clothing size (one-size-fits-all / accessories)
const NO_SIZE_CATEGORIES = new Set(["accessories"]);

function openProductModal(product, categoryKey) {
  buildProductModal();
  const catData = CATEGORIES[categoryKey] || {};

  const hasSize = !NO_SIZE_CATEGORIES.has(categoryKey);

  document.getElementById("pm-img").src = product.img;
  document.getElementById("pm-img").alt = product.name;
  document.getElementById("pm-name").textContent = product.name;
  document.getElementById("pm-price").textContent = product.price;
  document.getElementById("pm-desc").textContent =
    product.description ||
    "A beautifully crafted piece designed for the modern wardrobe.";
  document.getElementById("pm-category").textContent =
    catData.displayName || "";

  // Show or hide the SIZE label + buttons depending on category
  const sizesEl = document.getElementById("pm-sizes");
  const sizeLabelEl = sizesEl?.previousElementSibling; // the "SIZE" pm-section-label
  if (sizesEl) sizesEl.style.display = hasSize ? "flex" : "none";
  if (sizeLabelEl && sizeLabelEl.classList.contains("pm-section-label")) {
    sizeLabelEl.style.display = hasSize ? "" : "none";
  }

  const wlBtn = document.getElementById("pm-wishlist-btn");
  const refreshWlBtn = () => {
    const inWL = isInWishlist(product.name);
    wlBtn.classList.toggle("active", inWL);
    wlBtn.innerHTML = inWL
      ? `<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6z"/></svg> Saved to Wishlist`
      : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6z"/></svg> Add to Wishlist`;
  };
  refreshWlBtn();
  wlBtn.onclick = () => {
    toggleWishlistItem(product);
    refreshWlBtn();
  };

  document.getElementById("pm-qty-num").textContent = "1";
  document
    .querySelectorAll(".pm-size")
    .forEach((b) => b.classList.remove("active"));
  if (hasSize) {
    document.querySelector(".pm-size[data-size='M']")?.classList.add("active");
  }

  // Store context so pm-add-cart can resolve product_id in all cases
  const _modalEl = document.getElementById("product-modal");
  _modalEl.dataset.hasSize = hasSize ? "1" : "0";
  _modalEl.dataset.categoryKey = categoryKey || "";
  _modalEl.dataset.product = JSON.stringify(product);

  document.getElementById("product-modal").classList.add("open");
  document.body.style.overflow = "hidden";
}

function closeProductModal() {
  document.getElementById("product-modal")?.classList.remove("open");
  document.body.style.overflow = "";
}

// ============================================
// SHOP NAV LINK — scrolls to #categories
// ============================================
function initShopNavLink() {
  document.querySelectorAll(".nav-links a").forEach((link) => {
    if (link.textContent.trim() === "Shop") {
      link.href = "#";
      link.addEventListener("click", (e) => {
        e.preventDefault();
        const isIndex =
          window.location.pathname.includes("index.html") ||
          window.location.pathname === "/" ||
          window.location.pathname.endsWith("/") ||
          window.location.pathname === "";
        if (isIndex) {
          const target = document.getElementById("categories");
          if (target)
            target.scrollIntoView({ behavior: "smooth", block: "start" });
        } else {
          window.location.href = "index.html#categories";
        }
      });
    }
  });
}

// ============================================
// HELP LINK → contact page
// ============================================
function initHelpLink() {
  document.querySelectorAll(".ann-right a").forEach((link) => {
    if (link.textContent.trim().toLowerCase() === "help") {
      link.href = "contact.html";
    }
  });
}

// ============================================
// SEARCH FUNCTIONALITY
// ============================================
function buildSearchIndex() {
  const index = [];
  for (const [key, category] of Object.entries(CATEGORIES)) {
    index.push({
      type: "category",
      name: category.displayName,
      categoryKey: key,
      searchText: category.displayName.toLowerCase(),
    });
  }
  // Prefer API cache for any category that has been loaded; fall back to static.
  const categoriesInCache = new Set(_productCache.map((p) => p.category));
  for (const [catKey, category] of Object.entries(CATEGORIES)) {
    const source = categoriesInCache.has(catKey)
      ? _productCache.filter((p) => p.category === catKey)
      : category.products;
    for (const product of source) {
      index.push({
        type: "product",
        name: product.name,
        category: category.displayName,
        categoryKey: catKey,
        productData: product,
        searchText: product.name.toLowerCase(),
      });
    }
  }
  return index;
}
// Initialised with static data; rebuilt after each API fetch.
let searchIndex = buildSearchIndex();

function highlightText(text, query) {
  if (!query) return text;
  const regex = new RegExp(
    `(${query.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")})`,
    "gi",
  );
  return text.replace(regex, '<mark class="search-result-highlight">$1</mark>');
}

function performSearch(query, resultsContainer) {
  if (!query.trim()) {
    resultsContainer.innerHTML = "";
    return;
  }
  const lowerQuery = query.toLowerCase();
  const matches = searchIndex.filter((item) =>
    item.searchText.includes(lowerQuery),
  );
  if (matches.length === 0) {
    resultsContainer.innerHTML =
      '<div class="search-result-empty">No products found</div>';
    return;
  }
  resultsContainer.innerHTML = matches
    .map((match) => {
      if (match.type === "category") {
        return `<div class="search-result-item category-result" data-type="category" data-category="${match.categoryKey}"><div class="search-result-name">${highlightText(match.name, query)}</div><div class="search-result-category">Category</div></div>`;
      } else {
        return `<div class="search-result-item" data-type="product" data-category="${match.categoryKey}" data-product-name="${match.name.replace(/['"]/g, "&quot;")}"><div class="search-result-name">${highlightText(match.name, query)}</div><div class="search-result-category">in <span>${match.category}</span></div></div>`;
      }
    })
    .join("");

  resultsContainer.querySelectorAll(".search-result-item").forEach((item) => {
    item.addEventListener("click", (e) => {
      e.stopPropagation();
      const category = item.dataset.category;
      closeAllSearchDropdowns();
      const isIndex =
        window.location.pathname.includes("index.html") ||
        window.location.pathname === "/" ||
        window.location.pathname.endsWith("/") ||
        window.location.pathname === "";
      if (isIndex) {
        const categoryItem = document.querySelector(
          `.category-item[data-category="${category}"]`,
        );
        if (categoryItem) categoryItem.click();
      } else {
        window.location.href = `index.html#category-${category}`;
      }
    });
  });
}

function closeAllSearchDropdowns() {
  document.querySelectorAll(".search-dropdown").forEach((dropdown) => {
    dropdown.classList.remove("open");
    const input = dropdown.querySelector("#search-input");
    if (input) input.value = "";
    const results = dropdown.querySelector(".search-results");
    if (results) results.innerHTML = "";
  });
}

function initSearch(searchBtn, searchDropdown, searchInput, searchResults) {
  if (!searchBtn || !searchDropdown || !searchInput || !searchResults) return;
  let debounceTimer;
  searchInput.addEventListener("input", (e) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(
      () => performSearch(e.target.value, searchResults),
      300,
    );
  });
  searchBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    const isOpen = searchDropdown.classList.contains("open");
    if (isOpen) {
      searchDropdown.classList.remove("open");
      searchInput.value = "";
      searchResults.innerHTML = "";
    } else {
      closeAllSearchDropdowns();
      searchDropdown.classList.add("open");
      searchInput.focus();
    }
  });
  document.addEventListener("click", (e) => {
    if (
      !searchDropdown.contains(e.target) &&
      e.target !== searchBtn &&
      !searchBtn.contains(e.target)
    ) {
      searchDropdown.classList.remove("open");
      searchInput.value = "";
      searchResults.innerHTML = "";
    }
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && searchDropdown.classList.contains("open")) {
      searchDropdown.classList.remove("open");
      searchInput.value = "";
      searchResults.innerHTML = "";
    }
  });
}

function initAllSearches() {
  document.querySelectorAll(".search-wrapper").forEach((wrapper) => {
    const searchBtn = wrapper.querySelector("button");
    const searchDropdown = wrapper.querySelector(".search-dropdown");
    const searchInput = wrapper.querySelector("input");
    const searchResults = wrapper.querySelector(".search-results");
    if (searchBtn && searchDropdown)
      initSearch(searchBtn, searchDropdown, searchInput, searchResults);
  });
}

// ============================================
// BACK TO TOP
// ============================================
const backToTop = document.getElementById("back-to-top");
if (backToTop) {
  window.addEventListener("scroll", () => {
    if (window.scrollY > 400) backToTop.classList.add("visible");
    else backToTop.classList.remove("visible");
  });
  backToTop.addEventListener("click", () =>
    window.scrollTo({ top: 0, behavior: "smooth" }),
  );
}

const cartBtn = document.getElementById("cart-btn");
if (cartBtn) {
  cartBtn.addEventListener(
    "click",
    () => (window.location.href = "checkout.html"),
  );
}

if (
  window.location.pathname.includes("index.html") ||
  window.location.pathname === "/" ||
  window.location.pathname.endsWith("/") ||
  window.location.pathname === ""
) {
  const hash = window.location.hash;
  if (hash && hash.startsWith("#category-")) {
    const category = hash.replace("#category-", "");
    document.addEventListener("DOMContentLoaded", () => {
      setTimeout(() => {
        const categoryItem = document.querySelector(
          `.category-item[data-category="${category}"]`,
        );
        if (categoryItem) categoryItem.click();
      }, 300);
    });
  }
}

// ============================================
// INIT
// ============================================
document.addEventListener("DOMContentLoaded", () => {
  initAllSearches();
  updateBadge();
  buildNavIcons();
  initShopNavLink();
  initHelpLink();
  updateWishlistBadge();

  // Verify PHP session is alive and sync the profile dropdown.
  // Pass no options here — individual pages (orders, admin) handle their own
  // redirect logic via their own inline auth-guard scripts.
  checkAuthStatus();

  // Seed the wishlist cache from the server so the badge and panel are accurate.
  fetchWishlist().then(() => {
    updateWishlistBadge();
    syncWishButtons();
  });
});
