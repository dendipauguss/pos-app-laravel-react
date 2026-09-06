# Local Docker Deployment

Dokumen ini menjelaskan deployment aplikasi POS ke Ubuntu Server yang berjalan di VirtualBox. Source code dikembangkan dari WSL, sedangkan Docker dijalankan di dalam VM Ubuntu.

## Arsitektur

```text
WSL
  -> git push ke GitHub

Ubuntu Server VM
  -> git pull
  -> Docker Compose
       nginx    : host 8080 -> container 80
       app      : Laravel + PHP-FPM 8.3
       worker   : Laravel database queue
       pgsql    : PostgreSQL 16
```

Source code di WSL tidak otomatis terlihat oleh VM. Distribusi source code dilakukan melalui GitHub.

## Prasyarat

- VirtualBox
- Ubuntu Server 24.04 di VM
- Repository GitHub
- RAM VM minimal 8 GB untuk build Docker
- Disk VM minimal 25 GB
- Minimal 2 CPU core, idealnya 4 core

## 1. Konfigurasi jaringan VirtualBox

Untuk instalasi package dan Docker, gunakan:

- Adapter 1: `NAT`
- Adapter type: `Intel PRO/1000 MT Desktop (82540EM)`
- `Cable Connected`: aktif

Ubuntu biasanya mendapat IP seperti `10.0.2.15`. IP tersebut adalah IP internal NAT VM.

### Port forwarding SSH

Di VirtualBox, buka `Settings -> Network -> Adapter 1 -> Advanced -> Port Forwarding` dan tambahkan:

| Name | Protocol | Host Port | Guest Port |
| --- | --- | ---: | ---: |
| SSH | TCP | 2222 | 22 |

Host IP dan Guest IP dapat dikosongkan.

### Port forwarding aplikasi

Tambahkan aturan:

| Name | Protocol | Host Port | Guest Port |
| --- | --- | ---: | ---: |
| Web | TCP | 8080 | 8080 |

Aplikasi dapat dibuka dari Windows melalui `http://127.0.0.1:8080`.

## 2. Menyiapkan SSH di Ubuntu VM

Jalankan langsung di console Ubuntu VM:

```bash
sudo apt update
sudo apt install -y openssh-server git
sudo systemctl enable --now ssh
```

Tes dari PowerShell Windows:

```powershell
ssh -p 2222 dendipauguss@127.0.0.1
```

Dari WSL, `127.0.0.1` berarti localhost milik WSL dan tidak selalu sama dengan localhost Windows. Gunakan IP gateway Windows jika diperlukan:

```bash
ip route | awk '/default/ {print $3}'
ssh -p 2222 dendipauguss@IP_WINDOWS_HOST
```

Jika muncul `REMOTE HOST IDENTIFICATION HAS CHANGED`, hapus key lama hanya untuk host dan port tersebut:

```bash
ssh-keygen -f "$HOME/.ssh/known_hosts" -R "[IP_WINDOWS_HOST]:2222"
```

Verifikasi fingerprint dari VM sebelum menerima host key baru.

## 3. Memperbesar disk Ubuntu

Disk virtual sudah diperbesar menjadi 25 GB di VirtualBox, tetapi filesystem Ubuntu awalnya masih berukuran sekitar 12 GB. Periksa:

```bash
lsblk
df -h
```

Pada instalasi ini `/dev/sda3` adalah partisi LVM dan root logical volume adalah `/dev/mapper/ubuntu--vg-ubuntu--lv`:

```bash
sudo pvresize /dev/sda3
sudo lvextend -l +100%FREE /dev/mapper/ubuntu--vg-ubuntu--lv
sudo resize2fs /dev/mapper/ubuntu--vg-ubuntu--lv
df -h /
```

Jika disk baru saja diperbesar tetapi `/dev/sda3` belum mengikuti, jalankan terlebih dahulu:

```bash
sudo growpart /dev/sda 3
```

Pastikan nama device sesuai output `lsblk` sebelum menjalankan perintah.

## 4. Install Docker di VM

```bash
sudo apt update
sudo apt install -y docker.io docker-compose-v2 git
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
```

Logout dan login kembali, lalu verifikasi:

