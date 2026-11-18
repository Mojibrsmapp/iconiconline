#!/usr/bin/env python3
"""Build a code-only ZIP from a reviewed Git tree using a local ionCube Encoder."""

import argparse
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
APP_DIRS = {'admin', 'api_key', 'assets', 'fonts', 'includes', 'order', 'pages', 'smart'}
RUNTIME_TYPES = {
    '.css', '.eot', '.gif', '.html', '.jpeg', '.jpg', '.js', '.otf', '.php',
    '.png', '.svg', '.ttf', '.woff', '.woff2',
}
PRICE_FILES = {
    'auto_tin.txt', 'card_make.txt', 'deduction_amount.txt', 'recharge.txt',
    'signToServer.txt', 'smartret.txt', 'v3_deduction_amount.txt',
}
THEME_FILES = {
    f'{prefix}assets/vendors/typicons/{name}'
    for prefix in ('', 'admin/')
    for name in ('typicons.json', 'LICENCE.md')
}
REQUIRED = {
    'index.php', 'admin/index.php', 'user Dashboad.php', 'profile.php',
    'includes/database_settings.php', 'includes/lookup_unavailable.php',
    'assets/images/faces/face8.jpg',
}
PROBE_NAME = 'iconic_build_loader_probe.php'
PROBE_OK = b'ICONIC_IONCUBE_OK'
PROBE_PHP = b"""<?php
if (!function_exists('ioncube_file_is_encoded') || !ioncube_file_is_encoded()) {
    exit(2);
}
echo 'ICONIC_IONCUBE_OK';
"""


class BuildError(Exception):
    pass


def git(*args, stdout=subprocess.PIPE):
    return subprocess.run(['git', *args], cwd=ROOT, stdout=stdout, check=True).stdout


def validate_path(name):
    parts = name.split('/')
    if any(part.startswith('.') or not part for part in parts) or re.search(r'[\\:\x00-\x1f\x7f]', name):
        raise BuildError(f'Unsafe export path: {name!r}')
    path = PurePosixPath(name)
    if len(parts) == 1:
        allowed = (path.suffix == '.php' and name != PROBE_NAME) or name == 'sign.png'
    elif parts[0] == 'api_key':
        allowed = len(parts) == 2 and path.name in PRICE_FILES
    else:
        allowed = parts[0] in APP_DIRS and (path.suffix in RUNTIME_TYPES or name in THEME_FILES)
        if name.casefold().startswith(('admin/uploads/', 'smart/image/', 'smart/vendor/', 'assets/images/faces/user_')):
            allowed = False
    if not allowed:
        raise BuildError(f'Unexpected file in Git export: {name!r}')
    return path


def extract_export(archive_path, source):
    files = []
    seen = set()
    with tarfile.open(archive_path, 'r:') as archive:
        for member in archive:
            if member.isdir():
                continue  # We create only the directories needed for verified regular files.
            if not member.isfile():
                raise BuildError(f'Unexpected link or special file in export: {member.name!r}')
            name = member.name
            path = validate_path(name)
            if name.casefold() in seen:
                raise BuildError(f'Duplicate file in export: {name!r}')
            seen.add(name.casefold())
            destination = source.joinpath(*path.parts)
            destination.parent.mkdir(parents=True, exist_ok=True)
            with archive.extractfile(member) as incoming, destination.open('xb') as outgoing:
                shutil.copyfileobj(incoming, outgoing)
            if path.parts[0] == 'api_key' and not re.fullmatch(rb'[0-9]+(?:\.[0-9]+)?', destination.read_bytes().strip()):
                raise BuildError(f'Expected a numeric rate, not a secret or configuration file: {name!r}')
            files.append(name)
    missing = REQUIRED.difference(files)
    if missing:
        raise BuildError(f'Not a cleaned release tree; required files missing: {", ".join(sorted(missing))}')
    return sorted(files)


def export_tree(ref, scratch):
    # Resolve an exact tree and use that tree's export rules, not the checkout or an old ZIP.
    tree = git('rev-parse', '--verify', '--end-of-options', f'{ref}^{{tree}}').decode().strip()
    if subprocess.run(['git', 'cat-file', '-e', f'{tree}:.gitattributes'], cwd=ROOT, stderr=subprocess.DEVNULL).returncode:
        raise BuildError('No release export rules in this tree. Use the reviewed cleanup commit, not the original upload.')
    archive = scratch / 'source.tar'
    with archive.open('xb') as stream:
        git('archive', '--format=tar', tree, stdout=stream)
    source = scratch / 'source'
    files = extract_export(archive, source)
    archive.unlink()
    return source, files


def find_executable(value, label):
    binary = shutil.which(value)
    if binary is None:
        raise BuildError(f'{label} not found or not executable: {value}. No ZIP was created.')
    return os.path.abspath(binary)


def check_tools(encoder, php, target):
    version = subprocess.run([*encoder, '-V'], capture_output=True, check=True, timeout=20)
    banner = (version.stdout + version.stderr).lower()
    if not re.search(rb'ioncube\s+(?:php\s+)?encoder', banner):
        raise BuildError('Use the official, activated ionCube Encoder executable.')
    if re.search(rb'\b(?:evaluation|trial)\b', banner):
        raise BuildError('Refusing an evaluation/trial Encoder for client delivery.')

    check = "echo PHP_MAJOR_VERSION, '.', PHP_MINOR_VERSION, '|', (int) extension_loaded('ionCube Loader');"
    result = subprocess.run([php, '-r', check], capture_output=True, check=True, timeout=20).stdout.strip()
    if result != f'{target}|1'.encode():
        raise BuildError(f'The build PHP CLI must be PHP {target} with ionCube Loader enabled. Check the client web runtime separately.')


