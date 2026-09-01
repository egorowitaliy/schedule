# Установка и обслуживание Schedule 1.0.1

Инструкция рассчитана на Debian 12 или Debian 13, nginx и PHP-FPM. Команды выполняются от `root`, если не указано иное.

## 1. Требования

- PHP 8.2 или новее;
- PHP-FPM и PHP CLI одной версии;
- расширения `PDO SQLite` и `mbstring`;
- nginx;
- SQLite 3;
- OpenSSL для создания одноразового ключа установки;
- действующий HTTPS-сертификат либо отдельный доверенный обратный прокси-сервер, который завершает TLS;
- `max_input_vars` в PHP-FPM не меньше `5000`, чтобы большие таблицы расписания сохранялись целиком.

Debian 12 обычно поставляет PHP 8.2. В Debian 13 или при использовании стороннего репозитория версия может отличаться. Имя службы и сокета в примерах замените на фактическое.

## 2. Установка PHP, nginx и SQLite

```bash
apt update
apt install nginx php-fpm php-cli php-sqlite3 php-mbstring sqlite3 openssl ca-certificates mc
php -v
php -m | grep -E '^(PDO|pdo_sqlite|sqlite3|mbstring)$'
systemctl list-unit-files 'php*-fpm.service'
ls -l /run/php/
```

Последние две команды покажут имя службы PHP-FPM и путь к сокету. Например, для стандартного Debian 12 это часто `php8.2-fpm.service` и `/run/php/php8.2-fpm.sock`.

Перед установкой проверьте ограничение количества полей формы:

Узнайте имя бинарного файла PHP-FPM из установленного пакета. Для Debian 12 это обычно `php-fpm8.2`. Проверить его значение можно так:

```bash
php-fpm8.2 -i | grep '^max_input_vars'
```

Это должна быть именно конфигурация PHP-FPM, а не только PHP CLI. Для Schedule рекомендуется значение не меньше `5000`. Измените параметр в конфигурации PHP-FPM соответствующей версии, например:

```bash
mcedit /etc/php/8.2/fpm/php.ini
```

Найдите или добавьте строку:

```ini
max_input_vars = 5000
```

После изменения перезапустите фактическую службу PHP-FPM, например `systemctl restart php8.2-fpm`. Если используется другая версия PHP, замените `8.2` на неё.

## 3. Проверка и распаковка Schedule

Поместите архив и файл контрольной суммы в один каталог, затем выполните:

```bash
sha256sum -c schedule-1.0.1.tar.gz.sha256
tar -tzf schedule-1.0.1.tar.gz | less

test ! -e /var/www/schedule || {
    echo 'Каталог /var/www/schedule уже существует. Для новой установки нужен пустой путь.'
    exit 1
}

tar -xzf schedule-1.0.1.tar.gz -C /var/www
```

Архив содержит один корневой каталог `schedule/`. Для новой установки не распаковывайте его поверх существующего приложения: старые файлы могут остаться на сервере.

## 4. Права каталогов

Код принадлежит `root`, а PHP-FPM получает право записи только в `storage/`:

```bash
chown -R root:root /var/www/schedule
find /var/www/schedule -type d -exec chmod 0755 {} +
find /var/www/schedule -type f -exec chmod 0644 {} +
install -d -m 0770 -o www-data -g www-data /var/www/schedule/storage
```

Не используйте `chmod 777`. Корневой папкой сайта будет `public/`, поэтому рабочая база не окажется в открытом доступе.

## 5. Настройка nginx

В комплекте есть два образца:

- `deploy/nginx.conf.example` — nginx сам обслуживает HTTPS;
- `deploy/nginx-reverse-proxy.conf.example` — TLS завершается на отдельном прокси-сервере.

Для обычной установки с TLS на этом сервере:

```bash
cp /var/www/schedule/deploy/nginx.conf.example /etc/nginx/sites-available/schedule.conf
mcedit /etc/nginx/sites-available/schedule.conf
ln -s /etc/nginx/sites-available/schedule.conf /etc/nginx/sites-enabled/schedule.conf
nginx -t
systemctl reload nginx
```

