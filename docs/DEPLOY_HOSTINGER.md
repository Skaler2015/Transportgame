# Auto-deploy to Hostinger — cargo.playb.in

Har `git push` par site apne-aap publish ho — yeh guide wahi set up karti hai.

**Kaise kaam karta hai:** push par **GitHub Actions** app build karta hai
(Composer + Vite se Vue SPA) → phir **FTP** se Hostinger par files bhej deta hai.
Workflow file: [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml).

> **SSH kyun nahi?** Hostinger apne firewall se cloud/CI (GitHub) ke IPs ko SSH par
> block karta hai (aapke apne PC se SSH chalega, GitHub se nahi). Isliye transfer
> **FTP** se hota hai. Database migrations aap **ek baar apne PC ke SSH se** chalate
> ho (aapka IP block nahi hai) — schema kabhi-kabhi hi badalta hai.

> App **ek hi Laravel app** hai jo `/api` bhi deta hai aur bana hua SPA bhi serve
> karta hai — isliye Hostinger par sirf **ek docroot** chahiye.

---

## Ek baar ka setup

### 1) MySQL database (ho gaya ✅)
hPanel → **Databases → Management**. Database `uXXXXXXXX_cargo`, user `uXXXXXXXX_cargo`,
aur password — teeno note kar lo (`.env` me lagenge).

### 2) FTP details lo
hPanel → **Files → FTP Accounts**. Wahan milega:
- **FTP hostname** (jaise `82.180.164.152` ya `ftp.playb.in` / server host)
- **FTP username** (jaise `uXXXXXXXX` ya `uXXXXXXXX.playb.in`)
- **FTP password** (zaroorat ho to naya set kar lo)

### 3) Subdomain docroot ko `public` par le jao
App `public_html/cargo/` me jaayega; Laravel ka front-controller uske `public/` me hai:
- hPanel → **Websites → cargo.playb.in → Website settings / Subdomains** me
  **Document Root** ko `public_html/cargo` → **`public_html/cargo/public`** kar do.
  > Zaroori: docroot `.../cargo/public` par ho — tabhi `.env` web se hidden rahega.

### 4) GitHub Secrets daalo
GitHub repo → **Settings → Secrets and variables → Actions**. Yeh 4 secrets banao
(agar pehle SSH_* secrets banaye the, unhe rehne do ya delete kar do — ab inki
zaroorat nahi):

| Secret name | Value |
|---|---|
| `FTP_SERVER` | FTP hostname (step 2), jaise `82.180.164.152` |
| `FTP_USERNAME` | FTP username (step 2) |
| `FTP_PASSWORD` | FTP password (step 2) |
| `FTP_SERVER_DIR` | `/public_html/cargo/`  *(agar aapka FTP seedha public_html me kholta hai to `/cargo/`)* |

### 5) Pehla deploy chalao
GitHub → **Actions** → "Deploy to Hostinger" → **Run workflow**. Pehli baar vendor ki
hazaaron files jaati hain to **thoda slow** hoga (10–15 min); agli baar se sirf badli
hui files jaati hain, fast.

### 6) Ek baar `.env` + migrate (apne PC ke SSH se)
Apne computer (Windows Terminal / PowerShell / Mac Terminal) se:
```bash
ssh -p 65002 uXXXXXXXX@82.180.164.152      # apna IP/user (SSH Access page se)
cd ~/public_html/cargo
cp .env.production.example .env
nano .env         # DB name/user/password + APP_URL=https://cargo.playb.in bharo
php artisan key:generate
php artisan migrate --seed --force        # world seed (cities, cargo, vehicles)
php artisan storage:link || true
php artisan world:tick                    # pehla contract/price batch
chmod -R 775 storage bootstrap/cache
```
> Aage code/frontend badloge to FTP se apne-aap chadh jayega. Sirf **naya migration**
> aane par yeh `migrate --force` dobara chalana (kabhi-kabhi).

### 7) World chalta rahe (cron)
hPanel → **Advanced → Cron Jobs** → har minute:
```
cd ~/public_html/cargo && php artisan schedule:run >/dev/null 2>&1
```

---

## Uske baad — bas push karo
Secrets set hone ke baad **har push par** GitHub build karke FTP se files chadha
dega. Progress: repo → **Actions** tab. Manual chalane ke liye **Run workflow**.

## Troubleshooting
- **FTP bhi timeout/fail ho** to Hostinger cloud IPs ko FTP par bhi block kar raha
  hai — tab hum **Hostinger ka apna Git auto-deploy (webhook)** use karenge (Hostinger
  khud pull karta hai, kabhi block nahi hota). Bata dena, main set kar dunga.
- **500 / blank page** → `.env` bana hai? `APP_KEY` set hai? `storage/` &
  `bootstrap/cache/` par `chmod -R 775` kiya? Docroot `.../cargo/public` par hai?
- **PHP version**: hPanel → Advanced → PHP Configuration me **8.2+** (behtar 8.3).
