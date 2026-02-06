# UMT Rozpis (PHP + SQLite)

Minimalistická webová aplikace pro rezervace umělé trávy (UMT) s potvrzením e-mailu a administrací. Bez frameworků.

## Požadavky
- PHP 8.2+
- SQLite3 extension
- Composer (jen pokud chcete PHPMailer)
- SMTP účet pro odesílání e-mailů

## Instalace
1. Vytvořte datový adresář mimo web root (v projektu je `data/` jako výchozí).
2. Nainstalujte závislosti (volitelné, doporučeno):

```bash
composer install --no-dev --optimize-autoloader
```

3. Spusťte migrace:

```bash
php scripts/migrate.php
```

4. Vygenerujte hash hesla administrátora:

```bash
php scripts/gen_admin_hash.php "VašeHeslo"
```

5. Nastavte environment proměnné (např. v `.env` nebo v konfiguraci serveru):

```text
APP_SECRET=...dlouhý náhodný řetězec...
ADMIN_PASSWORD_HASH=...výstup z gen_admin_hash.php...
DB_PATH=/absolute/path/to/data/mrbs.sqlite
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=...
SMTP_PASS=...
SMTP_FROM_EMAIL=rezervace@example.com
SMTP_FROM_NAME=UMT Rezervace
SMTP_SECURE=tls
SPACE_LABEL_A=Půlka A (tribuna)
SPACE_LABEL_B=Půlka B (les)
```

## Spuštění lokálně
Použijte PHP vestavěný server pouze pro vývoj:

```bash
php -S 127.0.0.1:8080 -t public
```

## Struktura
- `public/` – veřejný web root
- `app/` – logika aplikace
- `data/` – SQLite databáze (mimo web root)
- `scripts/` – migrace a helpery

## Nginx + PHP-FPM (příklad)
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

## Bezpečnostní checklist
- Používejte HTTPS a správné `session.cookie_secure`.
- Udržujte `data/` mimo web root.
- Nastavte přísná práva souborů: kód jen pro čtení, DB jen pro PHP-FPM uživatele.
- Zapněte fail2ban / rate limiting na web serveru.
- Pravidelné zálohy SQLite DB.
- Monitorujte `audit_log`.

## Poznámky k bezpečnosti
Aplikace používá CSRF tokeny, rate limiting, transakční kontrolu konfliktů a hlavičky pro hardening. Vždy však zvažte doplňkové vrstvy ochrany (WAF, síťové limity, logování).