В файле замените доменное имя, пути сертификатов и `fastcgi_pass` на обнаруженный в разделе 2 сокет PHP-FPM. Не копируйте `/run/php/php8.2-fpm.sock` вслепую, если сервер использует другую версию PHP.

## 6. HTTPS

### Вариант A: TLS завершается на nginx

Основной образец слушает порт 443 и перенаправляет HTTP на HTTPS. Укажите реальные файлы сертификата и закрытого ключа, например созданные Certbot:

```nginx
ssl_certificate /etc/letsencrypt/live/schedule.example.org/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/schedule.example.org/privkey.pem;
```

Сначала получите сертификат подходящим для вашей инфраструктуры способом, затем проверьте `nginx -t`. Никогда не вводите пароль первого администратора по открытому HTTP.

### Вариант B: TLS завершается на отдельном прокси-сервере

Внешний посетитель всё равно должен подключаться только по HTTPS. Внутренний nginx можно оставить на HTTP, но доступ к нему разрешайте только адресу прокси-сервера.

Скопируйте второй образец, настройте закрытый адрес и межсетевой экран. Затем добавьте адрес прокси-сервера в `trusted_proxies`, как показано в следующем разделе. Schedule доверяет `X-Forwarded-Proto` и адресам клиента только от явно настроенного прокси-сервера.

На внешнем HTTPS-прокси передавайте приложению исходный адрес и протокол запроса. Для nginx это выглядит так:

```nginx
location / {
    proxy_set_header Host $server_name;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_pass http://192.0.2.20:8080;
}
```

Замените `192.0.2.20:8080` на внутренний адрес сервера Schedule. Не принимайте эти заголовки напрямую от посетителей в обход доверенного прокси-сервера.

## 7. Локальная конфигурация

Файл `inc/config.local.php` содержит только локальные параметры конкретного сервера. В архиве есть безопасный пример. Создавайте рабочий файл только при необходимости изменить часовой пояс или список доверенных прокси-серверов:

```bash
cp /var/www/schedule/inc/config.local.php.example /var/www/schedule/inc/config.local.php
mcedit /var/www/schedule/inc/config.local.php
```

Пример локальной конфигурации при прямой работе nginx с HTTPS:

```php
<?php
return [
    'app' => [
        'timezone' => 'Europe/Moscow',
    ],
    'proxy' => [
        'trusted_proxies' => [],
    ],
];
```

Пример записи доверенного прокси: `'trusted_proxies' => ['192.0.2.10', '2001:db8::10']`. Не добавляйте туда общедоступные сети и адреса посетителей.

После сохранения:

```bash
chown root:www-data /var/www/schedule/inc/config.local.php
chmod 0640 /var/www/schedule/inc/config.local.php
```

## 8. Включение установщика

Установщик не принимает данные без секретного одноразового ключа, который заранее создаётся на сервере:

```bash
schedule_install_token=$(openssl rand -hex 32)
printf '%s\n' "$schedule_install_token" > /var/www/schedule/storage/install.enabled
chown www-data:www-data /var/www/schedule/storage/install.enabled
chmod 0600 /var/www/schedule/storage/install.enabled
printf 'Одноразовый ключ установки: %s\n' "$schedule_install_token"
unset schedule_install_token
```

Не отправляйте этот ключ в публичные чаты и не храните его в истории задач.

## 9. Браузерная установка

Откройте только по HTTPS:

```text
https://schedule.example.org/install.php
```

Введите одноразовый ключ, имя первого администратора, логин и пароль длиной не менее 12 символов. Установка сначала создаётся во временной базе данных. Рабочей она становится только после успешной проверки. Если установка прервётся, повторный запуск не должен уничтожить уже существующие данные.

## 10. Демонстрационные данные

Флажок **«Создать демонстрационный набор данных»** необязателен и по умолчанию выключен.

Если хотите сразу посмотреть работу Schedule, отметьте этот флажок. Установщик добавит несколько вымышленных групп, преподавателей, дисциплин, аудиторий, сетку пар, учебные дни и пример расписания. Для рабочей установки обычно оставляйте флажок выключенным.

