# Quickstart

## Install

```bash
git clone https://github.com/hustlonian/microsysadmin-public.git microsysadmin
cd microsysadmin
sudo ./install.sh
```

The interactive installer asks only for applicable database credentials. It selects healthy XAMPP under `/opt/lampp` or installs the managed Ubuntu 24.04 stack, generates the first Administrator, and protects the one-time login in `/var/lib/microsysadmin/private/initial-administrator.txt`. Open `http://localhost/microsysadmin/login` only after the installer reports that diagnostics, authentication validation, the release-critical health gate, and HTTP verification passed.

## Verify or maintain

```bash
sudo ./install.sh --check
sudo ./install.sh --repair
sudo ./install.sh --upgrade
```

An ordinary second `sudo ./install.sh` validates without replacing secrets, users, or customer data. Repair and upgrade create verified application/database rollback material first.

## Automated install

```bash
read -rsp "Database administrator password: " MICROSYSADMIN_DB_ADMIN_PASSWORD
export MICROSYSADMIN_DB_ADMIN_PASSWORD
sudo --preserve-env=MICROSYSADMIN_DB_ADMIN_PASSWORD env \
  MICROSYSADMIN_DB_ADMIN_USER=root \
  ./install.sh --non-interactive
unset MICROSYSADMIN_DB_ADMIN_PASSWORD
```

Ubuntu MariaDB normally needs no password because root authenticates through its local socket. Do not use password command-line options. When Administrator variables are omitted, retrieve the generated login with `sudo cat /var/lib/microsysadmin/private/initial-administrator.txt`, change the password at first login, then securely remove the file. See [Installation and recovery](INSTALLATION.md) for external databases, locations, backups, rollback, and uninstall behavior.

## Exact customer-path canary

```bash
./tools/github-test-vm/vm install-clean
```

This creates a reset disposable Ubuntu 24.04 environment, clones an ephemeral Git origin containing exactly the customer-visible source, runs `sudo ./install.sh`, and tests authentication, permissions, services, idempotency, installer upgrade and failed-upgrade recovery, backup/restore, signed update, manual and failed-update rollback, safe uninstall, repair, and secret redaction.
