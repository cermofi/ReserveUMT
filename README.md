# UMT Rozpis (PHP + SQLite)

Minimalistická webová aplikace pro rezervace umìlé trávy (UMT) s potvrzením e-mailu a administrací. Bez frameworkù.

## Poadavky
- PHP 8.2+
- SQLite3 extension
- Composer (jen pokud chcete PHPMailer)
- SMTP úèet pro odesílání e-mailù

## Instalace
1. Vytvoøte datovı adresáø mimo web root (v projektu je `data/` jako vıchozí).
2. Nainstalujte závislosti (volitelné, doporuèeno):

```bash
composer install --no-dev --optimize-autoloader
```

3. Spuste migrace:

```bash
php scripts/migrate.php
```

4. Vygenerujte hash hesla administrátora:

```bash
php scripts/gen_admin_hash.php "VašeHeslo"
```

5. Nastavte environment promìnné (napø. v `.env` nebo v konfiguraci serveru):

```text
APP_SECRET=...dlouhı náhodnı øetìzec...
ADMIN_PASSWORD_HASH=...vıstup z gen_admin_hash.php...
DB_PATH=/absolute/path/to/data/mrbs.sqlite
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=...
SMTP_PASS=...
SMTP_FROM_EMAIL=rezervace@example.com
SMTP_FROM_NAME=UMT Rezervace
SMTP_SECURE=tls
SPACE_LABEL_A=Pùlka A (tribuna)
SPACE_LABEL_B=Pùlka B (les)
```

## Spuštìní lokálnì
Pouijte PHP vestavìnı server pouze pro vıvoj:

```bash
php -S 127.0.0.1:8080 -t public
```

## Struktura
- `public/` – veøejnı web root
- `app/` – logika aplikace
- `data/` – SQLite databáze (mimo web root)
- `scripts/` – migrace a helpery

## Nginx + PHP-FPM (pøíklad)
```nginx
server {
    listen 443 ssl http2;
    server_name umt.example.com;

    root /var/www/umt/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    add_header X-Frame-Options DENY always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
}
```

## Bezpeènostní checklist
- Pouívejte HTTPS a správné `session.cookie_secure`.
- Udrujte `data/` mimo web root.
- Nastavte pøísná práva souborù: kód jen pro ètení, DB jen pro PHP-FPM uivatele.
- Zapnìte fail2ban / rate limiting na web serveru.
- Pravidelné zálohy SQLite DB.
- Monitorujte `audit_log`.

## Poznámky k bezpeènosti
Aplikace pouívá CSRF tokeny, rate limiting, transakèní kontrolu konfliktù a hlavièky pro hardening. Vdy však zvate doplòkové vrstvy ochrany (WAF, síové limity, logování).