```bash
docker --version
docker compose version
docker run hello-world
```

Pesan `Unable to find image 'hello-world:latest' locally` normal pada eksekusi pertama. Docker sedang mengunduh image.

## 5. Menyiapkan repository di VM

Dari WSL, push source code:

```bash
cd /home/dps/projects/pos-app
git add .
git commit -m "Prepare local Docker deployment"
git push origin main
```

Di VM:

```bash
sudo mkdir -p /opt/pos-app
sudo chown -R "$USER":"$USER" /opt/pos-app
git clone https://github.com/USERNAME/REPOSITORY.git /opt/pos-app
cd /opt/pos-app
```

Untuk update berikutnya:

```bash
cd /opt/pos-app
git pull origin main
```

## 6. File Docker yang digunakan

### `Dockerfile`

Menggunakan multi-stage build:

1. Stage `vendor` menjalankan Composer dengan PHP dependencies production.
2. Stage `frontend` memakai PHP CLI 8.3 dan Node 22. PHP diperlukan karena Wayfinder menjalankan `php artisan wayfinder:generate --with-form` saat build frontend.
3. Stage `app` memakai PHP-FPM 8.3 dengan extension `pdo_pgsql`, `intl`, `bcmath`, dan `zip`.
4. Stage `web` memakai Nginx dan mengambil folder `public` dari image aplikasi.

Build memvalidasi bahwa file Composer penting tidak kosong:

```text
vendor/autoload.php
vendor/laravel/framework/src/Illuminate/Foundation/Application.php
```

### `compose.yaml`

Menyediakan empat service:

- `app`: Laravel PHP-FPM
- `nginx`: web server pada port host `8080`
- `worker`: `php artisan queue:work database`
- `pgsql`: PostgreSQL 16 Alpine

Named volume yang digunakan:

- `laravel_storage`
- `pgsql_data`

Jangan menjalankan `docker compose down -v` jika database ingin dipertahankan.

### `docker/nginx/default.conf`

Nginx menggunakan `/var/www/html/public` sebagai document root dan meneruskan file PHP ke `app:9000`.

### `.dockerignore`

Mengecualikan `.env`, `vendor`, `node_modules`, hasil build lama, dan folder Git dari Docker build context. Dependency dibuat ulang di dalam image.

### `.env.docker.example`

Template environment deployment. Di VM, buat file lokal yang tidak di-commit:

```bash
cp .env.docker.example .env.docker
nano .env.docker
```

Konfigurasi database:

```env
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=pos
DB_USERNAME=pos
DB_PASSWORD=pos_password
```

`DB_HOST` harus `pgsql`, bukan `localhost`, karena PostgreSQL adalah service Docker lain.

## 7. Environment Laravel

Generate key dan simpan hasilnya ke `.env.docker`:

```bash
docker compose run --rm app php artisan key:generate --show
```

Jika output tidak terlihat, buat key dengan PHP:

```bash
docker compose exec app php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Isi file:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://127.0.0.1:8080
APP_KEY=base64:HASIL_KEY
```

Jangan meng-commit `.env.docker` atau membagikan `APP_KEY`.

## 8. Build dan menjalankan Docker

Dari `/opt/pos-app`:

```bash
docker compose build
docker compose up -d
docker compose ps
```

Status yang diharapkan:

```text
app       Up
nginx     Up
pgsql     Up (healthy)
worker    Up
```

`docker compose build` hanya membuat image. `docker compose up -d` membuat dan menjalankan container.

## 9. Inisialisasi database dan Laravel

```bash
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan optimize
```

Pada deployment pertama, `optimize:clear` dapat gagal jika tabel `cache` belum ada. Jalankan migration terlebih dahulu, kemudian ulangi `optimize:clear`.

## 10. Verifikasi

Health check Laravel:

```bash
curl --fail http://localhost:8080/up
```

Verifikasi response halaman:

```bash
curl -i http://localhost:8080/
```

Verifikasi vendor:

```bash
docker compose exec app sh -lc '
wc -c vendor/autoload.php
wc -c vendor/laravel/framework/src/Illuminate/Foundation/Application.php
'
```

Kedua nilai harus lebih besar dari `0`.

