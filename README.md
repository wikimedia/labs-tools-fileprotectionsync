# FileProtectionSyncBot

**FileProtectionSyncBot** applies upload protection to files on Wikimedia Commons that are used on sensitive pages on other wikis. This prevents vandalism of these highly-visible images.

The bot does this by extending the "cascading" page protection feature of MediaWiki core. In MediaWiki, one can protect a page with with "cascading protection", which automatically protects all images and templates embedded by that page, e.g. the front page of Wikipedia. However, This feature is limited to within the same wiki, whereas in practice the majority of media files are embedded from the central repository, Wikimedia Commons. FileProtectionSyncBot maintains a mirror page on commons.wikimedia.org, which a Commons administrator needs to protect only once (with "cascading" turned on). The bot then regularly edits this one page to display the same images as currently used by the protected pages on the other wikis, thus naturally extending the cascading protection from the wiki's Main Page to the files they currently use from Wikimedia Commons.

* [Commons:Auto-protected files](https://commons.wikimedia.org/wiki/Commons:Auto-protected_files)
* [User:KrinkleBot](https://commons.wikimedia.org/wiki/User:KrinkleBot)

## Local development

* Clone code, `git clone https://gerrit.wikimedia.org/r/labs/tools/fileprotectionsync`
* Configure user, <https://www.mediawiki.org/wiki/Quickstart>
   ```bash
   $ edit auth.json
   {
     "apipath": "http://localhost:4000/api.php",
     "loginname": "Admin",
     "password": "*******"
   }
   ```
* Run `bin/fileprotectionsync`

## Toolforge installation

1. Fetch code:
   ```bash
   ssh tools-login
   you$ become krinklebot
   krinklebot$ mkdir -p ~/src && cd ~/src
   krinklebot:src$ git clone https://gerrit.wikimedia.org/r/labs/tools/fileprotectionsync
   ```
2. Configure user:
   <https://commons.wikimedia.org/wiki/Special:BotPasswords>
   * High-volume access.
   * Edit existing pages.
   * Edit protected pages.
   * Create, edit, and move pages.

   ```bash
   $ touch ~/.fileprotectionsync.auth.json && chmod 600 ~/.fileprotectionsync.auth.json
   $ edit ~/.fileprotectionsync.auth.json
   {
     "apipath": "https://commons.wikimedia.org/w/api.php",
     "loginname": "KrinkleBot@MY_BOTPASSWORD_SUFFIX",
     "password": "MY_BOTPASSWORD_SECRET"
   }
   ```
3. Load jobs. This will run the bot **every 10 minutes**.
   ```bash
   # (tooluser in ~/src/fileprotectionsync)
   $ toolforge jobs load jobs.yaml
   ```

## Toolforge operation

### Deployment

```
ssh tools-login
you$ become krinklebot
krinklebot$ cd src/fileprotectionsync
fileprotectionsync:krinklebot$ git pull
```

### Run manually

```bash
$ toolforge jobs restart fileprotectionsync
```