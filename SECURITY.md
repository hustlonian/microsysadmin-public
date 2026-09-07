# MicroSysAdmin Security Policy

MicroSysAdmin controls development-server resources and can expose dangerous local capabilities when an administrator enables them. Deploy it only on localhost, a private network, or behind a VPN and authenticated reverse proxy. Do not expose the browser terminal or Code Runner to the public Internet.

## Supported releases

Security fixes are provided for commercially supported MicroSysAdmin releases according to the active update entitlement. An expired update entitlement does not disable an installed version or essential local access.

## Reporting a vulnerability

Do not open a public issue containing exploit details, credentials, customer data, or machine paths. Contact the repository owner through a private GitHub security advisory after the private repository is established. Include the affected version, security profile, reproduction steps, and impact without including real secrets.

## Repository rules

- Never commit `config/config.php`, `.env` files, runtime storage, database dumps, logs, backups, machine identity, agent credentials, private keys, or access tokens.
- Run `./microsysadmin-git audit` before every commit and `./microsysadmin-git verify` before every push.
- Rotate any credential found in source, Git history, shell history, command logs, or a remote URL. Removing the text alone does not revoke it.
- Stable releases require verifiable metadata and a configured signing process. Unsigned development archives must not be described as signed releases.
- Remote history rewriting and force pushes require an explicit, separately documented decision.

See [docs/SECURITY.md](docs/SECURITY.md) and [docs/SECURITY-PROFILES.md](docs/SECURITY-PROFILES.md) for product hardening guidance.