Buka dari browser Windows:

```text
http://127.0.0.1:8080
```

## 11. Update deployment setelah perubahan kode

Dari WSL:

```bash
cd /home/dps/projects/pos-app
git add .
git commit -m "Update application"
git push origin main
```

Di VM:

```bash
cd /opt/pos-app
git pull origin main
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize
```

Jika hanya konfigurasi environment berubah, recreate service terkait:

```bash
docker compose up -d --force-recreate app worker nginx
```

## 12. Troubleshooting yang ditemui

### DNS atau koneksi VM

Pastikan Adapter NAT aktif dan `Cable Connected` aktif. Tes:

```bash
ping -c 3 8.8.8.8
ping -c 3 google.com
```

### Docker mengakses IPv6 dan gagal

Error seperti `connection refused` atau `cannot assign requested address` pada alamat IPv6 menunjukkan jalur IPv6 tidak tersedia:

```bash
sudo tee /etc/sysctl.d/99-disable-ipv6.conf >/dev/null <<'EOF'
net.ipv6.conf.all.disable_ipv6 = 1
net.ipv6.conf.default.disable_ipv6 = 1
net.ipv6.conf.lo.disable_ipv6 = 1
EOF
sudo sysctl --system
sudo systemctl restart docker
```

Opsional, atur DNS Docker di `/etc/docker/daemon.json`:

```json
{
  "dns": ["8.8.8.8", "1.1.1.1"]
}
```

Uji image sebelum Compose build:

```bash
docker pull node:22-bookworm
docker pull composer:2
docker pull php:8.3-cli-bookworm
docker pull php:8.3-fpm-bookworm
```

### `no space left on device`

Periksa dan bersihkan cache yang tidak dipakai:

```bash
df -h /
docker system df
docker builder prune -af
docker image prune -af
sudo apt clean
```

Jangan gunakan `docker system prune --volumes` jika database ingin dipertahankan.

### Font remote timeout saat `npm run build`

Build awal mencoba mengakses `fonts.bunny.net`. Konfigurasi Vite sudah diubah agar tidak mengambil font remote, sehingga build tidak bergantung pada layanan font eksternal.

### `php: not found` saat Wayfinder

Wayfinder menjalankan Artisan dari proses Vite. Stage frontend harus memiliki PHP CLI dan `vendor` Composer.

### PHP 8.2 saat Wayfinder

Project mensyaratkan PHP `>= 8.3`. Stage frontend menggunakan `php:8.3-cli-bookworm`, bukan PHP CLI default Debian 8.2.

### npm `Cannot find module '../lib/cli.js'`

Node sebelumnya disalin sebagian. Dockerfile sekarang menyalin seluruh `/usr/local/` dari image Node agar file internal npm ikut tersedia.

### `Class "Illuminate\\Foundation\\Application" not found`

Vendor Composer tidak lengkap, biasanya akibat build berhenti ketika disk penuh. Rebuild image setelah ruang disk tersedia dan pastikan validasi ukuran file vendor berhasil.

### `worker` berstatus `Restarting`

Periksa log:

```bash
docker compose logs --tail=100 worker
```

Pastikan migration sudah dijalankan dan tabel `jobs` sudah tersedia.

## 13. Perintah operasional penting

```bash
# Status service
docker compose ps

# Log semua service
docker compose logs --tail=100

# Log service tertentu
docker compose logs -f app
docker compose logs -f worker

# Restart tanpa rebuild
docker compose restart

# Hentikan container, pertahankan volume
docker compose down

# Masuk ke container aplikasi
docker compose exec app sh

# Cek database migration
docker compose exec app php artisan migrate:status
```

## Hasil akhir

Deployment lokal dianggap berhasil jika:

1. `docker compose build` selesai dengan status `Built` untuk `app`, `nginx`, dan `worker`.
2. `docker compose ps` menunjukkan semua service berjalan.
3. PostgreSQL berstatus `healthy`.
4. Worker berstatus `Up`, bukan `Restarting`.
5. Migration selesai.
6. `curl --fail http://localhost:8080/up` berhasil.
7. Browser dapat membuka `http://127.0.0.1:8080`.
