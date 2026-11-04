# Instalasi

## Prasyarat
    sudo apt install -y php8.3-fpm php8.3-pgsql postgresql nginx apache2-utils

## 1. PostgreSQL
    sudo -u postgres psql -c "CREATE USER db_creator WITH PASSWORD 'ganti-kuat';"
    sudo -u postgres psql -c "ALTER USER db_creator CREATEDB CREATEROLE;"

## 2. Adminer
    sudo mkdir -p /var/www/adminer
    sudo wget -O /var/www/adminer/index.php https://www.adminer.org/latest.php
    sudo cp php/register.php /var/www/adminer/
    sudo chown -R www-data:www-data /var/www/adminer

## 3. Environment (password db_creator)
    sudo cp php/env.example /etc/php/8.3/fpm/pool.d/adminer-env.conf
    sudo nano /etc/php/8.3/fpm/pool.d/adminer-env.conf
    sudo chmod 600 /etc/php/8.3/fpm/pool.d/adminer-env.conf
    sudo systemctl reload php8.3-fpm

## 4. Nginx
    # Tambahkan blok dari nginx/adminer.conf ke /etc/nginx/sites-available/moodle
    sudo htpasswd -cb /etc/nginx/.htpasswd-adminer admin 'password-kuat'
    sudo nginx -t && sudo systemctl reload nginx
