# Security Policy

## Supported Versions

Security fixes are issued for the current minor release only. Older
versions are not patched — please upgrade before reporting.

| Version | Supported |
| ------- | --------- |
| 3.3.x   | ✅ |
| < 3.3   | ❌ |

## Reporting a Vulnerability

**Please do not open a public issue for a security problem.**

Preferred: use GitHub's [private vulnerability reporting](https://github.com/shauncuier/dropshipzone/security/advisories/new)
on this repository. It keeps the report private until a fix is released
and gives us somewhere to coordinate with you.

If that is unavailable, email **plugins@3s-soft.com** with `SECURITY` in
the subject line.

Please include:

- A description of the vulnerability and its potential impact
- Steps to reproduce, ideally with a minimal example
- The plugin, WordPress, WooCommerce and PHP versions you tested against
- Any fix or mitigation you are aware of

We aim to acknowledge reports within 48 hours and to give a remediation
timeline within 5 business days. You will be kept informed as the fix
progresses, and credited in the changelog unless you would rather not be.

## Scope

In scope — anything in this repository:

- Unescaped output, unsanitized input, missing capability or nonce checks
- SQL injection in the plugin's own queries
- Exposure of stored Dropshipzone API credentials
- Privilege escalation through the plugin's admin screens or AJAX endpoints
- Data leakage through the shipping method at checkout

Out of scope:

- Vulnerabilities in WordPress core, WooCommerce, or other plugins —
  report those to their respective maintainers
- The Dropshipzone API itself, or anything concerning a Dropshipzone
  account. This plugin is an independent integration; contact
  Dropshipzone Pty Ltd directly for service matters
- Findings that require an already-compromised administrator account
- Reports produced solely by an automated scanner with no demonstrated
  impact

## Handling of Credentials

Dropshipzone API credentials are stored encrypted at rest using
AES-256-CBC with a random IV per value, keyed from the WordPress `auth`
salt. They are never written to logs and never rendered in admin output.

If you rotate your WordPress security salts, stored credentials can no
longer be decrypted and must be re-entered. That is expected behaviour,
not a vulnerability.
