# Demo login

After installing MicroSysAdmin, enable its demonstration from the application directory:

```bash
sudo php scripts/demo.php enable
```

On XAMPP, use `sudo /opt/lampp/bin/php scripts/demo.php enable`.

Open `http://localhost/microsysadmin/login` and sign in with:

| Field | Value |
|---|---|
| Username | `admindemo` |
| Password | `AdminDemoPassword` |

The login page displays these public credentials while demo mode is enabled. The demo account has the Administrator role, uses a hashed password, and does not require a password change. Enabling the demo again reuses the same account and resets the sample data. An existing account with that username and a different password is preserved, and setup stops with an error.

Demo mode applies to the whole installation: pages and APIs use sample data, and server operations are simulated. Password changes, terminal execution, workspace previews, external APIs, and real scheduled operations are disabled. Sample data resets automatically every hour. Use a separate installation without customer data for a hosted demonstration.

Production installation continues to generate a private administrator password. The shared demo password is accepted only in demo mode; it does not change the password requirements for ordinary accounts.

To return to ordinary operation:

```bash
sudo php scripts/demo.php disable
```

This disables the demo account and invalidates its sessions and remembered logins. Sign in with the private administrator account created during installation. The Safe security profile remains active. If `installation.demo_mode` is explicitly enabled in `config/config.php`, set it to `false` before running the disable command.
