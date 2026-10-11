=== LetterWP ===
Contributors: owldraft
Tags: newsletter, email, subscribers, resend, post notification
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish a post and it goes out as a beautiful email, through your own Resend account. Your list stays in your own WordPress.

== Description ==

LetterWP turns your WordPress posts into a newsletter. Publish a post and every subscriber gets it as an email: the whole post, laid out for the inbox, under your own name and your own domain.

* **Your list, in your WordPress.** Subscribers live in your own database, not on someone else's platform. Import a CSV from anywhere, export it any time.
* **Your brand.** Your logo, your colour, your domain in the From line. No "Sent with WordPress.com".
* **A beautiful email.** Blocks become clean, inbox-safe HTML: images, quotes, buttons, tables, code and video embeds all work in Gmail, Apple Mail and Outlook.
* **Your own sending account.** Emails go out through your [Resend](https://resend.com) account, so deliverability is yours too. Resend's free plan covers up to 1,000 contacts.

= What it does =

* **Send on publish.** A "Newsletter" panel in the editor: send this post as an email when it is published, send a test to yourself, preview it in a new tab. A post is never sent twice.
* **Subscribe form.** A Subscribe block and a `[letterwp_subscribe]` shortcode, with double opt-in and spam protection.
* **One list, one click to leave.** Every subscriber gets every post, and the Unsubscribe link in each email works in one click, on your site.
* **Reader account page.** Readers sign in with a magic link (no passwords, no WordPress accounts) to change their name or unsubscribe.
* **Subscribers screen.** Search, add, edit and delete subscribers. CSV import (Substack, beehiiv, Ghost, Mailchimp, Kit or any file with an email column) and CSV export.
* **Two-way sync with Resend.** Unsubscribes, bounces and spam complaints come back to WordPress through a signed webhook.
* **Email results per post.** Delivered, bounced, marked as spam and unsubscribed, from Resend's events. No open or click tracking.
* **Email Design.** Logo, site name and brand colour, with a live preview built from your real posts and a Send test button.
* **Guided setup.** Connect your Resend API key, add and verify your sending domain (with the DNS records to copy), set your sender addresses and postal address, and send a test email, step by step.

= Requirements =

* A [Resend](https://resend.com) account (free to start) and a domain you can add DNS records to.

== Installation ==

1. Install and activate LetterWP.
2. Go to **LetterWP > Setup** and paste your Resend API key.
3. Add your domain and copy the DNS records it shows into your DNS provider, then check again until it says Verified.
4. Set the newsletter address (for example `newsletter@yourdomain.com`), your postal address, and click **Run setup**.
5. Add the **Subscribe** block to a page, a post or a footer widget.
6. Write a post, tick **Send as email when published** in the Newsletter panel, and publish.

== Frequently Asked Questions ==

= Why Resend, and what does it cost? =

LetterWP sends through your own Resend account, so you own your sending reputation and nothing is resold to you. Resend's free plan covers up to 1,000 contacts. Above that, Resend's paid plans start at $40/month for marketing email. See [resend.com/pricing](https://resend.com/pricing) for current prices.

= Where are my subscribers stored? =

In your WordPress database, in LetterWP's own table. They are copied to Resend as contacts so Resend can deliver to them. If you stop using LetterWP, export them as a CSV from Subscribers > Import/Export.

= Does LetterWP track opens or clicks? =

No. Results come from Resend's delivery events: delivered, bounced, marked as spam and unsubscribed.

= Can I import my list from Substack, Mailchimp or another tool? =

Yes. Subscribers > Import/Export takes any CSV file with an email column. Names and subscribe dates come along when the file has them.

= Does LetterWP add a credit to my emails? =

Yes, one small line under the unsubscribe link: "Newsletter easily with LetterWP". It is what keeps LetterWP free. [LetterWP Pro](https://letterwp.com/pricing/) removes it.

= What does LetterWP Pro add? =

Campaigns (emails that are not posts: launches, offers), topics per category so readers pick what they get, the full Email Design (fonts, sizes, buttons, post details, excerpt or full post), no footer credit, and email support. See [letterwp.com/pricing](https://letterwp.com/pricing/).

== External services ==

LetterWP connects to the following services. Nothing is sent to any of them until you connect your Resend API key in LetterWP > Setup.

= Resend =

[Resend](https://resend.com) is the email service that sends your newsletters. LetterWP calls the Resend API (`https://api.resend.com`) with your own API key to:

* list, add and verify your sending domains;
* create the segment, topics, contact property and webhook your newsletter uses;
* keep subscribers in sync as contacts: email address, first and last name, subscription status, topic choices and an unsubscribe key;
* send newsletters, test emails and sign-in links: the email's subject, HTML and text, the sender and the recipients.

Resend calls back to your site's REST API (a signed webhook) with delivery events: delivered, bounced, complained, unsubscribed, delayed and failed.

Resend [Terms of Service](https://resend.com/legal/terms-of-service) and [Privacy Policy](https://resend.com/legal/privacy-policy).

= YouTube and Vimeo thumbnails =

When a post you send has a YouTube or Vimeo embed, LetterWP downloads the video's thumbnail image once, from `https://i.ytimg.com` or `https://i.vimeocdn.com`, and saves a copy on your site so the email can show it. Only the video's thumbnail address is requested; no data about you or your readers is sent.

YouTube: [Terms of Service](https://www.youtube.com/t/terms) and [Google Privacy Policy](https://policies.google.com/privacy). Vimeo: [Terms of Service](https://vimeo.com/terms) and [Privacy Policy](https://vimeo.com/privacy).

= letterwp.com updates =

The copy of LetterWP downloaded from letterwp.com checks `https://letterwp.com/update/letterwp` for a new version, during WordPress's own update check (about twice a day). The request carries the installed version and, like every request WordPress makes, your site's address in its User-Agent. letterwp.com keeps only a one-way hash of that address, to count active installs. The WordPress.org copy does not make this request.

letterwp.com [Terms](https://letterwp.com/terms/) and [Privacy Policy](https://letterwp.com/privacy-policy/).

== Changelog ==

= 1.0.1 =
* Ready for LetterWP Pro: campaigns get the Email column on their list, and Pro can turn the footer credit off.

= 1.0.0 =
* First public release.
