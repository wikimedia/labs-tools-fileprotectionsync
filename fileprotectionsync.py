# -*- coding: utf-8  -*-
# Script to create a gallery of files used on one or more pages on
# another wiki. Main purpose being to automatically protect files
# from a central repository that are used on a local wiki's main page.
# For this to work, the gallery page must have cascading protection as well.
#
# @author Betacommand
# @author Krinkle
# @author Legoktm
# @license CC-BY-SA 3.0
from __future__ import absolute_import, print_function
import sys
import json
try:
    from urllib.parse import urlencode
    from urllib.request import urlopen
except ImportError:
    # python2.7
    from urllib import urlencode
    from urllib2 import urlopen

import yaml

import fileprotectionsync_config as config
import pywikibot
commons_site = pywikibot.Site('commons', 'commons')


def build_wikitext(images, languages):
    videos = []
    wt = config.wikitext_start
    # alphasort and remove duplicates
    for image in sorted(set(images)):
        if image == 'War in Ukraine (2022) en.png':
            continue
        if image == 'War in Ukraine 2022 - fr.svg':
            # https://commons.wikimedia.org/w/index.php?title=User_talk:Krinkle&diff=632356246&oldid=628973063
            continue
        wt += 'File:%s\n' % image
        if image.endswith(('.ogv', '.webm', '.mpg', '.mpeg')):
            videos.append(image)
    wt += config.wikitext_end
    if videos:
        wt += """

<div class="mw-collapsible mw-collapsed">
<div style="font-weight:bold;line-height:1.6;">Subtitles</div>
<div class="mw-collapsible-content">
"""
        for video in videos:
            for language in languages:
                wt += '{{TimedText:%s.%s.srt}}\n' % (video, language)
        wt += '</div></div>'

    return wt


def main():
    languages = get_languages()
    for wiki in config.wikis:
        mpimages = []
        for pg in wiki['sourcepages']:
            mpimages.extend(get_images(wiki['sourcewiki'], pg))
        wt = build_wikitext(mpimages, languages)
        pywikibot.Page(commons_site, wiki['targetpage']).put(wt, config.editsummary)

    # Wiki logos (T273490)
    wt = build_wikitext(get_logos(), languages)
    pywikibot.Page(commons_site, 'Commons:Auto-protected files/misc/logos').put(wt, config.editsummary)


def get_images(site, title):
    # TODO: Use pywikibot's built-in API stuff instead of this
    title = urlencode({'titles': title})
    mpimages = []
    path = 'https://%s/w/api.php?action=query&prop=images&%s&imlimit=500&redirects&format=json' % (site, title)
    print(path)
    tx = urlopen(path)
    json_resp = tx.read().decode('utf-8')
    data = json.loads(json_resp)
    try:
        images = data['query']['pages'][list(data['query']['pages'].keys())[0]]['images']
    except KeyError:
        print('Error: Page "%s" not found on %s' % (title, site), file=sys.stderr)
        return mpimages
    for image in images:
        if image['ns'] == 6:
            # Extract file name (remove File namespace prefix)
            # This allows non-English wikis to be fetched into an English wiki
            # Datei:Awesome_collection:_The_Example_(2006).jpg -> Awesome_collection:_The_Example_(2006).jpg
            mpimages.append(image['title'].split(':', 1)[1])
    return mpimages


def get_languages():
    req = urlopen('https://commons.wikimedia.org/w/api.php?action=query&meta=siteinfo&siprop=languages&format=json')
    data = json.loads(req.read().decode('utf-8'))
    return [lang['code'] for lang in data['query']['languages']]


def get_logos():
    logos = []
    req = urlopen("https://noc.wikimedia.org/conf/logos-config.yaml")
    yaml_resp = req.read().decode('utf-8')
    data = yaml.safe_load(yaml_resp)
    for group, sites in data.items():
        for site, info in sites.items():
            if not info:
                continue
            if 'commons' in info:
                # Strip "File:" prefix
                logos.append(info['commons'].split(':', 1)[1])
            if 'variants' in info:
                for logo in info['variants'].values():
                    logos.append(logo.split(':', 1)[1])

    return logos


if __name__ == '__main__':
    main()
