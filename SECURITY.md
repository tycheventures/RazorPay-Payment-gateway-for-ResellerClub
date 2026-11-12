# Security Policy

This gateway handles payment confirmations for ResellerClub. Please report security problems privately so they can be fixed before they're public.

## Reporting a vulnerability

**Don't open a public issue.** Use GitHub's private reporting instead:

1. Open the repository's **Security** tab.
2. Click **Report a vulnerability**.
3. Describe the problem, the affected file(s), and the steps to reproduce it.

You should get a reply within 7 days. Once a fix is released, you'll be credited in the release notes unless you'd rather stay anonymous.

## Supported versions

Only the latest release gets security fixes. Each merge to `main` publishes a new release, so update to the newest one.

## Scope

In scope:
- Bypassing the ResellerClub checksum or the Razorpay signature/payment checks, so an invoice is marked paid without payment
- Reading `config.php`, the transaction database or logs over the web
- Open redirects, XSS or injection in `pay.php`, `callback.php` or `webhook.php`
- Weaknesses in the release and deploy workflows that could expose secrets or ship unreviewed code

Out of scope:
- Problems in ResellerClub or Razorpay themselves (report those to them)
- Misconfigured deployments, such as a web server that ignores `.htaccess`

## Keeping your deployment safe

- Keep `config.php` out of version control. Use test keys until you go live.
- Make sure `config.php`, `src/`, `bin/` and `data/` return 403 from the web.
- Set `allowed_redirect_hosts` to your own storefront and control panel hostnames.
