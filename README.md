# BE - Laravel + Docker (MVP Bootstrap)

Mục tiêu: chạy backend local chỉ với Docker, không cần cài PHP/Composer/PostgreSQL trên máy.

## 1. Yêu cầu tối thiểu
- Docker Desktop (hoặc Docker Engine + Docker Compose plugin)

## 2. Quy ước cấu hình (rất quan trọng)
Project dùng 2 file `.env` cho 2 mục đích khác nhau:
- `kado-timesheet-api/.env`: cấu hình cho Docker Compose (port, DB container, auto setup, admin mặc định).
- `kado-timesheet-api/src/.env`: cấu hình runtime của Laravel app.

Thứ tự ưu tiên khi chạy bằng Docker:
1. Biến env inject từ `docker-compose.yml` (ưu tiên cao nhất).
2. Giá trị trong `src/.env`.

Kết luận:
- Không xóa file nào, cả hai đều cần.
- Khi deploy AWS, không dùng file `.env` trong repo làm nguồn chính; dùng env từ hạ tầng (ECS/EC2/SSM/Secrets Manager).

## 3. Khởi động local (chuẩn cho team mới)
```bash
cd kado-timesheet-api
cp .env.example .env
cp src/.env.example src/.env
docker compose up -d --build
```

Sau khi chạy:
- API/Laravel: [http://localhost:8080](http://localhost:8080)
- PostgreSQL: `localhost:5432`

## 4. Cách hoạt động auto setup
- Container `app` sẽ tự kiểm tra thư mục `kado-timesheet-api/src`.
- Nếu chưa có Laravel source (`artisan` chưa tồn tại), hệ thống tự chạy:
  - `composer create-project laravel/laravel .`
- Sau đó tự:
  - tạo `.env` (nếu thiếu)
  - generate `APP_KEY`
  - chạy `php artisan migrate` (nếu `AUTO_MIGRATE=true`)

Vì vậy lần đầu chỉ cần `docker compose up -d --build`.

## 5. Lệnh thường dùng
```bash
# xem log
docker compose logs -f app
docker compose logs -f nginx
docker compose logs -f db

# artisan
docker compose exec app php artisan list
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed

# composer
docker compose exec app composer install
docker compose exec app composer require tymon/jwt-auth

# dừng hệ thống
docker compose down

# dừng và xóa volume DB
docker compose down -v
```

## 6. Cấu hình chính
Trong file `kado-timesheet-api/.env`:
- `APP_PORT`: cổng web local (mặc định 8080)
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `DB_EXTERNAL_PORT`: cổng DB local
- `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_FULL_NAME`: tài khoản admin mặc định khi seed DB
- `AUTO_SETUP=true|false`
- `AUTO_MIGRATE=true|false`

Trong file `kado-timesheet-api/src/.env` (Laravel runtime):
- `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, ...

## 7. Checklist sau khi chạy (để kiểm tra nhanh)
```bash
cd kado-timesheet-api
docker compose ps
docker compose exec app php artisan migrate:status
docker compose exec db psql -U kado -d kado -c "SELECT id, full_name, email, role FROM users;"
```

Mặc định phải thấy user admin:
- `email = admin@kado.local`
- `role = admin`

## 8. Cấu trúc thư mục
```text
kado-timesheet-api/
  docker/
    nginx/default.conf
    php/local.ini
  scripts/
    entrypoint.sh
  src/                    # Laravel source code
  docker-compose.yml
  Dockerfile
  .env.example
```

## 9. Lưu ý cho team
- Không commit file `kado-timesheet-api/.env`.
- Không commit file `kado-timesheet-api/src/.env`.
- Source Laravel nằm trong `kado-timesheet-api/src`.
- Nếu cần reset sạch DB local: `docker compose down -v`.
- Tài liệu mapping env local/AWS: xem `docs/deployment-env.md`.
