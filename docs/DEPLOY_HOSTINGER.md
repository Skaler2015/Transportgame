# Auto-deploy to Hostinger — cargo.playb.in

Har `git push` par site apne-aap publish ho — yeh guide wahi set up karti hai.

**Kaise kaam karta hai:** aap (ya main) branch par push karte ho → **GitHub Actions**
app ko build karta hai (Composer + Vite se Vue SPA) → phir **SSH/rsync** se Hostinger
par bhej kar database migrations aur cache warm-up chala deta hai. Workflow file:
[`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml).

> Yeh app **Laravel (PHP) + MySQL + Vue** hai. Poora app **ek hi Laravel app**
> ke roop me deploy hota hai jo `/api` bhi deta hai aur bana hua SPA bhi serve
> karta hai — isliye Hostinger par sirf **ek docroot** chahiye.

---

## Ek baar ka setup (sirf pehli baar)

### 1) MySQL database banao
hPanel → **Databases → MySQL Databases** → naya database + user banao (dono ka naam
`uXXXXXXXX_...` jaisa hoga). Database name, user, password note kar lo.

### 2) SSH access on karo
hPanel → **Advanced → SSH Access** → *Enable* (agar pehle se ACTIVE hai to theek).
Wahan milega: **IP/Host**, **Port** (`65002`), **Username** (`uXXXXXXXX`), aur
**Password** (zaroorat ho to "Change" se naya set kar lo — yahi GitHub secret
`SSH_PASSWORD` me jaayega).

> Hum yahan **password-based** deploy use kar rahe hain (sabse aasaan — koi key
> banane ki zaroorat nahi). Baad me chaaho to SSH key par upgrade kar sakte ho.

### 3) Subdomain docroot ko Laravel ke `public` par le jao
App `public_html/cargo/` me deploy hoga (DEPLOY_PATH). Laravel ka front-controller
uske andar `public/` me hota hai, isliye:
- hPanel → **Websites → cargo.playb.in → Website settings** (ya **Subdomains**) me
  **Document Root** ko `public_html/cargo` se badal kar **`public_html/cargo/public`**
  kar do.
  > Zaroori: docroot `.../cargo/public` par ho — tabhi `.env` web se hidden rahega.

### 4) Server par `.env` banao (ek baar)
Pehle ek deploy chala do (GitHub secrets set karke, ya Actions → Run workflow) taaki
files server par aa jayein. Phir SSH se:
```bash
ssh -p 65002 uXXXXXXXX@82.180.164.152     # apna IP/user
cd ~/public_html/cargo
cp .env.production.example .env
nano .env            # DB name/user/password aur APP_URL=https://cargo.playb.in bharo
php artisan key:generate
php artisan migrate --seed --force      # world seed (cities, cargo, vehicles)
php artisan world:tick                  # pehla contract/price batch
chmod -R 775 storage bootstrap/cache
```

### 5) World ko chalta rakho (cron)
hPanel → **Advanced → Cron Jobs** → naya cron, har minute:
```
cd ~/public_html/cargo && php artisan schedule:run >/dev/null 2>&1
```
Yeh `world:tick` ko har minute chalata hai (economy, events, deliveries aage badhti hain).

### 6) GitHub Secrets daalo
GitHub repo → **Settings → Secrets and variables → Actions → New repository secret**.
Yeh 5 secrets banao (values **yahan chat me mat bhejo**, seedha GitHub me daalo):

| Secret name | Value |
|---|---|
| `SSH_HOST` | Hostinger SSH IP (step 2) |
| `SSH_PORT` | `65002` |
| `SSH_USER` | `uXXXXXXXX` |
| `SSH_PASSWORD` | aapka SSH password (step 2) |
| `DEPLOY_PATH` | `/home/uXXXXXXXX/public_html/cargo` (poora path) |

---

## Uske baad — bas push karo

Secrets set hone ke baad, **har push par** GitHub Actions apne-aap:
1. SPA build karega, 2. Composer install, 3. rsync se Hostinger par bhejega,
4. `migrate --force` + cache warm karega. Site turant update ho jayegi.

Progress dekho: GitHub repo → **Actions** tab → "Deploy to Hostinger".
Manual chalana ho to wahin **Run workflow** dabao (`workflow_dispatch`).

---

## Note / troubleshooting
- **Pehla run** tabhi green hoga jab server par `.env` maujood ho (step 4). Isliye
  pehli baar: ek deploy chalao (files chali jayengi) → phir SSH se `.env` banao +
  migrate → dobara deploy/`Run workflow`.
- **500 error** aaye to server par `storage/` aur `bootstrap/cache/` writable karo
  (`chmod -R 775`), aur `APP_KEY` set hai ye check karo.
- **PHP version**: hPanel → Advanced → PHP Configuration me PHP **8.2+** (behtar 8.3/8.4) chuno.
- **Sirf FTP hai, SSH nahi?** Tab migrations SSH se nahi chal payengi. Aisi soorat me
  batao — main ek FTP-based workflow bana dunga aur migrations ke liye ek surakshit
  tareeka (cron/Terminal) set kar denge. Par database wale game ke liye SSH behtar hai.
