# Auto-deploy to Hostinger — cargo.playb.in

Har `git push` par site apne-aap publish ho — yeh guide wahi set up karti hai.

**Kaise kaam karta hai (fast + firewall-proof):**
1. Push par **GitHub Actions** app build karta hai (Composer + Vite) aur ek
   **`hostinger-live`** branch me poora bana-banaya app daal deta hai.
2. **Hostinger ka apna Git** us branch ko server-side **khud pull** karta hai
   (webhook se). Git packfile bhejta hai — hazaaron files bhi 1–3 min me, aur
   Hostinger outbound pull karta hai isliye firewall kabhi block nahi karta.

> **FTP/SSH kyun chhoda?** Hostinger CI ke IPs se SSH block karta hai, aur FTP se
> vendor ki ~15,000 files bhejna ghante bhar leta tha. Git pull isse kaei guna
> tez aur reliable hai. (Pehle banaye **FTP_/SSH_ secrets ab zaroori nahi** —
> rehne do ya delete kar do.)

> App **ek hi Laravel app** hai jo `/api` bhi deta hai aur bana hua SPA bhi serve
> karta hai — isliye Hostinger par sirf **ek docroot** chahiye.

---

## Ek baar ka setup

### 1) MySQL database (ho gaya ✅)
hPanel → **Databases**. `uXXXXXXXX_cargo` (db + user) + password note kar lo.

### 2) `hostinger-live` branch ban jaane do
Yeh GitHub Actions khud banata hai. Pehli baar: GitHub → **Actions** →
"Deploy to Hostinger" → **Run workflow** (ya koi push). ~1–2 min me repo me ek
nayi branch **`hostinger-live`** dikhne lagegi (isme bana-banaya app hota hai).

### 3) Hostinger me Git repository jodo
hPanel → **Advanced → GIT** → **Create a New Repository**:
- **Repository (URL):** `https://github.com/Skaler2015/Transportgame.git`
- **Branch:** `hostinger-live`
- **Directory:** `public_html/cargo`
- **Create** → Hostinger built app ko `public_html/cargo` me clone kar dega.

### 4) Auto-Deployment on karo (webhook)
Usi GIT page par apni repository ke aage **Auto-Deployment / Webhook URL** milega
→ usse **copy** karo. Phir:
GitHub repo → **Settings → Webhooks → Add webhook** →
- **Payload URL:** wahi copy kiya hua Hostinger webhook URL
- **Content type:** `application/json`
- **Just the push event** → **Add webhook**.

Ab jab bhi CI `hostinger-live` push karega, GitHub Hostinger ko ping karega aur
Hostinger turant pull kar lega. (Chaaho to hPanel GIT page se **"Deploy"** dabakar
manually bhi pull kar sakte ho.)

### 5) Subdomain docroot `public` par le jao
hPanel → **Websites → cargo.playb.in → Website settings / Subdomains** me
**Document Root** ko `public_html/cargo` → **`public_html/cargo/public`** kar do.
> Zaroori: docroot `.../cargo/public` par ho — tabhi `.env` web se hidden rahega.

### 6) Ek baar `.env` + migrate (apne PC ke SSH se — aapka IP block nahi hai)
```bash
ssh -p 65002 uXXXXXXXX@82.180.164.152
cd ~/public_html/cargo
cp .env.production.example .env
nano .env         # DB name/user/password + APP_URL=https://cargo.playb.in
php artisan key:generate
php artisan migrate --seed --force        # world seed (cities, cargo, vehicles)
php artisan storage:link || true
php artisan world:tick
chmod -R 775 storage bootstrap/cache
```
> `.env` git me nahi jaata, isliye pull par kabhi overwrite nahi hoga. Naya
> migration aane par hi `migrate --force` dobara chalana (kabhi-kabhi).

### 7) World chalta rahe (cron)
hPanel → **Advanced → Cron Jobs** → har minute:
```
cd ~/public_html/cargo && php artisan schedule:run >/dev/null 2>&1
```

---

## Uske baad — bas push karo
Har push par: GitHub build → `hostinger-live` push → webhook → Hostinger pull →
site update. Progress: repo → **Actions** tab; manual chalane ke liye **Run workflow**.

## Troubleshooting
- **500 / blank page** → `.env` bana hai? `APP_KEY` set? `storage/` &
  `bootstrap/cache/` par `chmod -R 775`? Docroot `.../cargo/public` par hai?
- **Pull nahi ho raha** → hPanel GIT page se ek baar **"Deploy"** manual dabao;
  webhook URL sahi paste hua kya check karo.
- **PHP version**: hPanel → Advanced → PHP Configuration me **8.2+** (behtar 8.3).
