# User Management Suite

A complete, modular user-management toolkit for WordPress, in a single plugin:

| Module | What it does |
| --- | --- |
| **Registration & Activity Tracking** | Records registration source/URL, last login time and (optional, anonymizable) IP; adds sortable Users-list columns and CSV export. |
| **Email Verification** | Requires email verification before login; spam/domain blocking; optional Google reCAPTCHA. |
| **Multiple Roles** | Assign multiple roles per user via a checklist on the user editor. |
| **User Switching** | Switch into any account you can edit, and switch back instantly. |

Everything is configured from one tabbed settings page at **Users → User Management**.
Each module can be enabled or disabled independently.

## Requirements

- WordPress 6.2+
- PHP 7.4+

## Development

```bash
composer install      # install dev tooling (PHPCS + WordPress Coding Standards)
composer phpcs        # lint
composer phpcbf       # auto-fix
```

The plugin uses a small PSR-4-style autoloader (`Marinski\UserManagementSuite\` →
`src/`). No runtime Composer dependencies are bundled.

## Architecture

- `user-management-suite.php` — bootstrap: header, constants, autoloader, lifecycle hooks.
- `src/Plugin.php` — container that loads settings, admin, and enabled modules.
- `src/Settings/` — the single `ums_settings` option, the tabbed settings page, and reusable field renderers.
- `src/Modules/<Name>/` — one self-contained, toggleable module per feature area.
- `src/Support/` — shared helpers (security, IP handling).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
