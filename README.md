# garak/sf-dep

Shared Deployer recipe for Symfony projects. Includes `recipe/symfony.php` and `contrib/cachetool.php`.

## Usage

```php
<?php

namespace Deployer;

require 'vendor/garak/sf-dep/recipe/sf-dep.php';

set('application', 'myapp');
set('repository', 'git@bitbucket.org:garak/{{application}}.git');
set('shared_dirs', ['var/log']);
set('writable_dirs', ['var', 'var/log']);

host('production')
    ->setHostname('my_host_name')
    ->set('deploy_path', '~/sf/{{application}}')
    ->set('remote_user', 'garak')
    ->set('user', 'garak')
    ->set('cachetool', '/run/php/php8.5-fpm.sock')
;
```

Do not require `recipe/symfony.php` again in the project.

## Tasks

| Task | Hook | Notes |
|---|---|---|
| `deploy:assets:build_local` | after `deploy:prepare` | runs `assets_build_cmd`, tars `assets_dirs` |
| `deploy:assets:upload` | before `deploy:symlink` | scp + extract into the release |
| `deploy:secrets:key` | after `deploy:shared` | `secrets` mode with a vault only: uploads the decryption key if missing, `chmod 640`, links it into the release |
| `deploy:db:update` | before `deploy:symlink` | `doctrine:schema:update` |
| `deploy:fpm:clear` | after `deploy:symlink` | path-scoped OPcache invalidation + APCu clear, only if `cachetool` is set |
| `deploy:warmup` | after `deploy:fpm:clear` | requests `warmup_url` via 127.0.0.1, only if set |
| `deploy:db:from_remote_copy` | manual | remote DB to local |
| `deploy:db:to_remote_copy` | manual | local DB to remote |

DB copy tasks pass credentials through a temporary `--defaults-extra-file`, never on the command line.

## Settings

| Setting | Default |
|---|---|
| `git_tty` | `true` |
| `keep_releases` | `2` |
| `release_name` | `date('YmdHis')` |
| `build_assets` | `true` |
| `assets_build_cmd` | `npm run build` |
| `assets_dirs` | `['public/build']` |
| `assets_archive` | `assets.tgz` |
| `env_source` | `secrets` (or `vhost`) |
| `secrets_list` | `config/secrets/prod/prod.list.php` |
| `db_env_var` | `APP_DATABASE_URL` |
| `db_env_file` | `/etc/nginx/sites-available/{{application}}` (`vhost` mode) |
| `secrets_key` | `config/secrets/prod/prod.decrypt.private.php` |
| `http_group` | `www-data` |
| `secrets_blank_env` | names in `config/secrets/prod/prod.list.php` |
| `update_db` | `true` |
| `schema_update_options` | `--force` |
| `db_local_client_options` | `''` (e.g. `--skip-ssl`) |
| `cachetool_clear_apcu` | `true` |
| `warmup_url` | `''` (disabled) |

## Env source

- `secrets` (default): Symfony prod vault. Without a vault (`secrets_list` missing) the vault handling is skipped. The DB URL is read with `secrets:reveal`. `bin/console` blanks every vault name (`env APP_X= ...`) so a real env var, e.g. from `/etc/environment`, can't override the vault. The deploy user must be in `http_group`.

- `vhost`: env vars live in the web server config. The DB URL is read with `grep` from `db_env_file`. Exposes the password to anyone who can read the vhost. Legacy projects must opt in:

```php
set('env_source', 'vhost');
```

`db_env_file` can be set per host, e.g. for a stage vhost:

```php
host('stage')->set('db_env_file', '/etc/nginx/sites-available/stage_{{application}}');
```
