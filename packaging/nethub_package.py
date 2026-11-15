"""Compare a NetHub candidate package with pinned source and reviewed build bytes."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import stat
import struct
import tempfile


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


HERE = Path(__file__).resolve().parent
source = load_module('nethub_package_source', HERE / 'nethub_source.py')
verify = load_module('nethub_package_verifier', HERE.parent / 'verify-repo.py')
MAX_FILE_BYTES = 64 * 1024 * 1024
MAX_BUILD_BYTES = 128 * 1024 * 1024
MAX_PACKAGE_BYTES = 256 * 1024 * 1024
RECORD_FIELDS = {'schema_version', 'source_commit', 'source_archive_sha256', 'abi',
                 'compiler', 'frp_version', 'build_input_sha256', 'generated_files'}
REQUIRED_OUTPUTS = {'frpc', 'FRP_BUILD.json', 'FRP_VERSION', 'FRP_LICENSE',
                    'frpc-licenses/GO-LICENSE', 'frpc-licenses/modules.txt'}
EXECUTABLE_SOURCE = {'/usr/local/etc/rc.d/nethub_agent_frpc',
    '/usr/local/etc/rc.syshook.d/early/14-nethub-agent-trust',
    '/usr/local/etc/rc.syshook.d/early/15-nethub-agent-certificate',
    '/usr/local/etc/rc.syshook.d/start/20-nethub-agent'}


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def read_pinned_record(path, sha256):
    if not isinstance(sha256, str) or not re.fullmatch('[0-9a-f]{64}', sha256):
        raise ValueError('A reviewed build-record SHA256 is required.')
    path = Path(path)
    if path.is_symlink() or not path.is_file():
        raise ValueError('Build record must be a regular file.')
    with path.open('rb') as stream:
        raw = stream.read(2 * 1024 * 1024 + 1)
    if len(raw) > 2 * 1024 * 1024 or digest(raw) != sha256:
        raise ValueError('Build record differs from its reviewed digest.')
    record = source.json_object(raw)
    if set(record) != RECORD_FIELDS or type(record['schema_version']) is not int or record['schema_version'] != 1:
        raise ValueError('Build record has an unsupported schema.')
    return record


def generated_files(directory, record, stage, lock):
    for key, expected in [('source_commit', lock['commit']), ('source_archive_sha256', lock['sha256']),
                          ('compiler', stage['compiler']), ('frp_version', stage['frp_version']),
                          ('build_input_sha256', stage['build_input_sha256'])]:
        if record[key] != expected:
            raise ValueError('Build record differs from pinned source inputs: ' + key)
    if not isinstance(record['abi'], str) or not re.fullmatch(verify.ABI_PATTERN, record['abi']):
        raise ValueError('Build record must pin a native FreeBSD amd64 ABI.')
    declared = record['generated_files']
    if not isinstance(declared, dict) or not REQUIRED_OUTPUTS <= set(declared) or len(declared) > 20000:
        raise ValueError('Build record lacks a complete generated-file inventory.')
    upstream_license = 'frpc-licenses/github.com/fatedier/frp@v' + stage['frp_version'] + '/LICENSE'
    if upstream_license not in declared:
        raise ValueError('Build record lacks the upstream frp license.')
    for name, expected in declared.items():
        source.upstream.relative_path(name)
        if name not in REQUIRED_OUTPUTS and not name.startswith('frpc-licenses/'):
            raise ValueError('Build record contains an unexpected generated output.')
        if not isinstance(expected, str) or not re.fullmatch('[0-9a-f]{64}', expected):
            raise ValueError('Build record contains an invalid generated-file digest.')
    root = Path(directory)
    if root.is_symlink() or not root.is_dir():
        raise ValueError('Generated-file cache must be a regular directory.')
    actual = {}
    total = 0
    for path in root.rglob('*'):
        mode = path.lstat().st_mode
        if stat.S_ISLNK(mode) or not (stat.S_ISDIR(mode) or stat.S_ISREG(mode)):
            raise ValueError('Generated-file cache contains a link or special entry.')
        if stat.S_ISDIR(mode):
            continue
        name = path.relative_to(root).as_posix()
        if name not in declared or path.stat().st_nlink != 1:
            raise ValueError('Generated-file cache differs from its closed inventory.')
        with path.open('rb') as stream:
            raw = stream.read(MAX_FILE_BYTES + 1)
        total += len(raw)
        if len(raw) > MAX_FILE_BYTES or total > MAX_BUILD_BYTES:
            raise ValueError('Generated-file cache exceeds its size bound.')
        if digest(raw) != declared[name]:
            raise ValueError('Generated-file cache differs from reviewed bytes: ' + name)
        actual[name] = raw
    if set(actual) != set(declared):
        raise ValueError('Generated-file cache is missing a reviewed output.')
    binary = actual['frpc']
    if (len(binary) < 20 or binary[:8] != b'\x7fELF\x02\x01\x01\x09'
            or struct.unpack('<HH', binary[16:20]) not in [(2, 62), (3, 62)]):
        raise ValueError('Reviewed frpc is not a FreeBSD amd64 ELF executable.')
    receipt = source.json_object(actual['FRP_BUILD.json'])
    expected_receipt = {'compiler': stage['compiler'], 'frp_version': stage['frp_version'],
        'target': 'freebsd_amd64', 'tags': ['frpc', 'noweb'],
        'dependency_locks': {name: stage['build_input_sha256'][source.PLUGIN + '/frpc-build/' + name]
                             for name in ['go.mod', 'go.sum']}, 'binary_sha256': digest(binary)}
    if receipt != expected_receipt or actual['FRP_VERSION'] != (stage['frp_version'] + '\n').encode():
        raise ValueError('Generated frpc receipt differs from source compiler/module/profile pins.')
    if actual['FRP_LICENSE'] != actual[upstream_license] or not actual['FRP_LICENSE'].strip() or not actual['frpc-licenses/GO-LICENSE'].strip():
        raise ValueError('Generated license inventory disagrees with the installed licenses.')
    try:
        modules = actual['frpc-licenses/modules.txt'].decode('ascii').splitlines()
    except UnicodeError as error:
        raise ValueError('Generated module license index is not canonical ASCII.') from error
    if (not modules or modules != sorted(set(modules))
            or actual['frpc-licenses/modules.txt'] != ('\n'.join(modules) + '\n').encode('ascii')
            or 'github.com/fatedier/frp@v' + stage['frp_version'] not in modules):
        raise ValueError('Generated module license index is incomplete or noncanonical.')
    for name in modules:
        source.upstream.relative_path(name)
        if '@v' not in name or not any(path.startswith('frpc-licenses/' + name + '/') for path in actual):
            raise ValueError('Generated module license index names an uncovered module.')
    for path in actual:
        if not path.startswith('frpc-licenses/') or path in REQUIRED_OUTPUTS:
            continue
        matches = [name for name in modules if path.startswith('frpc-licenses/' + name + '/')]
        if len(matches) != 1:
            raise ValueError('Generated license file is outside the module index.')
        relative = path[len('frpc-licenses/' + matches[0] + '/'):]
        if not relative.split('/')[0].lower().startswith(('license', 'licence', 'copying', 'notice', 'copyright')):
            raise ValueError('Generated module file is not a collected license or notice.')
    return {source.GENERATED + name: raw for name, raw in actual.items()}


def verify_snapshot(package, archive, lock, build_record, build_record_sha256, generated_directory):
    """Use one private package snapshot for all readers and the returned digest."""
    record = read_pinned_record(build_record, build_record_sha256)
    stage = source.reconstruct(archive, lock)
    outputs = generated_files(generated_directory, record, stage, lock)
    expected = dict(stage['files'])
    if set(expected) & set(outputs):
        raise ValueError('Generated outputs replace copied source files.')
    expected.update(outputs)
    package = Path(package)
    manifest = verify.manifest_of(package)
    verify.check_manifest_shape(package, manifest)
    substitutions = {'VERSION': stage['version'], 'ABI': record['abi'],
                     'ARCH': 'freebsd:' + record['abi'].split(':')[1] + ':x86:64'}
    text = json.dumps(stage['manifest_template'])
    for name, value in substitutions.items():
        text = text.replace('@' + name + '@', value)
    metadata = json.loads(text)
    metadata['desc'] = stage['description']
    if any(manifest.get(name) != value for name, value in metadata.items()):
        raise ValueError('Candidate package metadata differs from pinned source and ABI.')
    if set(manifest) - (set(metadata) | {'files', 'scripts', 'flatsize', 'deps'}):
        raise ValueError('Candidate package contains unreviewed manifest fields.')
    if not isinstance(manifest.get('deps', {}), dict) or manifest.get('deps', {}) != metadata.get('deps', {}):
        raise ValueError('Candidate package dependencies differ from pinned source.')
    if type(manifest.get('flatsize')) is not int or manifest['flatsize'] != sum(map(len, expected.values())):
        raise ValueError('Candidate package flat size differs from reconstructed files.')
    if not isinstance(manifest.get('files'), dict) or not isinstance(manifest.get('scripts'), dict):
        raise ValueError('Candidate package has no complete file and hook inventories.')
    if any(not isinstance(value, str) for value in manifest['scripts'].values()):
        raise ValueError('Candidate lifecycle hooks must be strings.')
    if any(not isinstance(value, str) for value in manifest['files'].values()):
        raise ValueError('Candidate package carries unreviewed per-file manifest attributes.')
    for member in verify.python_members(package):
        if not member.isfile():
            raise ValueError('NetHub candidate package must contain only regular files.')
        name = '/' + member.name.removeprefix('./').lstrip('/')
        executable = name in EXECUTABLE_SOURCE or name == source.GENERATED + 'frpc'
        if member.mode != (0o755 if executable else 0o644) or member.uid != 0 or member.gid != 0:
            raise ValueError('Candidate file permissions or ownership differ from the builder: ' + name)
    verify.verify_file_inventory(package, manifest, expected)
    verify.verify_lifecycle_hooks(manifest, stage['scripts'])
    with package.open('rb') as stream:
        package_sha256 = hashlib.file_digest(stream, 'sha256').hexdigest()
    return {'source_commit': lock['commit'], 'source_archive_sha256': lock['sha256'],
            'build_record_sha256': build_record_sha256, 'package_sha256': package_sha256,
            'files_compared': len(expected), 'hooks_compared': len(stage['scripts']),
            'matches_reviewed_inputs_and_bytes': True, 'native_lifecycle_or_release_accepted': False}


def verify_candidate(package, archive, lock, build_record, build_record_sha256, generated_directory):
    """Verify candidate bytes only; this does not authorize signing or publication.

    The build-record digest is a separate review input. Deriving that record from
    the candidate under test would destroy its independence and is not acceptance.
    A native build/lifecycle report and the owner's release approval remain gates.
    """
    original = Path(package)
    if original.is_symlink() or not original.is_file():
        raise ValueError('Candidate package must be a regular file.')
    with tempfile.TemporaryDirectory(prefix='nethub-candidate-') as temporary:
        snapshot = Path(temporary) / 'candidate.pkg'
        total = 0
        with original.open('rb') as stream, snapshot.open('xb') as output:
            while block := stream.read(65536):
                total += len(block)
                if total > MAX_PACKAGE_BYTES:
                    raise ValueError('Candidate package exceeds its size bound.')
                output.write(block)
        return verify_snapshot(snapshot, archive, lock, build_record, build_record_sha256, generated_directory)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', type=Path, required=True)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--source-lock', type=Path, required=True)
    parser.add_argument('--build-record', type=Path, required=True)
    parser.add_argument('--build-record-sha256', required=True)
    parser.add_argument('--generated-directory', type=Path, required=True)
    args = parser.parse_args()
    result = verify_candidate(args.package, args.archive, source.upstream.load_lock(args.source_lock),
                              args.build_record, args.build_record_sha256, args.generated_directory)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
