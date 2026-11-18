"""Offline build tests with synthetic files. No mock output is a real ionCube file."""

import io
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deploy'))
import build_encoded_release as release


class EncodedReleaseTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.scratch = Path(self.tmp.name)
        self.source = self.scratch / 'source'

    def archive(self, entries):
        path = self.scratch / 'test.tar'
        with tarfile.open(path, 'w') as out:
            for name, body in entries.items():
                info = tarfile.TarInfo(name)
                if body is None:
                    info.type = tarfile.SYMTYPE
                    info.linkname = '/test-target'
                    out.addfile(info)
                else:
                    info.size = len(body)
                    out.addfile(info, io.BytesIO(body))
        return path

    def test_export_rejects_sensitive_or_unsafe_paths(self):
        for name in ('deploy/sql/dump.sql', 'uploads/results/private.jpg', 'admin/uploads/order.jpg',
                     'smart/image/private.jpg', 'assets/images/faces/user_123.jpg', 'api_key/secret.txt',
                     'assets/client.sql', 'config.json', '.git/config', 'admin/../escape.php',
                     '/escape.php', r'assets\escape.php'):
            with self.subTest(name=name), self.assertRaises(release.BuildError):
                release.extract_export(self.archive({name: b'fixture only'}), self.source)
        self.assertFalse(self.source.exists())

    def test_export_rejects_links_and_non_numeric_rates(self):
        with self.assertRaisesRegex(release.BuildError, 'link'):
            release.extract_export(self.archive({'assets/link.png': None}), self.source)
        with self.assertRaisesRegex(release.BuildError, 'numeric rate'):
            release.extract_export(self.archive({'api_key/smartret.txt': b'not-a-number'}), self.source)

    def test_export_preserves_exact_runtime_names_and_bytes(self):
        entries = {name: b'fixture' for name in release.REQUIRED}
        entries['assets/theme.css'] = b':root{--test:red}\r\n'
        entries['api_key/smartret.txt'] = b'1.25\n'
        result = release.extract_export(self.archive(entries), self.source)
        self.assertEqual(sorted(entries), result)
        for name, body in entries.items():
            self.assertEqual(body, (self.source / name).read_bytes())

    def test_tool_preflight_rejects_wrong_target_or_no_loader(self):
        banner = subprocess.CompletedProcess([], 0, b'ionCube PHP Encoder Version test', b'')
        for version in (b'8.3|1', b'8.4|0'):
            with self.subTest(version=version), patch.object(release.subprocess, 'run', side_effect=[
                banner, subprocess.CompletedProcess([], 0, version, b''),
            ]), self.assertRaisesRegex(release.BuildError, 'PHP 8.4 with ionCube Loader'):
                release.check_tools(['/test/encoder'], '/test/php', '8.4')

    def test_encoding_zip_keeps_assets_and_never_includes_probe_or_source(self):
        self.source.mkdir()
        (self.source / 'index.php').write_bytes(b'<?php echo "synthetic source";')
        (self.source / 'theme.css').write_bytes(b'body { color: red; }\r\n')
        files = ['index.php', 'theme.css']
        fake_payload = b'<?php // synthetic test ionCube Loader stub; not executable bytecode'

        def run(command, **kwargs):
            if command[0] == '/test/encoder':
                source, output = Path(command[-3]), Path(command[-1])
                self.assertEqual({'index.php', release.PROBE_NAME}, {p.name for p in source.iterdir()})
                output.mkdir()
                for name in ('index.php', release.PROBE_NAME):
                    (output / name).write_bytes(fake_payload)
                return subprocess.CompletedProcess(command, 0)
            self.assertEqual(Path(command[-1]).name, release.PROBE_NAME)
            return subprocess.CompletedProcess(command, 0, release.PROBE_OK, b'')

        with patch.object(release.subprocess, 'run', side_effect=run):
            encoded = release.encode_php(self.source, files, self.scratch, ['/test/encoder'], '/test/php')
        output = self.scratch / 'synthetic-test-only.zip'
        release.write_zip(self.source, encoded, files, output)
        with zipfile.ZipFile(output) as archive:
            self.assertEqual(files, archive.namelist())
            self.assertEqual(fake_payload, archive.read('index.php'))
            self.assertEqual((self.source / 'theme.css').read_bytes(), archive.read('theme.css'))
        with self.assertRaises(FileExistsError):
            release.write_zip(self.source, encoded, files, output)

    def test_encoding_refuses_copied_php(self):
        self.source.mkdir()
        (self.source / 'index.php').write_bytes(b'<?php /* source copied by mistake */')

        def run(command, **kwargs):
            source, output = Path(command[-3]), Path(command[-1])
            output.mkdir()
            for file in source.iterdir():
                (output / file.name).write_bytes(file.read_bytes())
            return subprocess.CompletedProcess(command, 0)

        with patch.object(release.subprocess, 'run', side_effect=run), self.assertRaisesRegex(release.BuildError, 'refusing the ZIP'):
            release.encode_php(self.source, ['index.php'], self.scratch, ['/test/encoder'], '/test/php')
        self.assertEqual([], list(self.scratch.glob('*.zip')))


    def test_encoding_refuses_a_failed_loader_probe(self):
        self.source.mkdir()
        (self.source / 'index.php').write_bytes(b'<?php /* fixture */')

        def run(command, **kwargs):
            if command[0] == '/test/encoder':
                output = Path(command[-1])
                output.mkdir()
                for name in ('index.php', release.PROBE_NAME):
                    (output / name).write_bytes(b'<?php // synthetic ionCube Loader stub')
                return subprocess.CompletedProcess(command, 0)
            return subprocess.CompletedProcess(command, 2, release.PROBE_OK, b'')

        with patch.object(release.subprocess, 'run', side_effect=run):
            with self.assertRaisesRegex(release.BuildError, 'smoke test'):
                release.encode_php(self.source, ['index.php'], self.scratch, ['/test/encoder'], '/test/php')
        self.assertEqual([], list(self.scratch.glob('*.zip')))


if __name__ == '__main__':
    unittest.main()
