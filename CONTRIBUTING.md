# Contributing to EstrategiaWeb Toolkit

Who works on this plugin (23-11-2026): **Juan** (GitHub
`SoporteEstrategiaWeb`), who takes over the development of the plugin and of
the sites' theme, and **David** (`dpereztomas`, owner). Both publish.

Everything here is the short version. The detail lives where it is used:
the README («How a change reaches every site», «How to maintain»), the header
of [tools/wporg.mjs](tools/wporg.mjs) and the header of each module.

## The usual process, from an incident to every site

A change is born from ONE site: an incident, or a request for a feature.

1. **Branch and pull request.** Nobody pushes to `main`: it requires a pull
   request, with NO approval needed (David, 23-11-2026), so whoever opens it
   merges it. (GitHub lets the admin push straight to `main`; we do not, so
   that every change has its pull request saying where it was checked.)
   Branch names: `modulo/<name>` for a module, `fix/<what>`, `docs/<what>`.
   The trap: GitHub never lets you APPROVE your own pull request, but you
   can always MERGE it when the rules allow; with one required approval,
   each of us would be blocked waiting for the other.
2. **Tests, level 1.** `node tests/run.mjs --nivel 1` (PHP without WordPress,
   seconds). A new test is seen in red once (break the code on purpose, watch
   it fail, put it back) and the test's header says how.
3. **Try it on the site that asked for it.** Copy the COMMITTED files into
   that site's plugin folder (`wp-content/plugins/estrategiaweb-toolkit/`),
   never a working tree with loose changes:

       git archive <commit> modules/<name> | ssh <site> 'cd <plugin folder> && tar -x'

   Run again the real case that showed the problem, and check it on the real
   site (not only the tests: the first run on the real site is the test).
   The hand copy is overwritten by the site's next WordPress.org update, so
   publish soon after.
4. **The pull request says where and how it was checked** (the template
   asks for it), and it carries the version: the number in the three places
   (`Version:` in estrategia-web.php, `ESTRATEGIA_WEB_VERSION`, `Stable tag:`
   in readme.txt), the entry in CHANGELOG.md and the one in readme.txt.
   Version rule: README, «Versions».
5. **Merge.** Whoever opened it merges it. A second look is welcome on
   something risky (asked for in the pull request), not required. The real
   brake will be the level 1 tests on GitHub Actions (TODO.md): when they
   exist, a red one will block the merge.
6. **Publish on WordPress.org by creating a release** (OK from David or Juan).
   Merging only accumulates in `main`; the release publishes. To prepare a
   version: an issue «Publicar vX.Y.Z» with the `claude` label opens the
   version pull request (the number in the three places, CHANGELOG, readme).
   Once merged, create the release with tag `vX.Y.Z` on GitHub: the workflow
   `publicar.yml` checks the tag against `Version:` and `Stable tag:`, runs the
   level 1 tests and Plugin Check, publishes `trunk` and `tags/X.Y.Z` with
   `10up/action-wordpress-plugin-deploy` (assets of `.wordpress-org/` if it
   exists) and attaches the zip to the release. It needs the secrets
   `SVN_USERNAME` and `SVN_PASSWORD` (until they exist it fails with a clear
   message and does nothing). Dry run: Actions → «Publicar en WordPress.org» →
   Run workflow with a version: it lists what it would upload, uploads nothing.
   What travels is decided by `.gitattributes` (`export-ignore`), so there is
   no `.distignore`. The manual way, if the workflow is down:

       node tools/wporg.mjs check     0 errors; read the warnings

   then SVN: `trunk/` = the zip's contents, and copy `trunk` to
   `tags/<version>/`. Header of [tools/wporg.mjs](tools/wporg.mjs).
   It then follows its own course: nothing is forced on any site.
7. **The NEXT DAY, update the fleet from MainWP**
   (https://reports.estrategiaweb.es). Not the same day: WordPress.org's
   update service offers the version later than the plugin page, and until
   then MainWP does not see it. In MainWP, updating plugins on a site
   updates EVERY pending plugin there, not only this one: look first.

A change with nothing site-specific to try (a text, a translation, a fix
asked by the WordPress.org review) goes to whichever site uses the touched
module, or straight to the release if none does; the pull request says so.

## Access each person needs

- **GitHub**: write access to `estrategia-web-es/estrategia-web-wp-plugin`.
- **WordPress.org SVN**, to publish: your own WordPress.org account, added by
  the owner as a committer of the plugin (plugin page → Advanced → Committers),
  and your own SVN password, which is NOT your WordPress.org password: it is
  created at https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password
  It goes in `secretos/svn.txt` (`user=`, `pass=`; `secretos/` is in
  .gitignore) and never in a command line, a commit or a chat.
- **The site of the incident**: SSH or the hosting panel, to copy the files
  and run WP-CLI.
- **MainWP**: an account on https://reports.estrategiaweb.es.

## How this file is maintained

Whoever changes the process changes this file in the same pull request, and
the README section it points to. The edge case that taught the rule:
`cache-purge` (2.13.0, 23-11-2026) was born on Gastronòmicament, tried there
first, published, and the fleet waited for MainWP. The trap: on that day
WordPress.org's plugin page said 2.13.0 while its update service still said
2.12.1, so the sites saw nothing new; it is why step 7 is the next day.
