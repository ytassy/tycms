#!/usr/bin/env python3
"""Construit le catalogue local depuis deux instantanés publics officiels, sans clé.

Télécharger https://fonts.google.com/metadata/fonts et
https://api.github.com/repos/google/fonts/git/trees/main?recursive=1,
puis passer leurs chemins à ce script. Aucun téléchargement au runtime public.
"""
import json
import re
import sys
from pathlib import Path

metadata = json.loads(Path(sys.argv[1]).read_text())
tree = json.loads(Path(sys.argv[2]).read_text())
if tree.get('truncated') or not re.fullmatch(r'[a-f0-9]{40}', tree['sha']):
    raise ValueError('Arbre Git incomplet ou révision invalide')

# Les licences sont rattachées à une révision immuable du dépôt officiel.
packages = {}
for item in tree['tree']:
    parts = item['path'].split('/')
    if len(parts) == 3 and parts[0] in ('ofl', 'apache', 'ufl'):
        package = packages.setdefault(parts[1], {'path': '/'.join(parts[:2]), 'files': []})
        if parts[2] in ('OFL.txt', 'LICENSE.txt', 'LICENCE.txt', 'NOTICE.txt', 'NOTICE'):
            package['files'].append({'name': parts[2], 'sha': item['sha']})

families = {}
for family in metadata['familyMetadataList']:
    slug = re.sub('[^a-z0-9]', '', family['family'].lower())
    package = packages.get(slug)
    if not package or not package['files'] or not family.get('isOpenSource'):
        continue
    if not any(file['name'] in ('OFL.txt', 'LICENSE.txt', 'LICENCE.txt') for file in package['files']):
        continue
    if not re.fullmatch(r"[A-Za-z0-9 &'().+/-]+", family['family']):
        continue
    variants = sorted(key for key in family['fonts'] if re.fullmatch(r'[1-9]00i?', key))
    if not variants:
        continue
    families['google_' + slug] = {
        'family': family['family'], 'category': family['category'],
        'variants': variants, 'path': package['path'],
        'licenses': sorted(package['files'], key=lambda file: file['name']),
    }

result = {'revision': tree['sha'], 'families': dict(sorted(families.items()))}
target = Path(__file__).resolve().parents[1] / 'releases/vdev/core_extensions/Core/Resources/Fonts/catalog.json'
target.write_text(json.dumps(result, ensure_ascii=False, separators=(',', ':')) + '\n')
print(f'{len(families)} familles écrites dans {target}')
