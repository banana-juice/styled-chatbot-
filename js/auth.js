
const API_BASE = "/styled";

let active = "signup";

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab) {
  if (tab === active) return;

  document.getElementById("tab-" + active).classList.remove("active");
  document.getElementById("tab-" + tab).classList.add("active");

  const outF = document.getElementById("form-" + active);
  const inF = document.getElementById("form-" + tab);
  outF.classList.add("slide-out");
  outF.classList.remove("active");
  setTimeout(() => {
    outF.classList.remove("slide-out");
    inF.classList.add("active");
  }, 340);

  document.getElementById("img-" + active).classList.remove("active");
  document.getElementById("img-" + tab).classList.add("active");

  active = tab;
  resetAll();
}

// ── Validation helpers ────────────────────────────────────────────────────────
const FM = {
  name: { w: "fw-name", e: "er-name" },
  "su-email": { w: "fw-su-email", e: "er-su-email" },
  "su-pw": { w: "fw-su-pw", e: "er-su-pw" },
  "su-cpw": { w: "fw-su-cpw", e: "er-su-cpw" },
  "li-email": { w: "fw-li-email", e: "er-li-email" },
  "li-pw": { w: "fw-li-pw", e: "er-li-pw" },
};

function setErr(key, msg) {
  const m = FM[key];
  if (!m) return;
  document.getElementById(m.e).textContent = msg;
  document.getElementById(m.w).classList.add("is-error");
}

function clr(key) {
  const m = FM[key];
  if (!m) return;
  document.getElementById(m.e).textContent = "";
  document.getElementById(m.w).classList.remove("is-error");
}

function resetAll() {
  Object.keys(FM).forEach(clr);
  ["s1", "s2", "s3", "s4"].forEach(
    (id) => (document.getElementById(id).style.background = ""),
  );
  document.getElementById("s-lbl").textContent = "";
  ["req-len", "req-upper", "req-num", "req-sym"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.classList.remove("met");
  });
}

function validEmail(v) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
}

// ── Password strength ─────────────────────────────────────────────────────────
function strength(pw) {
  let s = 0;
  if (pw.length >= 8) s++;
  if (/[A-Z]/.test(pw)) s++;
  if (/[0-9]/.test(pw)) s++;
  if (/[^A-Za-z0-9]/.test(pw)) s++;
  return s;
}

function updateStrength() {
  const pw = document.getElementById("su-pw").value;
  const s = pw ? strength(pw) : 0;
  const colors = ["", "#c0392b", "#e67e22", "#d4ac0d", "#3a6b4a"];
  const labels = ["", "Weak", "Fair", "Good", "Strong"];
  const c = colors[s] || "var(--border)";
  for (let i = 1; i <= 4; i++) {
    document.getElementById("s" + i).style.background =
      i <= s ? c : "var(--border)";
  }
  document.getElementById("s-lbl").textContent = pw ? labels[s] : "";
}

function updateReqs() {
  const pw = document.getElementById("su-pw").value;
  setReq("req-len", pw.length >= 8);
  setReq("req-upper", /[A-Z]/.test(pw));
  setReq("req-num", /[0-9]/.test(pw));
  setReq("req-sym", /[^A-Za-z0-9]/.test(pw));
}

