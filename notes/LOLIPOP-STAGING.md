# 和歌山雑賀クラブ LINE釣果・ロリポップ複製サイト（作業中）

- Source: https://github.com/oosaka0123-sudo/wakayama-saika-club
- Copied on 2026-11-19 from main commit e0b0dce.
- Entire mirror stays isolated under C:\Users\oosak\projects\wakayama-saika-lolipop-staging and tracked in this GitHub draft branch until the public server is safely verified.
- **DO NOT modify** chat.rss7.net, rss7.net, kansai.rss7.net or their current DNS/site root/directories.
- Preserve all 5 existing pages' styles, navigation and photo assets.
- catch/ is the new reports page. line-api/ is the future PHP webhook+image storage.
- Lolipop destination has now been identified as `azuma.rss7.net` -> public folder `azuma` (screen observed 2026-11-20). Saving the folder setting, free SSL and upload are NOT yet confirmed. Webhook URL will be `https://azuma.rss7.net/line-api/webhook.php` once HTTPS works. No LINE account, channel secret, or token exists yet.
- Do not publish this mirror as a second indexed duplicate. Keep mirror noindex or canonicalize to the official GitHub Pages site.
- A second URL cannot be made identical in the browser address bar. The seamless solution is to display the reports at the existing GitHub Pages domain while fetching data from Lolipop.
- User requirements: photo required; author and comment optional; no approval for each post; ability to delete incorrect posts; member-only posting ideally.
- Public site production must remain untouched until backend/LINE is verified.
