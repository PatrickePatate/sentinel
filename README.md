# Sentinel

**An AI sysadmin that watches your Linux servers over SSH, and cannot run anything you didn't allow.**

Sentinel connects to your machines over SSH, runs regular security and health audits with an LLM agent, reports what it finds, and can fix routine problems (restart a service, unban an IP, update a package) through a risk gate that keeps a human in charge of everything that matters.

It is built with Laravel 13, the Laravel AI SDK, Livewire 4 and [Sharp](https://sharp.code16.fr) for the back office.

---

## Features

- **Scheduled audits.** Each machine is scanned on its own schedule. The agent checks services, logs, fail2ban, pending updates and more, then submits a verdict (severity + report).
- **Live reports.** Follow a scan as it happens, step by step.
- **Chat with a machine.** Ask the agent questions about a server in plain language.
- **Act on a scan.** Reply to a finished scan (or press "Fix what you found") and the agent follows up on its own report. Fixes it recommends are filed as approvable actions, listed on the scan page.
- **Corrective actions with a risk gate.**
  - **Low risk**: may run on its own if autonomy is enabled for the machine *and* a second classifier model agrees.
  - **Medium risk**: always waits for human approval.
  - **High risk**: never executed by the agent.
- **Approvals anywhere.** Pending actions can be approved or rejected in the back office, by mail, from Telegram buttons, or with `php artisan sentinel:pending`.
- **Full audit trail.** Every command, its output and every decision is logged.

## Security model

Sentinel is designed so that a confused or manipulated model can't damage a server:

- **No raw shell.** The agent can only call named tools from a fixed catalog (`app/Ssh/ToolCatalog.php`, `app/Ssh/ActionCatalog.php`). Arguments are validated, and output size and runtime are bounded.
- **Least-privilege remote user.** Provisioning creates a user with no password, a restricted SSH key and a `sudoers` policy that only allows the exact commands Sentinel needs.
- **Source IP pinning.** The deployed key can be restricted to Sentinel's IPs (`SENTINEL_SOURCE_IPS`, IPv4/IPv6/CIDR).
- **Host key pinning.** Sentinel refuses to connect when the server's host key doesn't match the pinned fingerprint.
- **Tool output is untrusted.** Tool output is never treated as instructions, and it can't lower the severity of a finding.
- **Deterministic rules come first.** The model can escalate an action's risk, but it can never unlock one.
- **Revocation.** `sentinel:revoke` disables a machine immediately. `--purge` removes the remote user and files.

## Requirements

- PHP 8.3+ with Composer
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

The back office is available at `/sharp`.

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

**Notifications:** mail uses the usual `MAIL_*` settings. Telegram needs a public HTTPS `APP_URL`. Register the webhook from the channel in the back office, or with `php artisan sentinel:telegram-webhook <channel-id>`.

## Adding a machine

1. Create the machine in the back office.
2. Generate its provisioning script and run it on the server as root:
   ```bash
   php artisan sentinel:provision <machine-id> --output=provision.sh
   ```
   The script creates the Sentinel user, installs the restricted key and sudoers policy, and prints the host key fingerprints.
3. Pin the host key:
   ```bash
   php artisan sentinel:pin-host-key <machine-id>
   ```
4. Check that everything works:
   ```bash
   php artisan sentinel:check <machine-id>
   ```
5. Run a first scan:
   ```bash
   php artisan sentinel:scan <machine-id>
   ```

## Artisan commands

| Command | Description |
| --- | --- |
| `sentinel:admin {email}` | Create or promote an admin user |
| `sentinel:provision {machine}` | Print or write the provisioning script (`--sudoers` for the policy only) |
| `sentinel:pin-host-key {machine}` | Pin the server's SSH host key |
| `sentinel:check {machine}` | Diagnose SSH and authentication problems |
| `sentinel:scan {machine}` | Run a scan now (`--objective`, `--provider`, `--model`) |
| `sentinel:scan-due` | Run the scans that are due (scheduled every minute) |
| `sentinel:pending {id?}` | Review, approve or `--reject` pending actions |
| `sentinel:revoke {machine}` | Revoke access (`--purge` to clean the server, `--lift` to undo) |
| `sentinel:telegram-webhook {channel}` | Register a Telegram webhook |

## Development

```bash
php artisan test      # Pest test suite
vendor/bin/pint       # code style
```

Set `SENTINEL_TRANSPORT=fake` to develop without real servers.

## Contributing

Issues and pull requests are welcome. For anything touching the security model (tool catalog, risk gate, provisioning), please open an issue first to discuss it.

If you find a security vulnerability, **please don't open a public issue**. Report it privately via GitHub's *Report a vulnerability* button on the repository.

## License

Sentinel is released under the [PolyForm Noncommercial License 1.0.0](LICENSE.md). You are free to use, study, modify and share it for any **non-commercial** purpose. Commercial use requires a separate licence from the author.
