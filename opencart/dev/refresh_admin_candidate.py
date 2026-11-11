"""Refresh an existing isolated dev runtime from the exact candidate archive, without DB writes."""
import argparse
import hashlib
import json
from pathlib import Path
from zipfile import ZipFile
from runtime import LocalOpenCart, build

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--runtime-dir', type=Path, required=True)
parser.add_argument('--port', type=int, required=True)
parser.add_argument('--upstream', type=Path, required=True)
args = parser.parse_args()
runtime = LocalOpenCart(args.runtime_dir, args.port)
if not (runtime.directory / 'admin-password').is_file():
    parser.error('An already installed isolated runtime is required')
archive = build(args.upstream.resolve(), runtime.directory, extension_only=True)
script = r'''
$zip=new ZipArchive(); if($zip->open('/runtime/cmteh.ocmod.zip')!==true) throw new RuntimeException('Candidate missing');
$root='/var/www/html/extension/cmteh/';
for($i=0;$i<$zip->numFiles;$i++) {
    $name=$zip->getNameIndex($i);
    if(str_contains($name,'..') || str_starts_with($name,'/') || str_contains($name,'\\')) throw new RuntimeException('Unsafe archive path');
    $path=$root.$name;
    if(!is_dir(dirname($path))) mkdir(dirname($path),0755,true);
    $bytes=$zip->getFromIndex($i); if(file_put_contents($path,$bytes)!==strlen($bytes)) throw new RuntimeException('Incomplete refresh');
    if(!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path))) throw new RuntimeException('Installed candidate mismatch');
}
require '/var/www/html/config.php';
$cache=DIR_CACHE.'template';
if(is_dir($cache)) foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache,FilesystemIterator::SKIP_DOTS)) as $file) {
    if($file->isFile() && !$file->isLink() && $file->getExtension()==='php' && str_contains(file_get_contents($file->getPathname()),'return "extension/cmteh/')) {
        if(!unlink($file->getPathname())) throw new RuntimeException('Own template cache invalidation failed');
    }
}
echo json_encode(['installed_files'=>$zip->numFiles,'archive_sha256'=>hash_file('sha256','/runtime/cmteh.ocmod.zip')]);
'''
result = json.loads(runtime.docker('exec','-T','web','php','-r',script,capture=True).stdout)
expected = hashlib.sha256(archive.read_bytes()).hexdigest()
if result['archive_sha256'] != expected:
    raise RuntimeError('Runtime archive differs from candidate')
with ZipFile(archive) as package:
    if result['installed_files'] != len(package.infolist()):
        raise RuntimeError('Candidate entry count differs')
print(json.dumps({'state':'refreshed-without-db-writes','extension_sha256':expected,
                  'installed_files':result['installed_files'],'origin':runtime.origin}))
