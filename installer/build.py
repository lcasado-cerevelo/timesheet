"""
Builds dist/hosting/: instalar.php + timesheet-app.zip (what the installer uploads to a shared hosting)
+ timesheet-server.zip (the same files without the "app/" wrapper: it is extracted straight inside timesheet/server/ to update).
Every file is stored with its Unix permissions (0644): without them the cPanel extractor leaves the files at 0600 and Apache answers 403.
Needs dist/_v/vendor (composer install --no-dev, see README of the installer).
Run: python installer/build.py
"""
import os
import re
import shutil
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRV = os.path.join(ROOT, 'server')
VENDOR = os.path.join(ROOT, 'dist', '_v', 'vendor')
OUT = os.path.join(ROOT, 'dist', 'hosting')
os.makedirs(OUT, exist_ok=True)

TEXT_EXT = {'.php', '.json', '.js', '.css', '.html', '.md', '.txt', '.xml', '.svg', '.webmanifest', '.ini', '.sql', '.lock', '.yml', '.yaml', '.conf'}
SKIP_DIRS = {'tests', '.git', 'node_modules', 'vendor', 'docker', 'public'}


def skip_app(rel):
    p = rel.replace('\\', '/')
    parts = p.split('/')
    if any(x in SKIP_DIRS for x in parts[:-1]) or parts[0] in SKIP_DIRS:
        return True
    if p in ('.env', '.env.example', '.env.production.example', 'Dockerfile', 'Dockerfile.prod', '.dockerignore', 'CLAUDE.md', 'AGENTS.md') or p.endswith('.sqlite'):
        return True
    if p.startswith('storage/logs/') and not p.endswith('.gitignore'):
        return True
    if re.match(r'storage/framework/(cache|sessions|views)/.+', p) and not p.endswith('.gitignore'):
        return True
    if p.startswith('storage/app/') and not p.endswith('.gitignore'):
        return True
    if p.startswith('bootstrap/cache/') and p.endswith('.php'):
        return True
    return False


def data_of(path, rel):
    data = open(path, 'rb').read()
    if os.path.splitext(rel)[1].lower() in TEXT_EXT or os.path.basename(rel) in ('artisan', '.htaccess', '.gitignore'):
        data = data.replace(b'\r\n', b'\n')
    return data


zpath = os.path.join(OUT, 'timesheet-app.zip')
spath = os.path.join(OUT, 'timesheet-server.zip')
count = 0
with zipfile.ZipFile(zpath, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z, zipfile.ZipFile(spath, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zs:
    def put(zf, arc, data):
        info = zipfile.ZipInfo(arc)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o644 << 16   # permisos de Unix: sin esto el extractor de cPanel deja 0600 y Apache da 403
        zf.writestr(info, data)

    def add(arc, data):
        global count
        put(z, arc, data)
        put(zs, arc[4:] if arc.startswith('app/') else arc, data)   # el zip del servidor no lleva la carpeta "app/"
        count += 1

    for dp, dns, fns in os.walk(SRV):
        dns[:] = [d for d in dns if not os.path.islink(os.path.join(dp, d))]
        rel_dir = os.path.relpath(dp, SRV).replace('\\', '/')
        for fn in fns:
            full = os.path.join(dp, fn)
            rel = fn if rel_dir == '.' else rel_dir + '/' + fn
            if os.path.islink(full) or skip_app(rel):
                continue
            add('app/' + rel, data_of(full, rel))
    for dp, dns, fns in os.walk(VENDOR):
        for fn in fns:
            full = os.path.join(dp, fn)
            add('app/vendor/' + os.path.relpath(full, VENDOR).replace('\\', '/'), open(full, 'rb').read())
    pub = os.path.join(SRV, 'public')
    for dp, dns, fns in os.walk(pub):
        dns[:] = [d for d in dns if not os.path.islink(os.path.join(dp, d))]
        for fn in fns:
            full = os.path.join(dp, fn)
            rel = os.path.relpath(full, pub).replace('\\', '/')
            if os.path.islink(full) or rel in ('index.php', 'hot') or rel.startswith('storage'):
                continue
            add('public/' + rel, data_of(full, rel))

shutil.copy(os.path.join(ROOT, 'installer', 'instalar.php'), os.path.join(OUT, 'instalar.php'))
z = zipfile.ZipFile(zpath)
names = z.namelist()
print('files:', count, '| zip MB:', round(os.path.getsize(zpath) / 1e6, 1))
for n in ('app/artisan', 'app/vendor/autoload.php', 'app/bootstrap/app.php', 'public/.htaccess', 'public/app/index.html'):
    print(n, n in names)
print('no .env:', 'app/.env' not in names, '| no tests:', not any('/tests/' in n for n in names), '| public/index.php excluded:', 'public/index.php' not in names)
sz = zipfile.ZipFile(spath)
modes = {(i.external_attr >> 16) & 0o777 for i in sz.infolist()}
print('timesheet-server.zip MB:', round(os.path.getsize(spath) / 1e6, 1), '| files:', len(sz.namelist()), '| artisan at the root:', 'artisan' in sz.namelist(), '| permissions in the zip:', [oct(m) for m in modes])
