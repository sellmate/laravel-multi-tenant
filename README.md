# laravel-multi-tenant

테넌트마다 별도 데이터베이스를 두는 Laravel 멀티테넌시 패키지입니다.

- system DB의 `tenants` 테이블이 테넌트 목록과 테넌트 DB 접속 위치를 관리합니다.
- 요청의 라우트 파라미터(`{domain}`)로 테넌트를 찾아 `tenant` 연결을 그 테넌트 DB로 바꿉니다.
- `migrate`·`db:seed`·`make:*` 명령에 `--tenant`/`--domain` 옵션을 더하고, 테넌트 DB·계정을 만들고 지우는 명령을 제공합니다.
- Laravel Passport의 클라이언트·토큰을 테넌트 DB에 저장합니다.

## 요구 사항

| 패키지 | 버전 |
|---|---|
| PHP | ^8.3 |
| laravel/framework | ^13.0 |
| laravel/passport | ^13.0 |

Laravel 12 / Passport 12는 `v12.x` 태그를 사용하세요.

## 설치

```bash
composer require sellmate/laravel-multi-tenant
php artisan vendor:publish --provider="Sellmate\Laravel\MultiTenant\Providers\MultiTenantServiceProvider"
```

`MultiTenantServiceProvider`는 패키지 자동 등록으로 로드됩니다. publish하면 다음 파일이 생깁니다.

| 파일 | 내용 |
|---|---|
| `config/multitenancy.php` | 패키지 설정 |
| `database/migrations/system/..._create_tenants_table.php` | system DB의 `tenants` 테이블 |
| `app/Models/System/Tenant.php` | 테넌트 모델 (패키지가 이 클래스를 사용) |
| `stubs/model.stub`, `stubs/model.pivot.stub`, `stubs/seeder.stub` | `make:model`·`make:seeder` 스텁 |

## 데이터베이스 연결

`config/database.php`에 연결 세 개를 정의합니다. 이름은 설정으로 바꿀 수 있습니다.

| 연결 | 용도 |
|---|---|
| `system` | `tenants` 테이블이 있는 공용 DB |
| `tenant_admin` | 테넌트 DB와 계정을 만들고 지우는 관리자 계정. `host`/`port`는 테넌트의 `db_host`/`db_port`로 바뀝니다 |
| `tenant` | 요청·명령이 사용하는 테넌트 연결. `tenant_admin` 설정을 바탕으로 테넌트 DB·계정·비밀번호가 채워집니다 |

```php
'connections' => [
    'system' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'database' => env('DB_DATABASE', 'system'),
        'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),
        // ...
    ],
    'tenant_admin' => [
        'driver' => 'mysql',
        'username' => env('TENANT_ADMIN_USERNAME'),
        'password' => env('TENANT_ADMIN_PASSWORD'),
        // ...
    ],
    'tenant' => [
        'driver' => 'mysql',
        // 실행 시점에 채워집니다.
    ],
],
```

### 테넌트 DB 이름·계정·비밀번호

기본값은 다음과 같고, `App\Models\System\Tenant`에 `getDatabaseName()`·`getDatabaseUsername()`·`getDatabasePassword()`를 정의하면 바꿀 수 있습니다.

| 항목 | 기본값 |
|---|---|
| DB 이름·계정 | `<앱 이름 소문자, 공백→_>_<domain>` (예: `my_app_alpha`) |
| 비밀번호 | `sha1(<id><domain><key>)` |

`<key>`는 `multitenancy.key`(`MULTITENANCY_KEY`), 비어 있으면 `APP_KEY`입니다. **둘 다 없으면 테넌트 연결 시 `TenantDatabaseException`이 발생합니다.** 키를 바꾸면 기존 테넌트 계정의 비밀번호와 맞지 않게 되니 주의하세요.

DB 이름·계정은 영문자·숫자·`_`·`-`만 허용합니다(SQL Server는 `.`도 허용). 지원하는 드라이버는 MySQL, SQL Server, SQLite입니다.

## 라우트와 미들웨어

테넌트 식별자를 라우트 파라미터로 받는 그룹에 미들웨어를 겁니다.

```php
use Sellmate\Laravel\MultiTenant\Middleware\HandleTenantConnection;
use Sellmate\Laravel\MultiTenant\Middleware\RemoveTenantIdParameter;

Route::prefix('{domain}')
    ->middleware([HandleTenantConnection::class, RemoveTenantIdParameter::class])
    ->group(function () {
        // 테넌트 라우트
    });
```

- `HandleTenantConnection`: `tenants`에서 테넌트를 찾아 `tenant` 연결을 설정하고 기본 연결로 바꿉니다. 테넌트가 없으면 404입니다.
- `RemoveTenantIdParameter`: 컨트롤러 인자로 넘어가지 않도록 테넌트 파라미터를 지웁니다.

