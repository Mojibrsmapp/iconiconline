#!/usr/bin/env python3
"""Check a clean, owner-only Git export. Optional PHP checks use no DB or APIs."""

import argparse
import io
import os
from pathlib import Path
import re
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[1]
RATES = {
    'auto_tin.txt', 'card_make.txt', 'deduction_amount.txt', 'recharge.txt',
    'signToServer.txt', 'smartret.txt', 'v3_deduction_amount.txt',
}
PRIVATE_PREFIXES = (
    '.git/', 'deploy/', 'docs/', 'tests/', 'uploads/', 'admin/uploads/', 'lib/',
    'pdf/', 'photo/', 'qr_photo/', 'smart/image/', 'assets/images/faces/user_',
)
PRIVATE_NAMES = {
    '.gitignore', '.gitattributes', 'error_log', '.startup_status', 'config.json',
    'api_debug.txt', 'error_response_log.txt', 'createLink.txt', 'index.html',
    'nid_backup.php', 'on_off_test.php', 'bio_order.php',
}
PRIVATE_EXTENSIONS = ('.sql', '.map', '.log', '.pem', '.key', '.zip', '.tar.gz')
REQUIRED = {
    'index.php', 'admin/index.php', 'user Dashboad.php', '404.php',
    'profile.php', 'includes/user_session.php', 'includes/database_settings.php',
    'pages/login.php', 'recharge.php', 'assets/images/faces/face8.jpg',
}
LOOKUPS = ('fetch_info.php', 'insert_brn_info.php', 'insert_tin_data.php', 'insert_un_server.php')


def require(condition, message):
    if not condition:
        raise SystemExit(message)


def check_export(ref):
    archive = subprocess.check_output(['git', 'archive', '--format=tar.gz', ref], cwd=ROOT)
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as release:
        members = release.getmembers()
        files = {item.name for item in members if item.isfile()}
        require(all(item.isfile() or item.isdir() for item in members), 'Unexpected link or special file in export')
        require(REQUIRED <= files, f'Missing runtime files: {sorted(REQUIRED - files)}')
        for path in files:
            name = Path(path).name
            require(not (
                path.startswith(PRIVATE_PREFIXES) or name in PRIVATE_NAMES
                or name.startswith('.env') or path.endswith(PRIVATE_EXTENSIONS)
            ), f'Private or development file in export: {path}')
        require({path.removeprefix('api_key/') for path in files if path.startswith('api_key/')} == RATES,
                'Unexpected pricing file set')
        for rate in RATES:
            value = release.extractfile('api_key/' + rate).read().strip()
            require(re.fullmatch(rb'[0-9]+(?:\.[0-9]+)?', value), f'Non-numeric pricing file: {rate}')
        profile = release.extractfile('profile.php').read().lower()
        require(not any(marker in profile for marker in (b'getusermedia', b'navigator.geolocation', b'telegram')),
                'Third-party profile capture code in export')
    print(f'Export verified: {len(files)} runtime files; no tracked uploads, logs, SQL, or known local config.')


def check_php(php):
    paths = subprocess.check_output(['git', 'ls-files', '-z', '--', '*.php'], cwd=ROOT).split(b'\0')
    php_files = sorted(os.fsdecode(path) for path in paths if path and (ROOT / os.fsdecode(path)).is_file())
    subprocess.run([php, '-n', '-l', *php_files], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    print(f'PHP syntax verified: {len(php_files)} working-tree files.')

    env = {key: value for key, value in os.environ.items() if not key.startswith('ICONIC_DB_')}

    def run_php(body):
        return subprocess.check_output([php, '-n', '-r', body], cwd=ROOT, env=env, text=True).strip()

    status = 'register_shutdown_function(function () { echo "\\nstatus=", http_response_code(); });'
    for script in ('includes/configuration.php', 'includes/config.php', 'admin/includes/configuration.php'):
        message = run_php(f"{status} require '{script}';")
        require(message == 'Service temporarily unavailable.\nstatus=503', f'{script} did not fail closed')

    invalid_port = run_php(status + """
        putenv('ICONIC_DB_HOST=example.invalid');
        putenv('ICONIC_DB_NAME=example'); putenv('ICONIC_DB_USER=example');
        putenv('ICONIC_DB_PASSWORD=synthetic'); putenv('ICONIC_DB_PORT=0');
        require 'includes/database_settings.php'; iconic_database_settings();
    """)
    require(invalid_port == 'Service temporarily unavailable.\nstatus=503', 'Invalid DB port was accepted')
    for script in LOOKUPS:
        reply = run_php(f"{status} require '{script}';")
        require('"success":false' in reply and reply.endswith('status=410'), f'Old lookup did not stay disabled: {script}')
    for script in ('admin/pages/delete_user.php', 'admin/pages/active_deactive.php',
                   'admin/pages/update_admin_user.php', 'admin/admin_file_upload.php'):
        require(run_php(f"{status} require '{script}';") == 'Forbidden\nstatus=403',
                f'Unauthenticated admin request was not blocked: {script}')
    for script in ('pages/login.php', 'admin/pages/login.php'):
        response = run_php(f"{status} $_SERVER['REQUEST_METHOD'] = 'GET'; require '{script}';")
        require(response.endswith('status=405'), f'Non-POST login was not rejected: {script}')
    require(run_php(f"{status} require 'profile.php';") == 'status=302',
            'Anonymous profile access was not redirected')
    print('Offline DB configuration, lookup, login, profile, and admin guard checks passed.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('ref', nargs='?', default='HEAD', help='Git commit or tree to export')
    parser.add_argument('--php', help='Optional PHP 8.3+ CLI executable for working-tree lint and offline checks')
    args = parser.parse_args()
    check_export(args.ref)
    if args.php:
        check_php(args.php)


if __name__ == '__main__':
    main()
