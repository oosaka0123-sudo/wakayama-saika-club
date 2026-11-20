/* Standalone pre-deployment checks. Run: node tests/check-staging.cjs */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const get = name => fs.readFileSync(path.join(root,name), 'utf8');
const pages=['index.html','about/index.html','contact/index.html','gallery/index.html','join/index.html'];
for(const name of pages){
 const html=get(name);
 assert.match(html, /<meta name="robots" content="index,follow">/, name+' must stay indexable on GitHub Pages');
 assert.match(html, /data-wsc-footer/, name+' shared footer missing');
}
assert.match(get('catch/index.html'), /<title>釣果情報<\/title>/);
assert.match(get('catch/index.html'), /<h1>釣果情報<\/h1>/);
assert.match(get('catch/index.html'), /noindex,nofollow/);
assert.match(get('index.html'), /id="catch-home-grid"/);
assert.match(get('js/footer.js'), /catch\/.*釣果情報/);
assert.match(get('.htaccess'), /X-Robots-Tag "noindex, nofollow"/);
assert.match(get('line-api/webhook.php'), /hash_hmac\('sha256'/);
assert.match(get('line-api/webhook.php'), /wsc_public_photo/);
assert.match(get('line-api/admin/index.php'), /csrf/);
assert.match(get('line-api/feed.php'), /Access-Control-Allow-Origin/);
assert.match(get('line-api/config.sample.php'), /'channel_secret' => ''/);
assert.match(get('line-api/config.sample.php'), /https:\/\/azuma\.rss7\.net\/line-api/);
assert.doesNotMatch(get('js/footer.js'), /東[\s　]+秀樹/, 'full contact name must not be published');
assert.doesNotMatch(get('index.html'), /東[\s　]+秀樹/, 'full contact name must not be published');
assert.match(get('tools/package-azuma.ps1'), /config\.local\.php/);
assert.match(get('tools/package-azuma.ps1'), /\.htaccess/);
assert.ok(!fs.existsSync(path.join(root,'line-api/config.local.php')), 'secrets must not be in repository');
assert.ok(!fs.existsSync(path.join(root,'line-api/data/state.json')), 'private state must not be in repository');
console.log('PASS: 5 official pages indexable, catch page named correctly, mirror SEO isolation, webhook, admin and secrets');
