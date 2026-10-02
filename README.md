# Sentinel

**An AI sysadmin that watches your Linux servers over SSH, and cannot run anything you didn't allow.**

Sentinel connects to your machines over SSH, runs regular security and health audits with an LLM agent, reports what it finds, and can fix routine problems (restart a service, unban an IP, update a package) through a risk gate that keeps a human in charge of everything that matters.

It is built with Laravel 13, the Laravel AI SDK, Livewire 4, Alpine and Tailwind for the dashboard.

---

## Features

- **Scheduled audits.** Each machine is scanned on its own schedule. The agent checks services, logs, fail2ban, pending updates and more, then submits a verdict (severity + report).
- **Web server check.** A second kind of scan, scheduled on its own (down to every 15 minutes). The agent finds the web stack on the machine (nginx or apache, php-fpm, mysql/mariadb/postgresql, redis, queues...), tests each component (config tests, database pings, a loopback HTTP probe, error logs) and repairs what it can, each repair going through the risk gate. See below.
- **Machine memory.** Free-form notes per machine ("shop: nginx, php-fpm, mysql, redis; backups at 03:00"). The agent reads them in every scan and chat, so it knows what *should* run. Context only: notes never lift a refusal and never replace the risk gate.
- **Live dashboard.** Follow a scan as it happens (report and every command, step by step), see what waits for approval, and watch the activity feed update itself.
- **Chat with a machine.** Ask the agent questions about a server in plain language.
- **Act on a scan.** Reply to a finished scan (or press "Fix what you found") and the agent follows up on its own report. Fixes it recommends are filed as approvable actions, listed on the scan page.
- **Corrective actions with a risk gate.**
  - **Low risk**: may run on its own if autonomy is enabled for the machine *and* a second classifier model agrees.
  - **Medium risk**: always waits for human approval.
  - **High risk**: never executed by the agent.
- **Approvals anywhere.** Pending actions can be approved or rejected in the dashboard, by mail, from Telegram buttons, or with `php artisan sentinel:pending`.
- **Full audit trail.** Every command, its output and every decision is logged.
- **Issues across scans.** Each problem a scan finds is tracked by key: new, worse, still open or resolved by a later scan. The Issues page lets you acknowledge, mute (7/30/90 days), mark fixed or reopen them, each scan page shows what changed since the previous one, and a scan that only repeats known issues below high is not notified again.
- **Health metrics and trends.** Disk, inodes, memory, swap, load and pending (security) updates are sampled every 15 minutes with one read-only command. The machine page shows sparklines; a filesystem nearly full or due to fill within a week raises an alert.
- **Fleet view and weekly digest.** The Fleet page lists every machine worst first (open issues, health, updates, reboots); `sentinel:digest` mails the weekly summary every Monday.
- **Checked actions.** Service, web configuration, SSH hardening, package and kernel actions are followed by a read-only check that they had their effect. A failed check alerts you, and a broken web configuration reload is rolled back at once.
- **Maintenance windows.** A weekly window per machine: "Approve for the window" runs a pending action at its start. Planned work (1/4/12 h) and the window itself mute scan and machine alerts.
- **Roles and two-factor authentication.** Viewer, approver or admin; TOTP is required at the first login (with recovery codes). A machine can require two different people to approve its actions.
- **Budgets and costs.** A monthly budget overall and per machine (alerts at 80% and 100%, routine AI scans pause past it), and a Costs page by machine, kind of run and model. Scheduled audits skip the AI call when the machine state has not changed since the last AI audit, and a serious verdict from the cheaper scheduled model is checked again by the main one.

## Security model

Sentinel is designed so that a confused or manipulated model can't damage a server:

