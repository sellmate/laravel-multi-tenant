# SQL Server 드라이버 통합 테스트 (PDO)

`tests/Integration/SqlServerDriverTest.php` 는 `SqlServerDriver::setup()`·`destroy()` 를 **실제 Laravel 연결과 `pdo_sqlsrv`** 로 SQL Server 에 실행합니다. `tests/Unit/SqlServerDriverTest.php` 는 생성 SQL 문자열만, `tests/sqlserver/destroy-rerun/` 은 같은 SQL 을 `sqlcmd` 로만 확인하므로, PHP 실행 경로는 이 테스트가 확인합니다.

`pdo_sqlsrv` 가 없거나 접속 환경변수가 없으면 건너뜁니다(기본 `vendor/bin/phpunit` 실행에 영향 없음).

## 실행

```bash
SQLSRV_SA_PASSWORD='<임의의 강한 비밀번호>' sh tests/sqlserver/integration/run.sh
```

`run.sh` 가 하는 일:

1. 전용 Docker 네트워크에 SQL Server 2022 컨테이너를 띄움(호스트에 포트를 공개하지 않음)
2. `Dockerfile` 로 PHP 8.4 + `pdo_sqlsrv` 이미지 빌드
3. 저장소를 마운트한 PHP 컨테이너에서 `vendor/bin/phpunit --group sqlserver` 실행

비밀번호는 환경변수 이름으로만 컨테이너에 넘겨 명령줄에 남지 않습니다. 컨테이너와 네트워크는 다시 실행할 때 재사용하며, 다 쓴 뒤 스크립트 마지막에 안내하는 명령으로 지웁니다. Apple Silicon 에서는 두 컨테이너 모두 에뮬레이션(amd64)으로 동작합니다.

`vendor/` 는 호스트의 것을 그대로 쓰므로 먼저 `composer install` 이 되어 있어야 합니다. 잠긴 개발 의존성이 PHP 8.4.1 이상을 요구해 이미지도 PHP 8.4 를 씁니다.

## 확인하는 것

| 테스트 | 내용 |
|---|---|
| setup | DB·로그인 생성, 테넌트 로그인으로 그 DB 접속 |
| destroy 재실행 | 전체 삭제 후 다시 실행해도 오류 없음 |
| 접속 중 세션 | 테넌트 로그인이 접속한 상태에서 삭제, 그 연결은 끊김 |
| 부분 실패 후 재실행 | 로그인만 / DB 만 지워진 상태에서 정리 |
| 연결 상태 | setup·destroy 후 관리 연결의 현재 DB 가 `master` |
| 단일 사용자 자리 점유 | 다른 세션이 단일 사용자 모드 DB 의 자리를 잡고 있어도 destroy 가 정리 |
| (관찰) 5064 → 5069 | 자리 경합 시 `TRY/CATCH` 가 보는 오류 번호는 5069 → `destroy()` 재시도 조건의 근거 |
| 권한 부족 거부 | 권한 없는 로그인으로 destroy 하면 권한 확인에서 `TenantDatabaseException`, DB·로그인은 그대로이고 로그인도 비활성화되지 않음 |
| 최소 권한 관리자 | sysadmin 이 아닌 `securityadmin`·`processadmin`·`dbcreator` 로그인으로 자기가 만든 테넌트를 setup·destroy 할 수 있음 |
| 남의 DB 거부 | 같은 로그인이 남이 만든 테넌트 DB 는 지우지 못함(`dbcreator` 에는 ALTER ANY DATABASE 가 없음, SQL Server 2022 에서 확인) |
| (관찰) 배치 뒤쪽 오류 | Laravel `Connection::statement()` 는 배치의 첫 문장 이후 오류를 알리지 않음 → 드라이버가 PDO 로 직접 실행해 결과셋을 끝까지 읽는 이유 |
| (관찰) 메타데이터 가시성 | 권한 없는 로그인에게는 다른 로그인이 보이지 않음 → `destroy()` 가 먼저 권한을 확인하는 이유 |

**확인하지 않는 것:** 세션이 조회와 `KILL` 사이에 스스로 끝나는 경합(6106 무시 경로)과, 세션을 끊은 직후 다른 세션이 단일 사용자 자리를 다시 잡아 재시도가 실제로 일어나는 경로. 재현이 비결정적입니다.
