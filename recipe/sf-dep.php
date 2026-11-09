<?php

namespace Deployer;

require_once 'recipe/symfony.php';
require_once 'contrib/cachetool.php';

// Defaults. Override with set() in deploy.php after requiring this file.
set('git_tty', true);
set('keep_releases', 2);
set('release_name', static fn (): string => \date('YmdHis'));

// Assets: built locally, uploaded as a tarball.
set('build_assets', true);
set('assets_build_cmd', 'npm run build');
set('assets_dirs', ['public/build']);
set('assets_archive', 'assets.tgz');

// Where the prod env vars live:
// - "secrets" (preferred): Symfony prod vault, decryption key kept in shared/ and readable by http_group only.
//   Projects without a vault (no secrets_list file) skip the vault handling;
// - "vhost": APP_* defined in the web server config (db_env_file: nginx site, snippet, httpd vhost).
set('env_source', 'secrets');
set('db_env_var', 'APP_DATABASE_URL');
set('db_env_file', '/etc/nginx/sites-available/{{application}}');
set('secrets_key', 'config/secrets/prod/prod.decrypt.private.php');
set('secrets_list', 'config/secrets/prod/prod.list.php');
set('http_group', 'www-data');
// A real env var beats the vault (e.g. APP_* in /etc/environment for other apps): blank the vault names.
set('secrets_blank_env', static function (): array {
    $list = get('secrets_list');

    return \is_file($list) ? \array_keys(require $list) : [];
});
set('bin/console', static function (): string {
    $console = '{{bin/php}} {{release_or_current_path}}/bin/console';
    if ('secrets' !== get('env_source') || [] === get('secrets_blank_env')) {
        return $console;
    }
    $blank = \implode(' ', \array_map(static fn (string $var): string => $var.'=', get('secrets_blank_env')));

    return "env $blank $console";
});

// Database.
set('update_db', true);
set('schema_update_options', '--force');
// Extra options for the local mysql/mysqldump clients, e.g. "--skip-ssl".
set('db_local_client_options', '');

// FPM caches. Active only when the host sets "cachetool" (FPM socket) or "cachetool_args".
set('cachetool_clear_apcu', true);
set('warmup_url', '');

/**
 * @return array{url: string, host: string, port: int|null, user: string, pass: string, name: string}
 */
function garakParseDbUrl(string $raw, string $source): array
{
    if (!\preg_match('#(mysql://\S+)#', $raw, $matches)) {
        throw error(get('db_env_var')." not found in $source");
    }
    $url = \rtrim($matches[1], ';"\'');
    $parts = \parse_url($url);
    if (false === $parts) {
        throw error('Invalid '.get('db_env_var')." in $source");
    }

    return [
        'url' => $url,
        'host' => $parts['host'] ?? 'localhost',
        'port' => $parts['port'] ?? null,
        'user' => \urldecode($parts['user'] ?? ''),
        'pass' => \urldecode($parts['pass'] ?? ''),
        'name' => \ltrim($parts['path'] ?? '', '/') ?: get('application'),
    ];
}

/**
 * @return array{url: string, host: string, port: int|null, user: string, pass: string, name: string}
 */
function garakRemoteDb(): array
{
    if ('secrets' === get('env_source')) {
        return garakParseDbUrl(run('{{bin/console}} secrets:reveal {{db_env_var}}'), 'prod secrets');
    }
    $file = get('db_env_file');

    return garakParseDbUrl(run("grep {{db_env_var}} $file"), $file);
}

/**
 * @return array{url: string, host: string, port: int|null, user: string, pass: string, name: string}
 */
function garakLocalDb(): array
{
    return garakParseDbUrl((string) \getenv(get('db_env_var')), 'local environment');
}

/**
 * Client options file, so the password never shows up in a process list.
 *
 * @param array{url: string, host: string, port: int|null, user: string, pass: string, name: string} $db
 */
function garakMyCnf(array $db): string
{
    $cnf = "[client]\nuser=\"{$db['user']}\"\npassword=\"{$db['pass']}\"\nhost=\"{$db['host']}\"\n";
    if (null !== $db['port']) {
        $cnf .= "port={$db['port']}\n";
    }

    return $cnf;
}

desc('Uploads the secrets decryption key if missing and links it into the release');
task('deploy:secrets:key', static function (): void {
    if ('secrets' !== get('env_source') || !\is_file(get('secrets_list'))) {
        return;
    }
    $key = get('secrets_key');
    $shared = "{{deploy_path}}/shared/$key";
    if (!test("[ -s $shared ]")) {
        if (!\is_file($key)) {
            throw error("Missing $key: copy it from the server or regenerate the vault.");
        }
        run('mkdir -p '.\dirname($shared));
        runLocally("scp $key {{user}}@{{hostname}}:$shared");
    }
    // php-fpm group only: the deploy user must belong to http_group.
    run("chgrp {{http_group}} $shared && chmod 640 $shared");
    run("ln -nfs $shared {{release_path}}/$key");
});

desc('Precompile assets');
task('deploy:assets:build_local', static function (): void {
    if (!get('build_assets')) {
        return;
    }
    runLocally('{{assets_build_cmd}}');
    runLocally('tar zcf {{assets_archive}} '.\implode(' ', \array_map('escapeshellarg', get('assets_dirs'))));
});

