# pywiki-fileprotectionsync

## Production

* [User:KrinkleBot](https://commons.wikimedia.org/wiki/User:KrinkleBot)
* [Commons:Auto-protected files](https://commons.wikimedia.org/wiki/Commons:Auto-protected_files)


## Setup

Fetch code:

```bash
# (tooluser in ~/src)
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

Configure fileprotectionsync:

```bash
# (you in ~/src/fileprotectionsync)
$ ln -sf fileprotectionsync_config-prod.py fileprotectionsync_config.py
```

Load jobs:

```bash
# (tooluser in ~/src/fileprotectionsync)
$ toolforge jobs load jobs.yaml
```

This may take some time, as it will wait to finish rebuildng the Python virtual environment.
To only reload the main fileprotectionsync job:

```bash
# (tooluser in ~src/fileprotectionsync)
$ toolforge jobs load jobs.yaml --job fileprotectionsync
```

To run it manually:

```bash
$ toolforge jobs restart fileprotectionsync
```

To update dependencies:

```bash
$ toolforge jobs load ~/src/fileprotectionsync/jobs.yaml --job build-venv
```