Демонстрационные данные создаются вместе со всей установкой: при ошибке неполный набор не публикуется.

## 11. Завершение установки

После успешной установки Schedule создаёт `storage/installed.lock` и удаляет `storage/install.enabled`. Проверьте:

```bash
test -f /var/www/schedule/storage/installed.lock
test ! -e /var/www/schedule/storage/install.enabled
ls -l /var/www/schedule/storage
```

Повторное открытие `install.php` не должно позволять создать ещё одного первого администратора.

## 12. Проверка приложения

```bash
nginx -t
systemctl status nginx --no-pager
systemctl status php8.2-fpm --no-pager
sqlite3 /var/www/schedule/storage/schedule.sqlite3 'PRAGMA integrity_check;'
sqlite3 /var/www/schedule/storage/schedule.sqlite3 'PRAGMA foreign_key_check;'
```

Замените `php8.2-fpm` на фактическую службу. Первая проверка SQLite должна вывести `ok`, вторая — ничего.

Откройте главную страницу, войдите в `/manage/`, при необходимости задайте название сайта и публичную шапку в разделе «Настройки», затем создайте тестовый день и убедитесь, что опубликованное расписание видно без входа.

## 13. Обслуживание старых дней

Команда удаления использует срок из настроек приложения:

```bash
runuser -u www-data -- php /var/www/schedule/tools/cleanup_old_days.php
```

Перед первым запуском сделайте резервную копию. Для регулярного выполнения используйте системный таймер или cron от имени `www-data`.

### Ротация журналов

Schedule пишет служебные сообщения в `storage/logs/`. Чтобы журналы не росли бесконечно, установите готовый пример `logrotate`:

```bash
cp /var/www/schedule/deploy/logrotate.conf.example /etc/logrotate.d/schedule
mcedit /etc/logrotate.d/schedule
logrotate -d /etc/logrotate.d/schedule
```

Если Schedule установлен не в `/var/www/schedule`, исправьте путь в файле. Пример ограничивает размер одного активного журнала и хранит несколько сжатых копий.

## 14. Резервное копирование

Создайте закрытый каталог один раз:

```bash
install -d -m 0700 -o root -g root /var/backups/schedule
```

Для каждой копии задавайте строгую маску прав и используйте команду SQLite `.backup`:

```bash
umask 077
schedule_backup_file="/var/backups/schedule/schedule-$(date -u +%Y%m%d-%H%M%S).sqlite3"
sqlite3 /var/www/schedule/storage/schedule.sqlite3 ".backup '$schedule_backup_file'"
chmod 0600 "$schedule_backup_file"
sqlite3 "$schedule_backup_file" 'PRAGMA integrity_check;'
sqlite3 "$schedule_backup_file" 'PRAGMA foreign_key_check;'
printf 'Резервная копия: %s\n' "$schedule_backup_file"
unset schedule_backup_file
```

Первая проверка должна вывести `ok`, вторая — ничего. Файлы копий должны оставаться с правами не шире `0600`. Храните дополнительную копию на отдельном защищённом носителе.

Если используется `inc/config.local.php`, сохраните его отдельную копию вместе с базой данных. Этот файл содержит локальные параметры сервера и не входит в архив выпуска:

```bash
if test -f /var/www/schedule/inc/config.local.php; then
    install -m 0600 -o root -g root \
        /var/www/schedule/inc/config.local.php \
        /var/backups/schedule/config.local.php
fi
```

## 15. Восстановление

Перед восстановлением временно остановите задания cron или systemd, которые запускают средства обслуживания Schedule, если вы их настраивали. Затем остановите PHP-FPM. В примерах ниже замените `php8.2-fpm` на фактическое имя службы.

```bash
systemctl stop php8.2-fpm
```

Перед заменой базы создайте контрольную копию текущего состояния и проверьте её:

