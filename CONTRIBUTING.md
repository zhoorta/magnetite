# Contributing

Magnetite is a personal project. It is shared because it may be useful, not because it is a service: issues and pull requests are welcome and read on a best-effort basis, with no promise of an answer or of a fix by a date. A small, focused change that fits the project (see `PLAN.md` and `docs/open-source.md`) has the best chance. For a larger idea, open an issue first so nobody writes code that will not be merged.

## Setup

    composer setup                 # dependencies, .env, SQLite file, migrations, assets
    php artisan magnetite:user me
    composer dev                   # web server, queue worker, Vite

`CLAUDE.md` lists the conventions that matter when changing the code: the persistent player, the visualizer contract, the streaming and upload rules, the multi-user scope.

## Before you send a change

    vendor/bin/pint --test         # style (vendor/bin/pint fixes it)
    vendor/bin/pest                # PHP tests
    npm run test:js                # meters, the library's row model and keys, themes
    npm run build                  # after changing Blade views that add Tailwind classes
    cd tui && cargo test           # only when you touch the terminal client

Add a test with a fix or a feature. The streaming, upload and sharing rules have tests that must keep passing; do not loosen them.

If you change a key, a command or a setting, update the guide (`docs/guide/`) and the help list in `resources/views/components/layouts/app.blade.php`.

## Pull requests

- One topic per pull request, a short description of what and why.
- Sign off your commits (`git commit -s`): it says you wrote the change or may pass it on under the project's licence, [AGPL-3.0-or-later](LICENSE). There is no contributor agreement.
- Plain, factual wording in the interface and the docs: no slogans.

## Reporting a bug

Say the version (or commit), how you run it (Docker, bare metal), the browser or client, what you did, what you expected and what happened. Logs help: `docker compose logs app`, or `storage/logs`. Security problems go to `SECURITY.md`, not to an issue.
