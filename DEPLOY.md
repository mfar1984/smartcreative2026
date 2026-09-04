# Deploying to cPanel

## Where the files go

Laravel serves from `public/`. Everything else must sit outside the web root,
because `.env` holds the `APP_KEY` and the database password and a web server will
happily hand it over as plain text.

There are two ways to arrange that. The first is cleaner; use it if cPanel lets you.

### Option 1: point the document root at `public/`

cPanel, **Domains**, find the domain, set **Document Root** to `public_html/public`.

Nothing else to do. `.env` is then above the document root and unreachable.

### Option 2: move the application above the web root

For the cases where the primary domain's document root cannot be changed.

```bash
cd ~
mkdir -p smartcreative
mv public_html/* public_html/.[!.]* smartcreative/ 2>/dev/null
mv smartcreative/public/* smartcreative/public/.htaccess public_html/
```

Then edit `public_html/index.php` and repoint the two requires:

```php
require __DIR__.'/../smartcreative/vendor/autoload.php';
$app = require_once __DIR__.'/../smartcreative/bootstrap/app.php';
```

### If the clone is already sitting in `public_html`

The `.htaccess` in the root of this repository rewrites everything into `public/`
and denies the sensitive files, so a wrong layout is safe rather than exposed. It
is a guard, not the fix. Do Option 1 or 2 when you can, then delete it.

## First run

```bash
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Then edit `.env`. The settings that matter on cPanel:

```
APP_ENV=production
APP_DEBUG=false          # true here prints the database password on any error page
APP_URL=https://your-domain

DB_HOST=localhost        # NOT 127.0.0.1
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

`DB_HOST` is the one that catches people. With `127.0.0.1` PHP opens a TCP socket to
port 3306, and cPanel usually has nothing listening there, which surfaces as
`SQLSTATE[HY000] [2002] Connection refused`. With `localhost` PHP uses the unix
socket instead. If it still refuses, find the socket and name it directly:

```bash
mysql_config --socket        # or: mysqladmin variables | grep socket
```

```
DB_SOCKET=/var/lib/mysql/mysql.sock
```

`APP_URL` has to be the canonical host exactly, with no `www.` and no trailing slash:

```
APP_URL=https://smartcreative.my
```

It is not cosmetic. Signed links carry a signature computed over the whole URL
including the host, so if `APP_URL` says `www.` and the visitor is on the bare
domain, or the other way round, the signature will not validate and the link
answers 403. The order confirmation, the cash on delivery receipt and the
registration payment links are all signed. CHIP is also registered against the bare
domain, and it refuses a callback on any port other than 80 or 443.

`public/.htaccess` sends `www.smartcreative.my` to the bare domain with a 301 so a
visitor who types www still lands somewhere the signatures hold.

Forcing plain HTTP to HTTPS is deliberately not in `.htaccess`. Use the **Force HTTPS
Redirect** toggle in cPanel's Domains screen instead. Doing it with a
`RewriteCond %{HTTPS} off` rule loops forever behind a proxy that terminates TLS,
such as Cloudflare, because `%{HTTPS}` reads `off` on a connection the visitor sees
as secure, and this application does not configure trusted proxies.

