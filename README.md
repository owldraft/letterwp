# LetterWP

Publish a post and it goes out as a beautiful email, through your own [Resend](https://resend.com) account. Your list stays in your own WordPress.

**[Download LetterWP (free)](https://letterwp.com/download/)** · [Docs](https://letterwp.com/docs/) · [Free vs Pro](https://letterwp.com/pricing/) · [letterwp.com](https://letterwp.com)

- **Your list, in your WordPress.** Subscribers live in your own database. CSV import from anywhere, CSV export any time.
- **Your brand.** Your logo, your colour, your domain in the From line.
- **A beautiful email.** Blocks become inbox-safe HTML that works in Gmail, Apple Mail and Outlook.
- **Your own sending account.** Resend's free plan covers up to 1,000 contacts.

## Install

1. [Download `letterwp.zip`](https://letterwp.com/download/).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, choose the zip, and activate it.
3. Go to **LetterWP > Setup** and follow the steps. The [docs](https://letterwp.com/docs/) walk through each one.

Updates arrive in **Dashboard > Updates** like any other plugin. Requires WordPress 6.5+ and PHP 7.4+.

See [readme.txt](readme.txt) for the full feature list, the FAQ and the external services LetterWP talks to.

## Build

`bin/build.sh` makes the download zip from the committed tree; `bin/build.sh wporg` makes the WordPress.org one, without the updater.

## License

GPLv2 or later. See [LICENSE](LICENSE). Made by [Owldraft](https://owldraft.com).
