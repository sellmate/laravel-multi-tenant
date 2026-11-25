# SQL Server `destroy` 재실행 검증

`SqlServerDriver::destroy()` 가 실행하는 SQL 을 실제 SQL Server 에서 확인하는 스크립트입니다. PHP 테스트는 SQL 문자열만 검증합니다.

드라이버의 SQL 을 바꾸면 다음 세 곳을 함께 바꾸고 다시 실행하세요.

- `SqlServerDriver::destroy()`
- `tests/Unit/SqlServerDriverTest::DESTROY_STATEMENTS`
- `destroy.sql`, 그리고 `verify.sql` S3 의 앞 두 문장(부분 실패 상태를 만드는 데 같은 SQL 을 씀)

| 파일 | 내용 |
|---|---|
| `destroy.sql` | `destroy()` 와 같은 문장(이름만 `lmt_t24`). 세션 종료(`KILL`) 단계는 제외 |
| `setup.sql` | `setup()` 이 남기는 상태(DB·로그인·DB 사용자) |
| `assert-gone.sql` | DB·로그인이 남아 있으면 오류 |
| `verify.sql` | 재실행 시나리오 S1~S4. `-b` 로 실행해 첫 오류에서 비정상 종료 |
| `probe.sql` | 설계 가정 확인(오류가 관찰 결과). `-b` 없이 실행 |

**검증하지 않는 것:** 테넌트 로그인의 세션을 끊는 `KILL` 단계, 그리고 삭제 도중 테넌트 앱이 다시 접속하는 경우.

## 실행

로컬 전용 컨테이너에서 실행합니다(포트를 공개하지 않음). Apple Silicon 에서는 에뮬레이션으로 동작합니다.

```bash
(umask 077; printf 'ACCEPT_EULA=Y\nMSSQL_SA_PASSWORD=%s\nSQLCMDPASSWORD=%s\n' "$PW" "$PW" > /tmp/mssql.env)  # PW: 임의의 강한 비밀번호
docker run -d --name lmt-mssql --platform linux/amd64 --env-file /tmp/mssql.env mcr.microsoft.com/mssql/server:2022-latest

# SQL Server 가 접속을 받을 때까지 대기(에뮬레이션에서는 수십 초 걸릴 수 있음)
until docker exec lmt-mssql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -C -Q "SELECT 1" > /dev/null 2>&1; do sleep 2; done

docker cp tests/sqlserver/destroy-rerun lmt-mssql:/tmp/destroy-rerun
docker exec lmt-mssql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -C -b -i /tmp/destroy-rerun/verify.sql; echo "exit=$?"
docker exec lmt-mssql /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -C -i /tmp/destroy-rerun/probe.sql

docker rm -f lmt-mssql && rm /tmp/mssql.env
```

## 기대 결과 (SQL Server 2022 에서 확인)

- `verify.sql`: `== ALL SCENARIOS PASSED`, `exit=0`
- `probe.sql`
  - P1: false `IF` 분기 안의 `USE` 도 없는 DB 면 Msg 911 로 배치가 실패 → `destroy()` 가 `USE` 를 `EXEC` 로 감싸는 이유
  - P2·P3: `ALTER DATABASE`·`DROP LOGIN` 은 false 분기에서 실패하지 않음
  - P4: `DROP LOGIN` 은 트랜잭션 롤백으로 되돌려짐
