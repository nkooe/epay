"""Verify the complete shipped PHP dependency tree; no network or PHP execution."""
import argparse
import hashlib
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
SKIP_DIRS={'.git','.github','.gitlab','tests','test','docs','examples','example','fixtures','fuzzing','dist','build','__pycache__'}
SKIP_NAMES={'.gitignore','.gitattributes','phpunit.xml','phpunit.xml.dist','phpstan.neon','phpstan.neon.dist','autoload-fast.php','autoload-pedantic.php','autoload-phpunit.php','build-phar.sh','infection.json.dist','psalm-new.xml','psalm.xml'}
def delivered(path,root):
    parts=path.relative_to(root).parts
    return not any(p in SKIP_DIRS for p in parts) and path.name not in SKIP_NAMES and path.suffix.lower() not in {'.md','.markdown','.zip','.map','.log','.bak','.pyc','.key','.pem','.p12','.pfx'}
def verify(root=ROOT,read=None,names=None):
    read=read or (lambda name:(root/name).read_bytes())
    manifest=json.loads(read('includes/dependency-integrity.json'))
    expected=manifest['sha256']
    if names is None:
        paths=list((root/'includes/vendor').rglob('*'))+list((root/'includes/dependency-patches').glob('*'))
        if any(p.is_symlink() for p in paths):raise RuntimeError('Dependency symlinks are not permitted')
        names={p.relative_to(root).as_posix() for p in paths if p.is_file() and delivered(p,root)}
        names|={'includes/composer.json','includes/composer.lock'}
    actual={n for n in names if n.startswith(('includes/vendor/','includes/dependency-patches/')) or n in {'includes/composer.json','includes/composer.lock'}}
    if actual!=set(expected):raise RuntimeError('Dependency file set differs: '+str(sorted(actual^set(expected))[:12]))
    for name,wanted in expected.items():
        if name.startswith('/') or '..' in Path(name).parts:raise RuntimeError('Invalid manifest path')
        if hashlib.sha256(read(name).replace(b'\r\n',b'\n')).hexdigest()!=wanted:raise RuntimeError('Dependency hash mismatch: '+name)
    lock=json.loads(read('includes/composer.lock'))
    identities={(p['name'],p['version'],p['source']['reference']) for p in manifest['packages']}
    if identities!={(p['name'],p['version'],p['source']['reference']) for p in lock['packages']}:raise RuntimeError('Dependency identities differ from lock')
    return {'files':len(expected),'packages':len(identities)}
if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--root',type=Path,default=ROOT);args=parser.parse_args()
    print(json.dumps(verify(args.root),ensure_ascii=False))