## Schema and the first account

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=AdminUserSeeder --force
php artisan db:seed --class=PointRuleSeeder --force
```

The admin account is taken from `ADMIN_USERNAME` and `ADMIN_PASSWORD` in `.env`.
Change the password after the first sign in, and take those two lines out.

## Storage

```bash
php artisan storage:link
```

`public/storage` is a symlink, so it cannot be committed and has to be recreated on
every deployment. Uploaded posters, team logos, branding images and match proofs are
served through it; without this they all 404.

## Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run these last, and run `php artisan config:clear` **before** editing `.env` again.
A cached config ignores `.env` entirely, which is why a corrected database password
sometimes appears to change nothing.

## Assets

`public/build` is committed, so there is nothing to compile on the server. If you
change any CSS or JavaScript, run `npm run build` locally and commit the result.

## Queue

`QUEUE_CONNECTION=database`, and a worker is required. Without one, every
registration confirmation, payment reminder and text message is written to the
`jobs` table and sits there. The registration itself is saved and correct; only the
messages are stranded, which is a failure nobody notices until a participant says
they were never told.

What needs the worker:

| Sent by | Queued | Needs a worker |
| --- | --- | --- |
| Participant and manager email | `Mail::to()->queue()` in `EventNotifier::queue()` | Yes |
| Participant and manager SMS | `SendTemplateSms::dispatch()` | Yes |
| Campaign messages | `SendCampaignMessage::dispatch()` | Yes, unless Send Now |
| Telegram staff alerts | not queued, `TelegramNotifier::post()` calls the API inline | No |

Scoring, draw generation and publishing all run inside the request that asked for
them, so those are unaffected. Campaign Send Now uses `dispatchSync` and also does
not need a worker.

The message log on a registration reports **Queued** until the worker runs. Status
becomes `sent` in `EventTemplateMail::build()` for email and
`SendTemplateSms::handle()` for SMS, both of which only execute inside the worker.
A row stuck on Queued therefore means the worker is not running, not that the
message was refused.

### Running the worker on cPanel

Shared cPanel hosting has no Supervisor, and a `queue:work` started over SSH dies
with the session. Use a cron job that drains the table and exits.

Resolve the PHP binary first. Do not assume a version:

```bash
readlink -f $(command -v php)     # the real binary, symlinks followed
command -v flock                  # confirm flock exists
```

`readlink -f` matters because `/usr/local/bin/php` on cPanel is often a wrapper that
picks a MultiPHP version from the working directory, and cron does not run in the
same directory or with the same `PATH` as your shell. Resolve it once and put the
absolute path in the cron line.

cPanel, **Advanced**, **Cron Jobs**, **Once Per Minute** (`* * * * *`):

```bash
<FLOCK> -n <HOME>/queue.lock <PHP> <HOME>/public_html/artisan queue:work --stop-when-empty --tries=3 --timeout=60 >> <HOME>/public_html/storage/logs/queue.log 2>&1
```

Substitute the paths the two commands above printed. Use absolute paths throughout
rather than `~`: cPanel's cron does not reliably expand a tilde, and a cron line that
fails on path expansion fails silently.

- `--stop-when-empty` drains the table then exits, so no process is left hanging.
- `flock -n` makes a run exit immediately if the previous one is still going.
  Without it a slow batch stacks a new PHP process every minute until the host
  suspends the account. `flock` creates the lock file itself; it does not need to
  exist beforehand.
- `--tries=3` matches `$tries` on `EventTemplateMail` and `SendTemplateSms`. After
  three failures the job lands in `failed_jobs` and the notification row turns
  **Failed** carrying the reason, written by the `failed()` method on each class.
- Redirecting output is not optional. Without it cPanel emails the cron output every
  minute.

On the current deployment those two commands resolved to `/usr/local/bin/php`
(PHP 8.3.33) and `/bin/flock`, with the home directory at `/home/smartcre`, giving:

```bash
/bin/flock -n /home/smartcre/queue.lock /usr/local/bin/php /home/smartcre/public_html/artisan queue:work --stop-when-empty --tries=3 --timeout=60 >> /home/smartcre/public_html/storage/logs/queue.log 2>&1
```

Note `/bin/flock`, not `/usr/bin/flock`. Resolve both again on any other host instead
of copying these.

Run that line by hand once before saving it as a cron job. `/usr/local/bin/php` is a
regular file rather than a symlink on cPanel, which means it can be a wrapper that
selects a MultiPHP version from the working directory. `cd / && /usr/local/bin/php -v`
proves which version cron will actually get.

If `flock` is missing, drop it and bound the run by time instead, which prevents
pile-up in a different way:

```bash
<PHP> <HOME>/public_html/artisan queue:work --max-time=50 --tries=3 --timeout=60 >> <HOME>/public_html/storage/logs/queue.log 2>&1
```

Check it took:

```bash
cd ~/public_html
php artisan queue:work --once -v            # force one job, watch what happens
php artisan tinker --execute="echo DB::table('jobs')->count();"
tail -20 storage/logs/queue.log
```

Jobs in the table do not expire, so messages stranded before the cron existed go out
on its first run without anyone pressing Send Again.

## Upgrading

```bash
cd /path/to/the/application
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan route:clear && php artisan view:clear && php artisan config:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

The clear line comes before the cache line, and skipping it is how a deployment
half-lands. A cached route table survives `git pull`, so a release that adds a route
serves the old table and the new view referencing the new route throws
`Route [...] not defined` on a screen that worked a minute ago. Caching on top of a
stale cache does not replace it.

`queue:restart` tells running workers to exit after their current job so the next one
starts on the new code. A worker holds the application in memory and will otherwise
keep running the version it booted with.

The seeder line is not optional, and leaving it out fails in a way that looks like
missing work rather than a missing step.

A release that adds a screen also adds the permission that screen is gated on. The
sidebar hides any item whose permission does not exist, so without re-seeding the
new screens are simply absent: no error, no empty page, nothing to suggest the code
arrived.

It expects to be run on every deployment. Two things it does are worth knowing:

- It resets the permissions of the four roles it declares (`super-admin`,
  `administrator`, `viewer`, `referee`) to the set in the seeder. If you granted one
  of them something extra by hand on the roles matrix, that grant is reverted. Roles
  you created yourself are never touched.
- It deletes permissions no longer declared in the seeder, so a withdrawn permission
  stops appearing on the matrix as a checkbox that grants nothing.

Accounts, passwords and role assignments are left alone.
