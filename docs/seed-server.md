# Seed Server

A lightweight PHP REST API for storing and distributing seed files. Upload a database export from one client, download and import it on another.

## Overview

The Seed Server stores multi-part V2 MessagePack files on a server. The Blazor `SeedManager` component provides:

- **Upload** — exports the current database and uploads parts to the server
- **Download** — downloads seed parts from the server and imports into the local database
- **List/Delete** — manage seeds on the server

## Setup

### Local Development (Herd/Valet)

```bash
cd SeedApi
herd link seed-api
herd secure seed-api
```

The `LocalValetDriver.php` handles URL routing for Herd/Valet.

**Important**: Increase PHP upload limits in Herd's GUI — the default 2MB is too small for seed files:
1. Open Herd → Settings → PHP
2. Set `upload_max_filesize`, `post_max_size`, and `memory_limit` to match `cloudPartSizeMb` with overhead (e.g., `cloudPartSizeMb: 40` → set PHP limits to at least `50M`)
3. Restart PHP in Herd

The `.htaccess` `php_value` directives are for Apache production servers and do NOT override Herd's global `php.ini`.

### Production (Apache)

Copy to your web server:
- `seed-api.php` — the API
- `index.php` — landing page
- `.htaccess` — URL rewriting + PHP limits

The `.htaccess` configures:
```apache
# URL routing
RewriteRule ^api/(.*)$ seed-api.php?path=$1 [QSA,L]

# Upload limits (adjust to match cloudPartSizeMb)
php_value upload_max_filesize 300M
php_value post_max_size 300M
```

The `seeds/` directory is auto-created and protected from direct access.

### Nginx

Add to your server block:
```nginx
client_max_body_size 300M;

location /api/ {
    rewrite ^/api/(.*)$ /seed-api.php?path=$1 last;
}

location /seeds/ {
    deny all;
}
```

## Client Configuration

In `wwwroot/appsettings.json`:

```json
{
  "seedApiUrl": "seed-api.test",
  "exportPartSizeMb": 250,
  "cloudPartSizeMb": 40
}
```

| Setting | Description | Default |
|---------|-------------|---------|
| `seedApiUrl` | Seed server domain (without protocol). Editable on the Seed Server page. Empty = disabled | `""` |
| `exportPartSizeMb` | Max part size in MB for local file export | `250` |
| `cloudPartSizeMb` | Max part size in MB for cloud upload (auto-synced from `.htaccess` at build time, should be < server upload limit) | `40` |

### Automatic Cloud Part Size Sync

The Demo project includes a MSBuild task (`SyncCloudPartSize`) that reads `upload_max_filesize` from `SeedApi/.htaccess` and writes 80% of it as `cloudPartSizeMb` to `appsettings.json` at build time. This keeps the client and server limits in sync.

## API Endpoints

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/api/seeds` | List all seeds with metadata |
| `GET` | `/api/seeds/{name}` | Get seed details and file list |
| `GET` | `/api/seeds/{name}/{file}` | Download a specific file |
| `POST` | `/api/seeds/{name}` | Upload a file (multipart form, field `file`) |
| `DELETE` | `/api/seeds/{name}` | Delete seed and all its parts |

### Example: List Seeds

```bash
curl https://seed-api.test/api/seeds
```

```json
[
  {
    "name": "todoitems-full-20260330-120000",
    "metaFile": "todoitems-full-20260330-120000.msgpack-meta",
    "totalRecordCount": 4000000,
    "exportedAt": "2026-03-30T12:00:00Z",
    "partCount": 4,
    "files": [
      { "name": "todoitems-full-20260330-120000.msgpack-meta", "size": 512 },
      { "name": "todoitems-full-20260330-120000-part001.msgpack", "size": 52428800 }
    ]
  }
]
```

### Example: Upload a File

```bash
curl -F "file=@todoitems-full-20260330-120000-part001.msgpack" \
  https://seed-api.test/api/seeds/todoitems-full-20260330-120000
```

## Storage Layout

```
SeedApi/
  seed-api.php
  index.php
  .htaccess
  .user.ini
  LocalValetDriver.php     (local dev only)
  seeds/                   (auto-created)
    todoitems-full-20260330-120000/
      todoitems-full-20260330-120000.msgpack-meta
      todoitems-full-20260330-120000-part001.msgpack
      todoitems-full-20260330-120000-part002.msgpack
```

## Security

This is a proof-of-concept with no authentication. For production:

- Add API key or Bearer token authentication
- Restrict CORS to your application's origin
- Use HTTPS
- Consider rate limiting for uploads
- Validate file content (check V2 magic number) before storing

## Future: Server-Side Delta Generation

A planned extension: the server can host a SQLite database and generate V2 MessagePack delta files directly using PHP's built-in SQLite3 support. This would enable seeding new clients without any client-side export.
