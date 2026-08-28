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

`QUEUE_CONNECTION=database`, but nothing in the application relies on a worker:
scoring, draw generation and publishing all run inside the request that asked for
them. Campaign sending is the one thing that queues. If you want it to drain
without a worker, keep using Send Now, which dispatches synchronously.

## Upgrading

```bash
cd /path/to/the/application
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

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