function setReq(id, met) {
  const el = document.getElementById(id);
  if (el) el.classList.toggle("met", met);
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function showModal() {
  document.getElementById("success-modal").classList.add("show");
}

function closeModal() {
  document.getElementById("success-modal").classList.remove("show");
}

function goToLogin() {
  closeModal();
  setTimeout(() => switchTab("login"), 200);
}

// ── Password reveal ───────────────────────────────────────────────────────────
function revealPw(inputId, btn) {
  const inp = document.getElementById(inputId);
  const isPassword = inp.type === "password";
  inp.type = isPassword ? "text" : "password";
  const svg = btn.querySelector("svg");
  if (isPassword) {
    svg.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;
  } else {
    svg.innerHTML = `<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>`;
  }
}

// ── Loading button helper ─────────────────────────────────────────────────────
function setLoading(btnId, loading) {
  const btn = document.getElementById(btnId);
  if (!btn) return;
  if (loading) {
    btn.classList.add("loading");
    btn.disabled = true;
  } else {
    btn.classList.remove("loading");
    btn.disabled = false;
  }
}

// ── Toast ─────────────────────────────────────────────────────────────────────
let toastT;
function toast(msg, type = "") {
  const el = document.getElementById("toast");
  el.textContent = msg;
  el.className = "toast " + type;
  el.classList.add("show");
  clearTimeout(toastT);
  toastT = setTimeout(() => el.classList.remove("show"), 3200);
}

// ── API helper ────────────────────────────────────────────────────────────────
async function apiPost(url, body) {
  const res = await fetch(url, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = await res.json();
  return { ok: res.ok, status: res.status, data };
}

// ── On page load: check for ?verified=1 flag ──────────────────────────────────
(function checkVerifiedFlag() {
  const params = new URLSearchParams(location.search);
  if (params.get("verified") === "1") {
    // Clean URL then show login tab with a success toast
    history.replaceState({}, "", location.pathname);
    // Give DOM time to mount
    setTimeout(() => {
      switchTab("login");
      toast("Email verified! You can now log in.", "ok");
    }, 100);
  }
})();

// ── SIGNUP HANDLER ────────────────────────────────────────────────────────────
async function handleSignup(e) {
  e.preventDefault();

  const name = document.getElementById("su-name").value.trim();
  const email = document.getElementById("su-email").value.trim();
  const pw = document.getElementById("su-pw").value;
  const cpw = document.getElementById("su-cpw").value;
  let ok = true;

  if (!name) {
    setErr("name", "Please enter your full name.");
    ok = false;
  } else if (name.length < 2) {
    setErr("name", "Name must be at least 2 characters.");
    ok = false;
  }

  if (!email) {
    setErr("su-email", "Please enter your email address.");
    ok = false;
  } else if (!validEmail(email)) {
    setErr("su-email", "Please enter a valid email address.");
    ok = false;
  }

  if (!pw) {
    setErr("su-pw", "Please create a password.");
    ok = false;
  } else if (pw.length < 8) {
    setErr("su-pw", "Password must be at least 8 characters.");
    ok = false;
  } else if (strength(pw) < 2) {
    setErr("su-pw", "Too weak — add uppercase letters, numbers or symbols.");
    ok = false;
  }

  if (!cpw) {
    setErr("su-cpw", "Please confirm your password.");
    ok = false;
  } else if (pw && cpw !== pw) {
    setErr("su-cpw", "Passwords do not match.");
    ok = false;
  }

  if (!ok) return;

  setLoading("btn-signup", true);

  try {
    const { ok: success, data } = await apiPost(
      API_BASE + "/php/auth/register.php",
      { full_name: name, email, password: pw },
    );

    if (success && data.success) {
      if (data.email_sent === false) {
        toast(
          "Account created, but we couldn't send your verification email. Use Resend on the next page, or contact support if it keeps failing.",
          "error",
        );
        setTimeout(() => {
          window.location.href =
            "verify-email.html?email=" + encodeURIComponent(email);
        }, 2500);
        return;
      }
      window.location.href =
        "verify-email.html?email=" + encodeURIComponent(email);
    } else {
      const msg = data.error || "Registration failed. Please try again.";
      if (msg.toLowerCase().includes("email")) {
        setErr("su-email", msg);
      } else if (msg.toLowerCase().includes("name")) {
        setErr("name", msg);
      } else if (msg.toLowerCase().includes("password")) {
        setErr("su-pw", msg);
      } else {
        toast(msg, "error");
      }
    }
  } catch (err) {
    toast(
      "Network error. Please check your connection and try again.",
      "error",
    );
  } finally {
    setLoading("btn-signup", false);
  }
}

// ── LOGIN HANDLER ─────────────────────────────────────────────────────────────
async function handleLogin(e) {
  e.preventDefault();

  const email = document.getElementById("li-email").value.trim();
  const pw = document.getElementById("li-pw").value;
  let ok = true;

  if (!email) {
    setErr("li-email", "Please enter your email address.");
    ok = false;
  } else if (!validEmail(email)) {
    setErr("li-email", "Please enter a valid email address.");
    ok = false;
  }

  if (!pw) {
    setErr("li-pw", "Please enter your password.");
    ok = false;
  } else if (pw.length < 6) {
    setErr("li-pw", "Password must be at least 6 characters.");
    ok = false;
  }

  if (!ok) return;

  setLoading("btn-login", true);

  try {
   const remember = document.getElementById("remember").checked;

const { ok: success, status, data } = await apiPost(API_BASE + "/php/auth/login.php", {
  email,
  password: pw,
  remember: remember       
});

    if (success && data.success) {
      const user = data.user;

      localStorage.setItem(
        "styled_user",
        JSON.stringify({
          name: user.full_name,
          email: user.email,
          role: user.role,
        }),
      );

      toast("Welcome back! Redirecting…", "ok");

      setTimeout(() => {
        if (user.role === "admin") {
          window.location.href = "admin.html";
        } else if (user.role === "staff") {
          window.location.href = "staff.html";
        } else {
          window.location.href = "index.html";
        }
      }, 1200);
    } else {
      if (data.unverified) {
        toast("Check your email for a verification code.", "");
        setTimeout(() => {
          window.location.href =
            "verify-email.html?email=" +
            encodeURIComponent(data.email || email);
        }, 1400);
        return;
      }

      const msg = data.error || "Invalid credentials.";
      setErr("li-email", " ");
      setErr("li-pw", msg);
    }
  } catch (err) {
    toast(
      "Network error. Please check your connection and try again.",
      "error",
    );
  } finally {
    setLoading("btn-login", false);
  }
}

// ── Event listeners ───────────────────────────────────────────────────────────
document
  .getElementById("tab-signup")
  ?.addEventListener("click", () => switchTab("signup"));
document
  .getElementById("tab-login")
  ?.addEventListener("click", () => switchTab("login"));

document.querySelectorAll(".switch-btn").forEach((btn) => {
  btn.addEventListener("click", () => switchTab(btn.dataset.switch));
});

document.querySelectorAll(".toggle-pw").forEach((btn) => {
  btn.addEventListener("click", () => revealPw(btn.dataset.target, btn));
});

document
  .getElementById("form-signup")
  ?.addEventListener("submit", handleSignup);
document.getElementById("form-login")?.addEventListener("submit", handleLogin);

document.getElementById("close-modal")?.addEventListener("click", closeModal);
document
  .getElementById("modal-login-btn")
  ?.addEventListener("click", goToLogin);

// ── Forgot Password link → redirect to forgot-password.html ──────────────────
document.querySelector(".forgot-link")?.addEventListener("click", (e) => {
  e.preventDefault();
  window.location.href = "forgot-password.html";
});

// Password strength meter
const suPw = document.getElementById("su-pw");
if (suPw) {
  suPw.addEventListener("input", () => {
    updateStrength();
    updateReqs();
    clr("su-pw");
  });
}

// Clear field errors on input
["su-name", "su-email", "su-cpw", "li-email", "li-pw"].forEach((id) => {
  const el = document.getElementById(id);
  if (el) el.addEventListener("input", () => clr(id));
});

/* ══════════════════════════════════════════════════════════════
   NEW — SOCIAL LOGIN / OAUTH (Google)
   Appended for the Google OAuth integration. Nothing above this
   line was modified, so the existing email/password signup and
   login handlers are untouched.

   This file contains NO secrets. It only:
     1. sends the browser to /php/auth/google-start.php
     2. reads ?oauth=success / ?oauth_error=... on the way back
     3. confirms the PHP session via the existing check.php and
        mirrors it into localStorage, exactly like handleLogin()
   ══════════════════════════════════════════════════════════════ */

// Friendly messages. The backend only ever sends a short machine code,
// never a stack trace or provider detail.
const OAUTH_ERRORS = {
  cancelled: "Google sign-in was cancelled.",
  config: "Google sign-in isn't configured yet. Please use email and password.",
  state: "Your sign-in session expired for security reasons. Please try again.",
  expired: "Your sign-in session expired. Please try again.",
  invalid_response: "We couldn't complete Google sign-in. Please try again.",
  token: "We couldn't verify your Google account. Please try again.",
  provider: "Google couldn't complete the sign-in. Please try again.",
  network: "We couldn't reach Google. Check your connection and try again.",
  incomplete:
    "Google didn't share an email address with us. Try another account or sign up with email.",
  unverified_google:
    "That Google account's email isn't verified. Please verify it with Google first.",
  already_linked:
    "This email is already linked to a different Google account. Please log in with email and password.",
  server: "Something went wrong on our end. Please try again shortly.",
};

// ── Start the OAuth flow ─────────────────────────────────────
function startOAuth(provider, btn) {
  if (provider !== "google") return;
  if (btn) {
    btn.classList.add("loading");
    btn.disabled = true;
  }
  // Full-page redirect: the consent screen must run on Google's own
  // origin. accounts.google.com refuses to be framed or fetched.
  window.location.href = API_BASE + "/php/auth/google-start.php";
}

document.querySelectorAll("[data-oauth]").forEach((btn) => {
  btn.addEventListener("click", () => startOAuth(btn.dataset.oauth, btn));
});

// ── Handle the return trip from google-callback.php ──────────
(function handleOAuthReturn() {
  const params = new URLSearchParams(location.search);

  // (a) Something went wrong / user cancelled
  const errCode = params.get("oauth_error");
  if (errCode) {
    history.replaceState({}, "", location.pathname);
    setTimeout(() => {
      switchTab("login");
      toast(
        OAUTH_ERRORS[errCode] || "Google sign-in failed. Please try again.",
        "error",
      );
    }, 120);
    return;
  }

  // (b) Success — the PHP session is already set by the callback.
  if (params.get("oauth") !== "success") return;

  const mode = params.get("mode"); // created | linked_to_existing | existing_link
  history.replaceState({}, "", location.pathname);

  (async () => {
    try {
      // Confirm with the SAME endpoint the rest of the site uses.
      const res = await fetch(API_BASE + "/php/auth/check.php", {
        method: "GET",
        credentials: "include",
      });
      const data = await res.json();

      if (!data.logged_in || !data.user) {
        toast("Your session didn't stick. Please try signing in again.", "error");
        return;
      }

      const user = data.user;

      // Mirror into localStorage in the exact shape main.js expects.
      localStorage.setItem(
        "styled_user",
        JSON.stringify({
          name: user.full_name,
          email: user.email,
          role: user.role,
        }),
      );

      if (mode === "created") {
        toast("Account created with Google. Welcome to Styled!", "ok");
      } else if (mode === "linked_to_existing") {
        toast("Google linked to your Styled account. Redirecting…", "ok");
      } else {
        toast("Welcome back! Redirecting…", "ok");
      }

      // Same role routing as handleLogin().
      setTimeout(() => {
        if (user.role === "admin") {
          window.location.href = "admin.html";
        } else if (user.role === "staff") {
          window.location.href = "staff.html";
        } else {
          window.location.href = "index.html";
        }
      }, 1300);
    } catch (err) {
      toast("Network error finishing sign-in. Please try again.", "error");
    }
  })();
})();