desc('Upload precompiled assets');
task('deploy:assets:upload', static function (): void {
    if (!get('build_assets')) {
        return;
    }
    try {
        runLocally('scp {{assets_archive}} {{user}}@{{hostname}}:{{release_path}}/');
        run('cd {{release_path}} && tar zxf {{assets_archive}} && rm {{assets_archive}}');
    } finally {
        runLocally('rm -f {{assets_archive}}');
    }
});

desc('Update database schema');
task('deploy:db:update', static function (): void {
    if (!get('update_db')) {
        return;
    }
    if ('secrets' === get('env_source')) {
        run('{{bin/console}} doctrine:schema:update {{schema_update_options}} {{console_options}}');

        return;
    }
    $url = \escapeshellarg(garakRemoteDb()['url']);
    run("{{db_env_var}}=$url {{bin/console}} doctrine:schema:update {{schema_update_options}} {{console_options}}");
});

desc('Copy remote database to local');
task('deploy:db:from_remote_copy', static function (): void {
    $remote = garakRemoteDb();
    $local = garakLocalDb();
    $name = '{{application}}_'.\date('YmdHis').'.sql.gz';
    $cnf = '/tmp/{{application}}_'.\bin2hex(\random_bytes(4)).'.cnf';
    $localCnf = \tempnam(\sys_get_temp_dir(), 'dep');

    try {
        run("umask 077; printf %s ".\escapeshellarg(garakMyCnf($remote))." > $cnf");
        run("mysqldump --defaults-extra-file=$cnf ".\escapeshellarg($remote['name'])." | gzip > /tmp/$name");
    } finally {
        run("rm -f $cnf");
    }
    runLocally("scp {{user}}@{{hostname}}:/tmp/$name .");
    run("rm -f /tmp/$name");

    try {
        \file_put_contents($localCnf, garakMyCnf($local));
        \chmod($localCnf, 0600);
        runLocally("gunzip -c $name | mysql --defaults-extra-file=$localCnf {{db_local_client_options}} ".\escapeshellarg($local['name']));
    } finally {
        \unlink($localCnf);
        runLocally("rm -f $name");
    }
});

desc('Copy local database to remote');
task('deploy:db:to_remote_copy', static function (): void {
    $local = garakLocalDb();
    $remote = garakRemoteDb();
    $name = '{{application}}_'.\date('YmdHis').'.sql.gz';
    $cnf = '/tmp/{{application}}_'.\bin2hex(\random_bytes(4)).'.cnf';
    $localCnf = \tempnam(\sys_get_temp_dir(), 'dep');

    try {
        \file_put_contents($localCnf, garakMyCnf($local));
        \chmod($localCnf, 0600);
        runLocally("mysqldump --defaults-extra-file=$localCnf {{db_local_client_options}} -K ".\escapeshellarg($local['name'])." | gzip > /tmp/$name");
    } finally {
        \unlink($localCnf);
    }
    runLocally("scp /tmp/$name {{user}}@{{hostname}}:/tmp/");
    runLocally("rm -f /tmp/$name");

    try {
        run("umask 077; printf %s ".\escapeshellarg(garakMyCnf($remote))." > $cnf");
        run("gunzip -c /tmp/$name | mysql --defaults-extra-file=$cnf ".\escapeshellarg($remote['name']));
    } finally {
        run("rm -f $cnf /tmp/$name");
    }
});

desc('Invalidates OPcache entries of this application and clears APCu');
task('deploy:fpm:clear', static function (): void {
    if ('' === (string) get('cachetool_args') && [] === \array_filter((array) get('cachetool'))) {
        return;
    }
    foreach (get('cachetool_options') as $option) {
        // Path-scoped: other apps on the same FPM master keep their OPcache.
        run("cd {{release_or_current_path}} && {{bin/php}} {{bin/cachetool}} opcache:invalidate:scripts --force $option {{deploy_path}}");
        if (get('cachetool_clear_apcu')) {
            run("cd {{release_or_current_path}} && {{bin/php}} {{bin/cachetool}} apcu:cache:clear $option");
        }
    }
});

desc('Warms up OPcache by requesting warmup_url from the server itself');
task('deploy:warmup', static function (): void {
    $url = (string) get('warmup_url');
    if ('' === $url) {
        return;
    }
    $parts = \parse_url($url);
    $scheme = $parts['scheme'] ?? 'https';
    $port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);
    $resolve = \escapeshellarg("{$parts['host']}:$port:127.0.0.1");
    $code = run("curl -sk -o /dev/null -w '%{http_code}' --resolve $resolve ".\escapeshellarg($url).' || true');
    writeln("Warmup: HTTP $code");
});

after('deploy:failed', 'deploy:unlock');
after('deploy:shared', 'deploy:secrets:key');
after('deploy:prepare', 'deploy:assets:build_local');
before('deploy:symlink', 'deploy:assets:upload');
before('deploy:symlink', 'deploy:db:update');
after('deploy:symlink', 'deploy:fpm:clear');
after('deploy:fpm:clear', 'deploy:warmup');
