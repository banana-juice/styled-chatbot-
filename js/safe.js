/* ============================================================
   safe.js — shared output-safety + date helpers.
   Load BEFORE main.js / admin.js / staff.js.

   escapeHtml(v)   Use on EVERY user-controlled value (names, emails,
                   product names, notes, addresses, messages…) before it is
                   placed inside an innerHTML / template-literal string.
                   Escapes & < > " ' and accepts numbers/null/undefined.
                   (Customer and product names were previously injected
                   raw, so a customer named <img onerror=…> ran script in
                   the admin's session.)

   parseServerDate(s)  The server stores/sends Asia/Manila wall-clock
                   times ("YYYY-MM-DD HH:MM:SS", no zone). `new Date(s)`
                   would read that in the *viewer's* zone and Safari can't
                   parse it at all, so interpret it explicitly as +08:00.
   fmtManila(s, opts)  Format a server time in Asia/Manila for display,
                   identically on every page and for every viewer.
   ============================================================ */
(function (global) {
  "use strict";

  function escapeHtml(value) {
    if (value === null || value === undefined) return "";
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function parseServerDate(s) {
    if (!s) return null;
    if (s instanceof Date) return s;
    const str = String(s).trim();
    // Already carries a zone (ISO with Z or ±hh:mm): trust it.
    if (/[zZ]$|[+-]\d{2}:?\d{2}$/.test(str)) {
      const d = new Date(str.replace(" ", "T"));
      return isNaN(d) ? null : d;
    }
    const m = str.match(
      /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/,
    );
    if (!m) {
      const d = new Date(str);
      return isNaN(d) ? null : d;
    }
    const iso = `${m[1]}-${m[2]}-${m[3]}T${m[4] || "00"}:${m[5] || "00"}:${m[6] || "00"}+08:00`;
    const d = new Date(iso);
    return isNaN(d) ? null : d;
  }

  const DATE_ONLY = { month: "short", day: "numeric", year: "numeric" };
  const DATE_TIME = {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  };

  function fmtManila(s, opts) {
    const d = parseServerDate(s);
    if (!d) return "";
    return d.toLocaleString(
      "en-PH",
      Object.assign({ timeZone: "Asia/Manila" }, opts || DATE_TIME),
    );
  }

  global.escapeHtml = escapeHtml;
  global.parseServerDate = parseServerDate;
  global.fmtManila = fmtManila;
  global.MANILA_DATE_ONLY = DATE_ONLY;
  global.MANILA_DATE_TIME = DATE_TIME;
})(window);
