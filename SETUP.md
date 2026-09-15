# Setting Up Styled on a New Machine (e.g. Your Laptop)

This walks through getting the project running on a second computer. Two
things never travel through Git and need to be handled by hand every time
you set up a new machine: the **`.env` file** (API key) and the **database**.

---

## 1. Install prerequisites

- **XAMPP** (PHP 8.2+, with the `curl` extension — it's on by default) —
  https://www.apachefriends.org/
- **Git** — https://git-scm.com/downloads

## 2. Clone the repo

Install XAMPP to the default location so the project lands at
`C:\xampp\htdocs`, then:

```bash
cd C:\xampp\htdocs
git clone https://github.com/adrieyell/styled.git
```

You should now have `C:\xampp\htdocs\styled\` with all the site files. The
`vendor/` folder (PHPMailer) is committed to the repo, so **no Composer step
is needed** — it comes with the clone.

## 3. Set up the database — ⚠️ not saved anywhere yet

There is currently no `.sql` export of `styled_db` checked into this repo or
saved elsewhere that we know of — so this step can't be fully scripted yet.
For now, on the new machine you'll need to either:

- get a database export (`mysqldump` or a phpMyAdmin "Export") from
  whoever/wherever the working copy of `styled_db` lives, and import it via
  phpMyAdmin (`http://localhost/phpmyadmin`) or
  `mysql -u root styled_db < dump.sql`, or
- recreate the schema by hand from what `php/*.php` expects (`users`,
  `orders`, `order_items`, `products`, `product_images`, `addresses`,
  `order_timeline`, plus whatever `cart.php`/`wishlist.php`/`promotions.php`
  use) — slower and error-prone, only do this if no export exists anywhere.

Once you have a copy of `styled_db` on the new machine, the app's DB config
in [php/db.php](php/db.php) needs no changes (`localhost` / `root` / no
password is XAMPP's default and should just work).

**Worth doing soon, regardless of the laptop:** export the current working
`styled_db` to a `.sql` file the next time you're at a machine that has it,
and keep that file somewhere the whole team can grab it from (shared drive,
private repo, etc. — just not a public place, since it will contain real
password hashes and any test customer data). That turns this whole step
into one `mysql ... < dump.sql` command from then on.

## 4. Copy `.env` — never through Git

`.env` holds `ANTHROPIC_API_KEY` and is intentionally excluded from Git (see
`.gitignore`). Pick one:

- **New key for the laptop (recommended):** create a second API key in the
  Anthropic Console, scoped/limited independently from the PC's key. Easier
  to revoke later if only one device is ever compromised.
- **Reuse the same key:** copy the `.env` file itself from the PC to the
  laptop over USB drive, direct file transfer, or a password manager's
  secure note/attachment — **not** email, chat, or a plain cloud-synced
  folder.

Either way, the file goes at `C:\xampp\htdocs\styled\.env` on the laptop,
same format as on the PC:

```
ANTHROPIC_API_KEY=sk-ant-...
```

`php/config/env.php` loads it automatically — no restart or extra config
needed. [.htaccess](.htaccess) blocks direct web access to `.env` (and to
`.git/`) the same way it does on the PC — verify that came through with the
clone (dotfiles sometimes get hidden by an OS file browser, but `git status`
will show it as tracked once you commit it — see the note at the bottom).

## 5. Start the servers

Open the XAMPP Control Panel and start **Apache** and **MySQL**.

## 6. Load the site

Visit `http://localhost/styled/index.html`. Confirm:

- The homepage and products load (checks Apache + PHP are serving files).
- Logging in works (checks the database connection).
- The chat bubble (bottom-right) opens, and after logging in, sending a
  message gets a reply (checks the `.env` key is being read correctly).

## 7. Day-to-day workflow between the two machines

Whichever machine you coded on last:

```bash
git add .
git commit -m "..."
git push
```

On the other machine, before you start working:

```bash
git pull
```

`.env` and the database are **not** part of this flow — see steps 3–4. If
the database schema ever changes on one machine, you'll need to re-export
and re-import it on the other (or agree on a shared dev database instead of
a local one per machine, if that becomes annoying).

---

**Note on `.htaccess`:** it's currently untracked (not yet committed) on
this PC, same as `.env`, `php/chatbot.php`, `php/config/env.php`,
`js/chatbot.js`, and `css/chatbot.css`. Unlike `.env`, `.htaccess` has no
secrets in it and is safe to commit — it's just waiting on you to say go.
Once it's committed and pushed, the laptop gets it automatically via
`git clone`/`git pull` and this manual step disappears from future setups.
