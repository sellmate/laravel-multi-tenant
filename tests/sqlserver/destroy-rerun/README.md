# SQL Server `destroy` 검증

`SqlServerDriver::destroy()` 가 실행하는 SQL 을 실제 SQL Server 에서 확인하는 스크립트입니다. PHP 테스트(`tests/Unit/SqlServerDriverTest.php`)는 SQL 문자열만 검증합니다.

드라이버의 SQL 을 바꾸면 `SqlServerDriver::destroy()`, `SqlServerDriverTest::destroyStatements()`, `step-*.sql` 을 함께 바꾸고 다시 실행하세요.

| 파일 | 내용 |
|---|---|
| `step-1-disable.sql` … `step-4-drop-login.sql` | `destroy()` 의 각 단계(이름만 `lmt_t24`) |
| `destroy.sql` | 네 단계를 순서대로 실행 |
| `setup.sql` | `setup()` 이 남기는 상태(DB·로그인·DB 사용자) |
| `assert-gone.sql` | DB·로그인이 남아 있으면 오류 |
| `verify.sql` | 순차 재실행 시나리오 S1~S4 |
| `run.sh` | 전체 실행: S1~S4, 동시 세션 시나리오 S5~S7(백그라운드 세션 사용), 가정 확인 |
| `probe.sql` | 드라이버가 기대는 SQL Server 동작 확인(오류가 관찰 결과) |

## 시나리오

| | 상황 | 기대 |
|---|---|---|
| S1 | 설정된 테넌트 전체 삭제 | DB·로그인 없음 |
| S2 | 전부 없는 상태에서 재실행 | 오류 없음 |
| S3 | 로그인만 삭제되고 DB(와 DB 사용자)가 남음 | 재실행으로 정리 |
| S4 | DB 만 없고 로그인이 남음 | 재실행으로 정리 |
| S5 | 테넌트 로그인이 접속 중 | 세션을 끊고 정리 |
| S6 | 단일 사용자 모드 DB 의 한 자리를 다른 세션이 차지 | 그 세션을 끊고 정리(`ROLLBACK IMMEDIATE` 만으로는 Msg 5064 로 실패해 DB 안 세션을 먼저 `KILL`) |
| S7 | 비활성화 후 테넌트 로그인으로 재접속 시도 | 접속 거부 |

**검증하지 않는 것:** 세션이 조회와 `KILL` 사이에 스스로 끝나는 경합(재현이 비결정적). 이때 나는 오류 번호는 `probe.sql` P1 로 확인합니다.

## 실행

로컬 전용 컨테이너에서 실행합니다(포트를 공개하지 않음). Apple Silicon 에서는 에뮬레이션으로 동작합니다.

```bash
(umask 077; printf 'ACCEPT_EULA=Y\nMSSQL_SA_PASSWORD=%s\nSQLCMDPASSWORD=%s\n' "$PW" "$PW" > /tmp/mssql.env)  # PW: 임의의 강한 비밀번호
docker run -d --name lmt-mssql --platform linux/amd64 --env-file /tmp/mssql.env mcr.microsoft.com/mssql/server:2022-latest

# SQL Server 가 접속을 받을 때까지 대기(에뮬레이션에서는 수십 초 걸릴 수 있음)
until docker exec lmt-mssql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -C -Q "SELECT 1" > /dev/null 2>&1; do sleep 2; done

sh tests/sqlserver/destroy-rerun/run.sh lmt-mssql

docker rm -f lmt-mssql && rm /tmp/mssql.env
```

## 기대 결과 (SQL Server 2022)

- `== S1-S4 PASSED`, `== ALL SCENARIOS PASSED`, 종료 코드 0
- `probe.sql`
  - P1: 없는 세션 `KILL` 의 오류 번호가 6106 → `destroy()` 는 이 번호만 무시
  - P2: false `IF` 분기 안의 `ALTER LOGIN ... DISABLE` 은 없는 로그인에서도 실패하지 않음
