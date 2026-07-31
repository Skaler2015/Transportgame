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
hPanel → **Advanced → SSH Access** → *Enable*. Wahan milega:
- **SSH IP / Host** (jaise `srv1558.hstgr.io` ya ek IP)
- **Port** (Hostinger par aksar `65002`)
- **Username** (jaise `u246829578`)

Deploy ke liye ek SSH key banao (apne PC par, ya kahin bhi):
```bash
ssh-keygen -t ed25519 -f hostinger_deploy -N ""      # 2 files banengi
```
- `hostinger_deploy.pub` (public key) ka content hPanel → **SSH Access → Manage SSH keys → Import**  me daal do (authorize kar do).
- `hostinger_deploy` (private key) ka **poora content** GitHub secret `SSH_PRIVATE_KEY` me jaayega (agla step).

### 3) App folder + subdomain docroot
- App ko is folder me deploy karenge (chuno ek, DEPLOY_PATH me yahi dalna hai):
  `~/domains/playb.in/cargo_app`  (ya `~/public_html/cargo_app`)
- hPanel → **Websites → cargo.playb.in → (Manage) → Website settings / Subdomains**
  me **Document Root** ko `.../cargo_app/public` par set karo (Laravel ka `public`).
  > Yeh zaroori hai — docroot Laravel ke `public` folder par hona chahiye, warna
  > `.env` public ho sakta hai.

### 4) Server par `.env` banao (ek baar)
SSH se login karke:
```bash
ssh -p 65002 uXXXXXXXX@YOUR_SSH_HOST
mkdir -p ~/domains/playb.in/cargo_app && cd ~/domains/playb.in/cargo_app
# pehle GitHub Actions ek deploy chala dega (neeche), uske baad:
cp .env.production.example .env
nano .env            # DB name/user/password aur APP_URL bhar do
php artisan key:generate
php artisan migrate --seed --force      # world seed ho jayega (cities, cargo, vehicles)
php artisan world:tick                  # pehla contract/price batch
chmod -R 775 storage bootstrap/cache
```

### 5) World ko chalta rakho (cron)
hPanel → **Advanced → Cron Jobs** → naya cron, har minute:
```
cd ~/domains/playb.in/cargo_app && php artisan schedule:run >/dev/null 2>&1
```
Yeh `world:tick` ko har minute chalata hai (economy, events, deliveries aage badhti hain).

### 6) GitHub Secrets daalo
GitHub repo → **Settings → Secrets and variables → Actions → New repository secret**.
Yeh 5 secrets banao (values **yahan chat me mat bhejo**, seedha GitHub me daalo):

| Secret name | Value |
|---|---|
| `SSH_HOST` | Hostinger SSH host/IP (step 2) |
| `SSH_PORT` | `65002` (jo step 2 me dikhe) |
| `SSH_USER` | `uXXXXXXXX` |
| `SSH_PRIVATE_KEY` | `hostinger_deploy` private key ka poora content |
| `DEPLOY_PATH` | `/home/uXXXXXXXX/domains/playb.in/cargo_app` (poora path) |

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