- **No raw shell.** The agent can only call named tools from a fixed catalog (`app/Ssh/ToolCatalog.php`, `app/Ssh/ActionCatalog.php`). Arguments are validated, and output size and runtime are bounded.
- **Least-privilege remote user.** Provisioning creates a user with no password, a restricted SSH key and a `sudoers` policy that only allows the exact commands Sentinel needs.
- **Source IP pinning.** The deployed key can be restricted to Sentinel's IPs (`SENTINEL_SOURCE_IPS`, IPv4/IPv6/CIDR).
- **Host key pinning.** Sentinel refuses to connect when the server's host key doesn't match the pinned fingerprint. With the one-line provisioning, the machine reports its own fingerprints over TLS and Sentinel pins the one it sees, so there is nothing to copy by hand.
- **Validated updates.** Wrappers and sudo policy can be updated over SSH (human-triggered only). The root-owned updater on the machine only accepts a fixed set of command shapes; anything wider needs the provisioning command run again as root.
- **Tool output is untrusted.** Tool output is never treated as instructions. A scan's verdict can't be lower than the worst finding it recorded, and `tests/Feature/PromptInjectionTest.php` checks that an agent that obeys a hostile log still cannot get past the catalogs and the gate.
- **Deterministic rules come first.** The model can escalate an action's risk, but it can never unlock one.
- **Revocation.** `sentinel:revoke` disables a machine immediately. `--purge` removes the remote user and files.

## Requirements

- PHP 8.4+ with Composer
- Node.js + npm (to build frontend assets)
- A database (SQLite works out of the box; MySQL/PostgreSQL are supported)
- An API key for an LLM provider (OpenAI, OpenRouter, or any provider from `config/ai.php`)
- Target servers: Linux with systemd and SSH access as root once, to provision

## Installation

```bash
git clone git@github.com:PatrickePatate/sentinel.git
cd sentinel
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan sentinel:admin you@example.com --name="Your Name"
```

