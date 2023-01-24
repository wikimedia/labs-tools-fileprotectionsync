# pywiki-fileprotectionsync

## Production

* [User:KrinkleBot](https://commons.wikimedia.org/wiki/User:KrinkleBot)
* [Commons:Auto-protected files](https://commons.wikimedia.org/wiki/Commons:Auto-protected_files)


## Setup

Fetch code:

```bash
# (tooluser in ~/src)
$ git clone --depth 1 --recursive --branch stable https://gerrit.wikimedia.org/r/pywikibot/core pywikibot-core
$ git clone https://gerrit.wikimedia.org/r/labs/tools/fileprotectionsync
```

Configure user:

```bash
$ mkdir -p ~/.pywikibot && chmod 700 ~/.pywikibot
$ touch ~/.pywikibot/{.pwd,user-config.py} && chmod 600 ~/.pywikibot/{.pwd,user-config.py}
$ edit ~/.pywikibot/user-config.py
	# -*- coding: utf-8  -*-
	import os
	family = 'commons'
	mylang = 'commons'
	usernames['commons']['commons'] = u'KrinkleBot'
	sysopnames['commons']['commons'] = u'KrinkleBot'
	password_file = os.path.expanduser('~/.pywikibot/.pwd')
$ edit ~/.pywikibot/.pwd
("<username>", BotPassword("<botname>", "<password>"))
```

Install pywikibot:

```bash
# (tooluser in ~/)
$ virtualenv pywikienv -p python3
$ source ~/pywikienv/bin/activate
$ pip install pyyaml mwparserfromhell
$ cd ~/src/pywikibot-core
$ python setup.py develop
```

Configure fileprotectionsync:

```bash
# (you in ~/src/fileprotectionsync)
$ ln -sf fileprotectionsync_config-prod.py fileprotectionsync_config.py

# (you in ~/)
$ edit crontab.txt
0,15,30,45 * * * * /usr/bin/jsub -once -quiet -l release=trusty -mem 500m -N fileprotectionsync $HOME/pywikienv/bin/python $HOME/src/fileprotectionsync/fileprotectionsync.py
```

To run it manually:

```bash
$ $HOME/pywikienv/bin/python $HOME/src/fileprotectionsync/fileprotectionsync.py
```