def encode_php(source, files, scratch, encoder, php):
    # Give the Encoder only PHP, with the exact same relative names and hierarchy.
    # Assets always come straight from the original export, never from the Encoder.
    php_files = [name for name in files if name.endswith('.php')]
    encoder_input = scratch / 'php-input'
    encoded = scratch / 'php-encoded'
    for name in php_files:
        target = encoder_input / name
        target.parent.mkdir(parents=True, exist_ok=True)
        data = (source / name).read_bytes()
        # Two legacy directory indexes are exactly zero bytes; keep them no-output
        # and preserve PHP's implicit successful include result.
        target.write_bytes(data or b'<?php\nreturn 1;\n')
    (encoder_input / PROBE_NAME).write_bytes(PROBE_PHP)
    subprocess.run(
        [*encoder, '--ascii', str(encoder_input), '-o', str(encoded)],
        stdin=subprocess.DEVNULL,
        check=True,
    )

    if encoded.is_symlink() or not encoded.is_dir():
        raise BuildError('The Encoder did not create a regular output directory.')
    output_files = set()
    for path in encoded.rglob('*'):
        if path.is_symlink() or not (path.is_dir() or path.is_file()):
            raise BuildError('Unexpected link or special file in Encoder output.')
        if path.is_file():
            output_files.add(path.relative_to(encoded).as_posix())
    expected_files = set(php_files) | {PROBE_NAME}
    if output_files != expected_files:
        missing = ', '.join(sorted(expected_files - output_files)) or 'none'
        extra = ', '.join(sorted(output_files - expected_files)) or 'none'
        raise BuildError(f'Encoder output does not match input (missing: {missing}; extra: {extra}).')

    for name in sorted(expected_files):
        data = (encoded / name).read_bytes()
        # Stock ASCII output starts with a PHP Loader stub. Do not publish a copied
        # or empty PHP file. The independent runtime probe below exercises the Loader.
        if (data == (encoder_input / name).read_bytes() or not data.startswith(b'<?php')
                or b'ioncube loader' not in data[:8192].lower()):
            raise BuildError(f'No ionCube Loader stub in output for {name!r}; refusing the ZIP.')
    probe = subprocess.run(
        [php, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', str(encoded / PROBE_NAME)],
        capture_output=True,
        timeout=20,
    )
    if probe.returncode or probe.stdout.strip() != PROBE_OK:
        raise BuildError('The encoded smoke test did not run as encoded PHP. No ZIP was created.')
    return encoded


def write_zip(source, encoded, files, output):
    output.parent.mkdir(parents=True, exist_ok=True)
    # Use a private sibling file and publish only a complete ZIP, without overwriting.
    with tempfile.NamedTemporaryFile(dir=output.parent, prefix='.encoded-', suffix='.tmp', delete=False) as temp:
        temporary = Path(temp.name)
    try:
        with zipfile.ZipFile(temporary, 'w', compression=zipfile.ZIP_DEFLATED) as package:
            for name in files:
                root = encoded if name.endswith('.php') else source
                package.write(root / name, arcname=name)
        os.link(temporary, output)
    finally:
        temporary.unlink(missing_ok=True)


def php_version(value):
    if not re.fullmatch(r'8\.[0-9]+', value):
        raise argparse.ArgumentTypeError('use the PHP 8 major.minor version, for example 8.4')
    return value


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ref', required=True, help='Reviewed cleaned Git commit or tree; never the original upload')
    parser.add_argument('--check-only', action='store_true', help='Validate the export; do not call the Encoder or create a ZIP')
    parser.add_argument('--encoder', default='ioncube_encoder.sh', help='Official activated Encoder executable')
    parser.add_argument('--encoder-php', type=php_version, help='PHP language selector for the vendor .sh wrapper')
    parser.add_argument('--php', default='php', help='Target-version PHP CLI with ionCube Loader on the build machine')
    parser.add_argument('--target-php', type=php_version, help='Client web runtime major.minor version')
    parser.add_argument('--output', type=Path, help='New .zip destination, e.g. dist/iconiconline-encoded.zip')
    args = parser.parse_args()
    if args.check_only and args.output is not None:
        parser.error('--check-only does not create a ZIP; omit --output')
    if not args.check_only and (args.target_php is None or args.output is None or args.output.suffix.lower() != '.zip'):
        parser.error('a real build requires --target-php and a new --output ending in .zip')

    try:
        if not args.check_only:
            if os.path.lexists(args.output):
                raise BuildError(f'Output already exists: {args.output}. Use a new destination.')
            encoder = [find_executable(args.encoder, 'Licensed ionCube Encoder')]
            if args.encoder_php or Path(encoder[0]).name == 'ioncube_encoder.sh':
                encoder.append('-' + (args.encoder_php or args.target_php).replace('.', ''))
            php = find_executable(args.php, 'PHP CLI')
            check_tools(encoder, php, args.target_php)
        with tempfile.TemporaryDirectory(prefix='iconic-encode-') as temp:
            scratch = Path(temp)
            source, files = export_tree(args.ref, scratch)
            num_php = sum(name.endswith('.php') for name in files)
            print(f'Checked Git export: {num_php} PHP files and {len(files) - num_php} unchanged non-PHP files.', flush=True)
            if args.check_only:
                print('Check only: no encoding performed and no ZIP created.')
            else:
                encoded = encode_php(source, files, scratch, encoder, php)
                write_zip(source, encoded, files, args.output)
                print(f'Encoded ZIP created: {args.output}. Test it on the client web runtime before go-live.')
    except (BuildError, OSError, subprocess.SubprocessError, tarfile.TarError, zipfile.BadZipFile) as error:
        print(f'Build stopped: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