Then run the app, a queue worker and the scheduler:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work   # in production: cron `* * * * * php artisan schedule:run`
```

The dashboard is at `/login` (sign in with the admin you created). Set `APP_URL` to the address machines and browsers reach Sentinel at.

## Configuration

The main `.env` settings (see `.env.example` for the full list):

| Variable | Purpose |
| --- | --- |
| `SENTINEL_AI_PROVIDER` / `SENTINEL_AI_MODEL` | Model used by the agent |
| `SENTINEL_SCHEDULED_PROVIDER` / `SENTINEL_SCHEDULED_MODEL` | Optional cheaper model for scheduled scans (set both) |
| `SENTINEL_GATE_PROVIDER` / `SENTINEL_GATE_MODEL` | Classifier model used by the risk gate |
| `OPENAI_API_KEY`, `OPENROUTER_API_KEY` | Provider credentials |
| `SENTINEL_TRANSPORT` | `fake` for local development, set to real SSH in production |
| `SENTINEL_SOURCE_IPS` | Public IPs Sentinel connects from (restricts the key, whitelisted in fail2ban) |
| `SENTINEL_USE_SUDO` | Run privileged commands through `sudo` |
| `SENTINEL_RESTARTABLE_SERVICES` | Services the agent may restart |
| `SENTINEL_RELOADABLE_SERVICES` | Services the agent may reload |
| `SENTINEL_UPGRADABLE_PACKAGES` | Packages the agent may upgrade |
| `SENTINEL_MONTHLY_BUDGET` | Monthly model budget in USD for all machines (empty: none) |
| `SENTINEL_ESCALATE_SEVERITY` | Verdict of the cheaper scheduled model that the main model checks again (`high` by default, empty: never) |
| `SENTINEL_SKIP_UNCHANGED` | Skip the AI in scheduled audits when the machine state is unchanged (default on) |
| `SENTINEL_NOTIFY_ONLY_CHANGES` | Do not notify scans that only repeat known issues below high (default on) |
| `SENTINEL_METRICS_INTERVAL` | Minutes between health samples (default 15) |
| `SENTINEL_VERIFY_DELAY` | Seconds to wait before checking that an action worked (default 3) |
| `SENTINEL_REQUIRE_2FA` | Require two-factor authentication for every dashboard user (default on) |

**Notifications:** mail uses the usual `MAIL_*` settings. Telegram needs a public HTTPS `APP_URL`. Register the webhook from the channel in the back office, or with `php artisan sentinel:telegram-webhook <channel-id>`.

## Adding a machine

1. **Add it** in the dashboard (Machines → Add machine): name, address (IPv4, IPv6 or hostname) and port.
2. **Provision it with one command.** On the machine's *Provisioning* tab, issue a link and run the command it shows, as root, on the server:
   ```bash
   curl -fsSL 'https://sentinel.example.com/provision/<id>/<token>' | sudo bash
   ```
   The link is valid for one hour and is bound to this machine. The script creates the restricted user, installs the key and the client bundle, then reports the server's host key fingerprints to Sentinel, which pins the matching one and checks that it can log in. The machine must be able to reach `APP_URL`; if it can't, pin the host key from the dashboard instead (compare with the fingerprint the script prints).
   From a terminal: `php artisan sentinel:provision <machine-id> --one-liner`. To review a script before running it: `php artisan sentinel:provision <machine-id> --output=provision.sh`.
3. **Scan it.** Run a first scan from the machine page, or `php artisan sentinel:scan <machine-id>`. `php artisan sentinel:check <machine-id>` diagnoses SSH problems step by step.

### Web server check

The web server analysis is **off by default** and enabled per machine: switch on "Web server analysis" in the machine settings. Until then nothing of it runs: no schedule, no manual "Web server check" in the scan dialog, no scan triggered by a down site. Once on, you set two frequencies: the **quick check** (every 5 minutes to daily, separate from the security audit schedule) and how often the **AI** looks too when the quick check is healthy (every hour to every day, or only when the quick check finds a problem). Per component, the agent can:

| Situation | Action | Risk |
| --- | --- | --- |
| A web stack unit is failed or stopped | `start_crashed_service`: only starts a unit that is down, never touches a running one, and refuses a web server whose config test fails | Low |
| A config change is on disk but not applied | `reload_web_config`: tests first, graceful reload only if the test passes | Low |
| The web server config fails its own test | `rollback_web_config`: restores the last configuration that passed a test (the broken one is kept next to it), then reloads | Low |

"Low" means the gate may run it on its own when autonomy is enabled for the machine *and* the classifier model is confident; otherwise it waits for approval like any action. The root wrappers (`sentinel-service-recover`, `sentinel-web-config`) enforce the guarantees above themselves, and tell the classifier about them. Rollback needs a "last known good" copy: every passing config test (a scan, or `reload_web_config`) records one, so schedule the check before anything breaks. Extra units can be made recoverable with `SENTINEL_RECOVERABLE_SERVICES` (comma separated, shell globs). Machines provisioned earlier get the new wrappers with a client update.

#### Keeping the web server check cheap and trustworthy

- **Plain check first.** A scheduled web server check starts with plain commands (units, config tests, a loopback HTTP probe) and only calls the model when something is wrong, plus a full AI check at the frequency you chose per machine (6 hours by default; `SENTINEL_PRECHECK=false` makes every scheduled check an AI check). Each plain check also records the root filesystem usage, so the machine page shows a disk trend and the agent sees it (`machine_history`).
- **Flapping.** The same command running 3 times in 24 hours is no longer run unattended: it waits for a human with the reason "flapping" (`sentinel.gate.flap_threshold`).
- **Urgent alerts.** A scheduled (or site-triggered) web server check that ends with severity high or critical reaches every channel that takes scan notifications, whatever its minimum severity.
- **Always allow.** On the approvals page, "Approve and always allow" lets the agent run that action on that machine without asking again. Only for actions whose wrapper enforces its own safeguards (the three web actions); the classifier is skipped, but the per-scan quota and the flapping guard still apply. Revoke on the machine page.
- **Per-machine gate tuning.** The machine form can tighten (and, within hard bounds, loosen) the gate thresholds and the autonomous actions per scan.

### Public sites and memory suggestions

List a machine's public URLs in its settings. `sentinel:check-sites` (every five minutes) checks status, speed and certificate expiry from Sentinel itself, alerts once after 2 failures (and when the site is back), warns 14 days before a certificate expires, and, when a site stays down, starts a web server check on that machine (`SENTINEL_SCAN_ON_SITE_DOWN=false` to only alert). The agent can also suggest memory notes (facts it learned about a machine); they wait on the machine page until you add or dismiss them.

### Realtime (WebSockets)

With Laravel Reverb the server pushes "something changed" to the dashboard, which re-renders at once; the polling stays as a slow safety net. Set `SENTINEL_REALTIME=true` and the `REVERB_*` / `VITE_REVERB_*` variables (see `.env.example`), run `php artisan reverb:start` next to the web server, and rebuild the assets (a running Vite or `artisan serve` process must be restarted to see new variables). Without it, the dashboard polls every few seconds. Behind a reverse proxy, proxy the WebSocket port and set `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` to the public address.

### Updating machines

When the wrappers, limits or sudo rules change in Sentinel (a new release, a changed `.env` list), machines keep running the previous *client bundle* until you update them. The dashboard shows who is outdated; update from the machine page, from the machines list ("Update clients"), or:

```bash
php artisan sentinel:update --all        # or: sentinel:update <machine-id>, --check to only look
```

Updates go over the existing SSH access, through a root-owned updater installed at provisioning time. It validates what it installs: only wrapper scripts named `sentinel-*` and sudo rules of the known shapes are accepted. It cannot replace itself, so a change to the updater (rare) needs the provisioning command run again.

## Artisan commands

| Command | Description |
| --- | --- |
| `sentinel:admin {email}` | Create a user (`--role=viewer\|approver\|admin`, admin by default) |
| `sentinel:provision {machine}` | Print or write the provisioning script (`--sudoers` for the policy only, `--one-liner` for the `curl \| sudo bash` command) |
| `sentinel:update {machine?}` | Check or update the client bundle on machines (`--all`, `--check`) |
| `sentinel:pin-host-key {machine}` | Pin the server's SSH host key by hand (the one-liner does it for you) |
| `sentinel:check {machine}` | Diagnose SSH and authentication problems |
| `sentinel:scan {machine}` | Run a scan now (`--objective`, `--provider`, `--model`) |
| `sentinel:check-sites` | Check the public sites of all machines (scheduled every five minutes) |
| `sentinel:scan-due` | Run the scans that are due (scheduled every minute) |
| `sentinel:pending {id?}` | Review, approve or `--reject` pending actions |
| `sentinel:revoke {machine}` | Revoke access (`--purge` to clean the server, `--lift` to undo) |
| `sentinel:telegram-webhook {channel}` | Register a Telegram webhook |
| `sentinel:collect-metrics {machine?}` | Sample health metrics and alert on trends (scheduled every 15 minutes) |
| `sentinel:run-scheduled-actions` | Run actions approved for a maintenance window that has started (scheduled every minute) |
| `sentinel:digest` | Send the fleet digest (scheduled Mondays at 08:00) |

## Development

```bash
php artisan test                                  # Pest test suite
vendor/bin/pint                                   # code style
vendor/bin/phpstan analyse --memory-limit=1G      # Larastan (level 5, with a baseline)
```

Set `SENTINEL_TRANSPORT=fake` to develop without real servers.

## Contributing

Issues and pull requests are welcome. For anything touching the security model (tool catalog, risk gate, provisioning), please open an issue first to discuss it.

If you find a security vulnerability, **please don't open a public issue**. Report it privately via GitHub's *Report a vulnerability* button on the repository.

## License

Sentinel is released under the [PolyForm Noncommercial License 1.0.0](LICENSE.md). You are free to use, study, modify and share it for any **non-commercial** purpose. Commercial use requires a separate licence from the author.
