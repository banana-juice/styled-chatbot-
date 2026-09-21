# Running Styled (and the chatbot) on another machine

The chatbot code is in this repo, but a fresh clone is missing two things that
**never travel through Git on purpose**: the **database** and your **API key**
(`.env`). Without both, the site can't log anyone in and the chatbot can't
answer. This guide covers getting them in place.

---

## 1. Install prerequisites

- **XAMPP** (PHP 8.x with the `curl` extension, which is on by default):
  https://www.apachefriends.org/
- **Git**: https://git-scm.com/downloads

## 2. Clone into XAMPP's web folder

```bash
cd C:\xampp\htdocs
git clone https://github.com/banana-juice/styled-chatbot-.git styled
```

The folder must be named `styled` so the site is at `http://localhost/styled/`.
`vendor/` (PHPMailer) is committed, so no Composer step is needed.

## 3. Database

The site needs a MySQL database called **`styled_db`**. The schema and data
are not in the repo, because the live export contains real teammates' emails
and password hashes and this repo is public. Ask the person who runs the live
site (Adrielle) to send you the `.sql` export directly (not through Git), then:

```bash
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS styled_db"
C:\xampp\mysql\bin\mysql.exe -u root --force styled_db < path\to\the-export.sql
```

`--force` is there because the current export has one orphaned foreign key
(an address pointing at a deleted user). Without it the import stops near the
end; the data still loads, but the remaining constraints get skipped.

[php/db.php](php/db.php) already matches XAMPP's defaults (`localhost`,
`root`, no password), so no code change is needed locally.

## 4. Add your API key (`.env`)

```bash
copy .env.example .env
```

Then open `.env` and replace `paste-your-key-here` with a real key from
https://console.anthropic.com/ (set a spending limit on it there).

- `.env` is git-ignored: it never gets committed, so **every machine needs its
  own**. Do not paste the key into chat, email, or any file that is committed.
- Each teammate can create their own key, or the key owner can share theirs
  privately (a password manager, not a message). One shared key means everyone
  spends the same balance.
- [.htaccess](.htaccess) blocks the web from serving `.env` and `.git`.

## 5. Start XAMPP and open the site

Start **Apache** and **MySQL** in the XAMPP Control Panel, then open
`http://localhost/styled/index.html`. Log in with an account that exists in
your database, open the chat bubble (bottom right) and send a message.

---

## Chatbot not working? Checklist

The chat widget only replies to **logged-in** users. If it fails, open the
browser DevTools (F12), go to **Console**, and look for a line starting with
`[chatbot]`. On localhost it prints a `code` and the exact reason.

| Console code | Meaning | Fix |
|---|---|---|
| (widget says "Please log in") | Not logged in, or no `styled_db` so login can't work | Do step 3, then log in |
| `no_api_key` | No `.env`, or the key line is empty | Do step 4 |
| `no_curl` | PHP curl extension is off | In `C:\xampp\php\php.ini` remove the `;` before `extension=curl`, restart Apache |
| `network` | Server can't reach api.anthropic.com. Often `SSL certificate problem` on a fresh XAMPP | Check internet/firewall. For the certificate error, download `cacert.pem` from https://curl.se/ca/cacert.pem, save it to `C:\xampp\php\extras\ssl\cacert.pem`, set `curl.cainfo="C:\xampp\php\extras\ssl\cacert.pem"` in `php.ini`, restart Apache |
| `api_error` | Anthropic rejected the request (e.g. invalid key, no credit) | Read the `detail` text. Fix the key or add credit in the Anthropic console |
| "Database connection failed" | MySQL not running, or `styled_db` missing | Start MySQL, do step 3 |

Server-side details are also written to `C:\xampp\apache\logs\error.log`.

## Making it available to everyone (deployment)

Groupmates who clone the repo only get the code. To let people just *use* the
chatbot without setting anything up, host the site once and keep the key on
the server:

1. Upload the project to your PHP host (the live site is on InfinityFree).
   The chatbot needs `php/chatbot.php`, `php/config/env.php`, `js/chatbot.js`,
   `css/chatbot.css`, `.htaccess`, and the updated `*.html` files.
2. On the server, create a file named `.env` in the site root containing
   `ANTHROPIC_API_KEY=...` (use the host's File Manager). **Do not** get it
   there through GitHub.
3. On the host, `php/db.php` uses that host's own database name, user and
   password, not the XAMPP defaults in this repo. Keep the server's copy.
4. Confirm `https://your-site/.env` returns 403/404, not the file contents.
5. Confirm the host allows outbound HTTPS calls from PHP (curl). Some free
   hosts restrict this; if `network` errors persist there, that's the cause.
6. Set a spending limit on the key in the Anthropic console. Only logged-in
   users can chat, but a public site should still cap what the key can spend.

## Day-to-day between machines

```bash
git pull      # get the latest
git push      # share your changes (to the repo you cloned from)
```

`.env` and the database are outside this flow: set them up once per machine.