파라미터 이름(`tenant-id-parameter`)과 조회 컬럼(`tenant-id-column`)은 기본값이 `domain`이며 설정으로 바꿀 수 있습니다.

## Artisan 명령

### 테넌트 DB 생성·삭제

```bash
php artisan tenant:setup --domain=alpha --migrate   # DB·계정 생성 후 마이그레이션
php artisan tenant:destroy --domain=alpha           # 계정·DB 삭제
```

`tenant:setup`은 `setup_has_done`이 false인 테넌트만, 나머지 테넌트 명령은 true인 테넌트만 대상으로 합니다. `setup_has_done`은 DB·계정이 만들어졌다는 뜻이라, `--migrate`가 실패해도 true로 남고 명령만 실패합니다. 이때는 `migrate --tenant --domain=<domain>`으로 다시 시도하거나 `tenant:destroy`로 정리하세요. `APP_ENV=production`에서 `--migrate`는 관리 비밀번호를 묻습니다.

### `--tenant`·`--domain` 옵션

| 명령 | 추가 옵션 |
|---|---|
| `migrate`, `migrate:install`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` | `-T`/`--tenant`, `--domain`, `--without-root` (`migrate`는 `--secret`도) |
| `migrate:status`, `db:seed` | `-T`/`--tenant`, `--domain` |
| `make:migration`, `make:model` | `-S`/`--system`, `-T`/`--tenant` (`make:migration`은 `--database`도) |
| `make:seeder` | `-T`/`--tenant` |
| `passport:client`, `passport:install`, `passport:hash`, `passport:purge` | `-T`/`--tenant`, `--domain` |

`--domain`은 쉼표로 여러 테넌트를 지정할 수 있고, 비우거나 `all`이면 전체 테넌트입니다.

```bash
php artisan migrate                              # system DB
php artisan migrate --tenant --domain=all        # 모든 테넌트
php artisan migrate --tenant --domain=alpha,beta
php artisan make:migration create_orders_table --tenant
```

### 마이그레이션 경로

| 대상 | 경로 |
|---|---|
| 공통(root) | `database/migrations` |
| system | `database/migrations/system` |
| tenant | `database/migrations/tenant` |

기본으로 공통 경로와 대상 경로를 함께 실행합니다. `--without-root`를 주거나 연결 이름이 `without-root` 설정에 있으면 대상 경로만 실행합니다.

### 실행 환경 검증

`migrate`·`migrate:refresh|reset|rollback`·`tenant:destroy`는 실행 전에 대상 DB를 검사합니다.

- `APP_ENV=production`: 관리 비밀번호를 묻습니다. `migrate`는 `--secret`으로 넘길 수 있습니다.
- 그 외 환경: 연결 host가 `dev-host-list`(`MULTITENANCY_TENANT_DEV_HOST_LIST`, 쉼표 구분)에 있어야 합니다. 연결에 `url`이 있으면 URL의 host로 판단하고, SQLite는 항상 허용합니다.

검증에 실패하거나 대상 테넌트가 없으면 명령은 0이 아닌 종료 코드로 끝납니다. `migrate --graceful`도 검증 실패는 실패로 남깁니다.

## Laravel Passport

1. Passport 자동 등록을 끄고 패키지 프로바이더를 등록합니다.

   ```json
   "extra": {
       "laravel": {
           "dont-discover": ["laravel/passport"]
       }
   }
   ```

   ```php
   // bootstrap/providers.php
   return [
       // ...
       Sellmate\Laravel\MultiTenant\Providers\PassportServiceProvider::class,
   ];
   ```

2. OAuth 라우트를 테넌트 라우트 그룹 안에 등록합니다. 패키지 프로바이더는 OAuth 라우트를 전역으로 등록하지 않습니다(전역 라우트는 system DB에서 토큰을 발급·조회하게 됩니다).

   ```php
   Route::prefix('{domain}')
       ->middleware([HandleTenantConnection::class, RemoveTenantIdParameter::class])
       ->group(function () {
           Route::group([
               'as' => 'passport.',
               'prefix' => 'oauth',
               'namespace' => 'Laravel\Passport\Http\Controllers',
               'middleware' => config('passport.middleware', []),
           ], base_path('vendor/laravel/passport/routes/web.php'));
       });
   ```

3. **`passport.connection`(`PASSPORT_CONNECTION`)은 비워 두세요.** Passport 모델은 이 값이 없을 때 기본 연결(= 요청의 테넌트 연결)을 씁니다. 값을 정하면 모든 테넌트의 클라이언트·토큰이 그 DB 하나에 저장됩니다.

4. 테넌트별 클라이언트를 만듭니다. 키(`passport:keys`)는 테넌트와 무관하게 한 번만 만듭니다.

   ```bash
   php artisan passport:keys
   php artisan migrate --tenant --path=vendor/laravel/passport/database/migrations
   php artisan passport:install --tenant --domain=all   # personal access·password grant 클라이언트
   php artisan passport:client --tenant --domain=alpha --client --name="alpha"
   ```

   password grant는 Passport 12부터 기본으로 꺼져 있습니다. `passport:install --tenant`가 만드는 password grant 클라이언트를 쓰려면 `AppServiceProvider::boot()`에서 `Passport::enablePasswordGrant()`를 호출하세요.

5. 만료·폐기 토큰 정리도 테넌트별로 실행합니다.

   ```php
   // routes/console.php
   Schedule::command('passport:purge --tenant')->daily();
   ```

## 설정

| 키 | 환경변수 | 기본값 |
|---|---|---|
| `key` | `MULTITENANCY_KEY` | `APP_KEY` |
| `system-connection` | `MULTITENANCY_SYSTEM_CONNECTION` | `system` |
| `tenant-connection` | `MULTITENANCY_TENANT_CONNECTION` | `tenant` |
| `tenant-admin-connection` | `MULTITENANCY_TENANT_ADMIN_CONNECTION` | `tenant_admin` |
| `tenant-id-column` | `MULTITENANCY_TENANT_ID_COLUMN` | `domain` |
| `tenant-id-parameter` | `MULTITENANCY_TENANT_ID_PARAMETER` | `domain` |
| `without-root` | — | `[tenant-admin-connection 이름]` (루트 마이그레이션을 건너뛸 연결 목록) |
| `dev-host-list` | `MULTITENANCY_TENANT_DEV_HOST_LIST` | (없음) |
| `skip-migrations-in-parallel-tests` | `PARALLEL_TEST_SKIP_MIGRATIONS` | `false` |

publish하지 않은 키도 패키지 기본값이 적용됩니다.

## 12.x에서 업그레이드

- PHP 8.3, Laravel 13, Passport 13이 필요합니다.
- `MULTITENANCY_KEY`와 `APP_KEY`가 모두 없으면 예외가 납니다(이전에는 고정 키로 대체).
- `passport:client --user_id`, `passport:install --uuids`가 없어졌습니다(Passport 13에서 제거).
- `Sellmate\Laravel\MultiTenant\RefreshTokenRepository`는 deprecated입니다. Passport 기본 저장소가 같은 동작을 합니다.
- 명령 실패(대상 테넌트 없음, 환경 검증 실패)는 0이 아닌 종료 코드를 반환합니다.
- `migrate:install|refresh|reset|rollback`의 `--without-root`는 값 없는 플래그입니다.
- `passport:install --tenant`는 키를 만들지 않습니다. `passport:keys`를 따로 실행하세요.

### Passport 12 → 13 (테넌트 DB마다 적용)

[Passport 13 업그레이드 가이드](https://github.com/laravel/passport/blob/13.x/UPGRADE.md)의 DB 관련 단계는 system DB가 아니라 **각 테넌트 DB**에 적용해야 합니다.

1. **클라이언트 secret 해시.** Passport 13은 secret을 항상 해시로 검증하므로, 평문 secret이 남은 테넌트는 클라이언트 인증이 실패합니다.

   ```bash
   php artisan passport:hash --tenant --domain=all           # 한 번 확인을 묻습니다
   php artisan passport:hash --tenant --domain=all --force   # 배포 스크립트처럼 묻지 못하는 환경
   ```

   묻지 못하는 환경에서 `--force` 없이 실행하면 아무것도 해시하지 않고 실패(0이 아닌 종료 코드)합니다.

2. **(선택) `oauth_clients` 스키마 변경.** 새 스키마는 하위 호환이라 필수는 아니지만, 정수 클라이언트 ID를 쓰면 가이드가 강하게 권장합니다(일부 토큰 요청이 401로 실패). 적용한다면 가이드의 마이그레이션(`owner_type`·`owner_id`, `redirect_uris`, `grant_types`)을 `database/migrations/tenant`에 두고 테넌트에 실행합니다. 컬럼을 지우는 되돌릴 수 없는 변경이니 백업 후 실행하세요. 더 이상 쓰지 않는 `oauth_personal_access_clients`도 같은 방식으로 지울 수 있습니다.

   ```bash
   php artisan migrate --tenant --domain=all
   ```

3. **정수 클라이언트 ID 유지.** Passport 13은 기본으로 UUID 클라이언트 ID를 씁니다. 기존 테넌트의 정수 ID를 유지하려면 `Passport::$clientUuids = false;`를 `AppServiceProvider::boot()`에 둡니다.

그 밖의 앱 측 변경(User 모델의 `OAuthenticatable` 구현, 미들웨어 이름 변경, 인가 화면 제거, 키 파일 권한 검증)은 가이드를 따르세요.

## 테스트

```bash
composer install
vendor/bin/phpunit
```

테스트는 SQLite만 사용합니다.
