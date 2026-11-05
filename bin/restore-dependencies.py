"""Install locked dependencies and apply the declared local SDK patches, then verify every delivered file."""
import argparse,hashlib,importlib.util,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--php',default='php');p.add_argument('--composer',required=True,help='Verified Composer 2.8.12 PHAR');args=p.parse_args()
    manifest=json.loads((ROOT/'includes/dependency-integrity.json').read_text(encoding='utf-8'))
    for pkg in manifest['packages']:
        if not pkg['local_patch']:continue
        name=pkg['local_patch'];actual=hashlib.sha256((ROOT/name).read_bytes().replace(b'\r\n',b'\n')).hexdigest()
        if actual!=manifest['sha256'][name]:raise RuntimeError('Patch hash mismatch: '+name)
    composer=[args.php,str(Path(args.composer).resolve())]
    version=subprocess.check_output(composer+['--version'],text=True)
    if not version.startswith('Composer version 2.8.12 '):raise RuntimeError('Use verified Composer 2.8.12 to reproduce the loader')
    subprocess.run(composer+['install','--no-dev','--no-plugins','--no-scripts','--no-interaction','--prefer-dist'],cwd=ROOT/'includes',check=True)
    for pkg in manifest['packages']:
        if not pkg['local_patch']:continue
        directory=ROOT/'includes/vendor'/pkg['name']
        complete=all((directory/c['file']).is_file() and hashlib.sha256((directory/c['file']).read_bytes().replace(b'\r\n',b'\n')).hexdigest()==c['sha256'] for c in pkg['changes'])
        if complete:continue
        subprocess.run(['git','apply','--whitespace=nowarn','--directory=includes/vendor/'+pkg['name'],str(ROOT/pkg['local_patch'])],cwd=ROOT,check=True)
    spec=importlib.util.spec_from_file_location('integrity',ROOT/'bin/verify-dependencies.py');v=importlib.util.module_from_spec(spec);spec.loader.exec_module(v);print(json.dumps(v.verify()))
if __name__=='__main__':main()