```bash
install -d -m 0700 -o root -g root /var/backups/schedule
umask 077
sqlite3 /var/www/schedule/storage/schedule.sqlite3 \
  ".backup '/var/backups/schedule/before-restore.sqlite3'"
chmod 0600 /var/backups/schedule/before-restore.sqlite3
sqlite3 /var/backups/schedule/before-restore.sqlite3 'PRAGMA integrity_check;'
sqlite3 /var/backups/schedule/before-restore.sqlite3 'PRAGMA foreign_key_check;'
```

Первая проверка должна вывести `ok`, вторая — ничего. После этого установите заранее проверенную резервную копию:

```bash
rm -f \
  /var/www/schedule/storage/schedule.sqlite3-wal \
  /var/www/schedule/storage/schedule.sqlite3-shm

install -m 0600 -o www-data -g www-data \
  /var/backups/schedule/НУЖНАЯ-КОПИЯ.sqlite3 \
  /var/www/schedule/storage/schedule.sqlite3

runuser -u www-data -- php /var/www/schedule/tools/migrate.php
sqlite3 /var/www/schedule/storage/schedule.sqlite3 'PRAGMA integrity_check;'
sqlite3 /var/www/schedule/storage/schedule.sqlite3 'PRAGMA foreign_key_check;'
```

Не запускайте PHP-FPM, если `integrity_check` не вывел `ok` или `foreign_key_check` вывел нарушения. После успешной проверки запустите PHP-FPM и снова включите задания обслуживания Schedule:

```bash
systemctl start php8.2-fpm
```

## 16. Обновление

Версия 1.0.1 является первой публикуемой сборкой, но эта последовательность подходит для последующих архивов Schedule.

1. Сделайте резервную копию по разделу 14 и убедитесь, что проверки прошли.
2. Проверьте контрольную сумму нового архива.
3. Подготовьте новый код отдельно, пока приложение продолжает работать:

```bash
test ! -e /var/www/schedule-new || { echo 'Каталог /var/www/schedule-new уже существует; используйте новый пустой каталог.'; exit 1; }
install -d -m 0755 -o root -g root /var/www/schedule-new
tar -xzf /root/schedule-1.0.1.tar.gz --strip-components=1 -C /var/www/schedule-new
chown -R root:root /var/www/schedule-new
find /var/www/schedule-new -type d -exec chmod 0755 {} +
find /var/www/schedule-new -type f -exec chmod 0644 {} +
install -d -m 0770 -o www-data -g www-data /var/www/schedule-new/storage
```

4. Если для Schedule настроены задания cron или systemd, временно приостановите их. Затем остановите PHP-FPM. Только после этого переносите рабочие данные:

```bash
systemctl stop php8.2-fpm
cp -a /var/www/schedule/storage/. /var/www/schedule-new/storage/
if test -f /var/www/schedule/inc/config.local.php; then
    cp -a /var/www/schedule/inc/config.local.php /var/www/schedule-new/inc/config.local.php
fi
chown -R www-data:www-data /var/www/schedule-new/storage
chmod 0770 /var/www/schedule-new/storage
```

5. Выполните миграцию и проверки до переключения nginx на новый код:

```bash
runuser -u www-data -- php /var/www/schedule-new/tools/migrate.php
sqlite3 /var/www/schedule-new/storage/schedule.sqlite3 'PRAGMA integrity_check;'
sqlite3 /var/www/schedule-new/storage/schedule.sqlite3 'PRAGMA foreign_key_check;'
```

6. Если проверки успешны, переключите каталоги и запустите PHP-FPM:

```bash
schedule_old_dir="/var/www/schedule-old-$(date -u +%Y%m%d-%H%M%S)"
mv /var/www/schedule "$schedule_old_dir"
mv /var/www/schedule-new /var/www/schedule
systemctl start php8.2-fpm
nginx -t
systemctl reload nginx
printf 'Предыдущий каталог: %s\n' "$schedule_old_dir"
unset schedule_old_dir
```

7. Проверьте вход, публичную страницу, создание и изменение расписания. После успешной проверки снова включите задания обслуживания Schedule, если они были приостановлены. Не удаляйте предыдущий каталог и резервную копию, пока не убедитесь в нормальной работе.

Если миграция или проверка не прошла, не запускайте новый код. Верните предыдущий каталог и восстановите проверенную резервную копию.
