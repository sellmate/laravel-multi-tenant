-- Sequential rerun scenarios. Run with sqlcmd -b: the first error aborts with a non-zero exit code.
SET NOCOUNT ON;
GO
-- Clean slate in case an earlier run stopped midway
:r /tmp/destroy-rerun/destroy.sql

PRINT '== S1: full destroy of a set-up tenant';
:r /tmp/destroy-rerun/setup.sql
:r /tmp/destroy-rerun/destroy.sql
:r /tmp/destroy-rerun/assert-gone.sql

PRINT '== S2: destroy again when everything is already gone';
:r /tmp/destroy-rerun/destroy.sql
:r /tmp/destroy-rerun/assert-gone.sql

PRINT '== S3: rerun after the login was dropped but the database (with its user) was not';
:r /tmp/destroy-rerun/setup.sql
:r /tmp/destroy-rerun/step-1-disable.sql
:r /tmp/destroy-rerun/step-2-kill.sql
:r /tmp/destroy-rerun/step-4-drop-login.sql
IF DB_ID(N'lmt_t24') IS NULL RAISERROR('FAIL: S3 precondition, database should remain', 16, 1);
GO
:r /tmp/destroy-rerun/destroy.sql
:r /tmp/destroy-rerun/assert-gone.sql

PRINT '== S4: rerun when the database is already gone but the login remains';
:r /tmp/destroy-rerun/setup.sql
:r /tmp/destroy-rerun/step-3-drop-database.sql
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_t24') RAISERROR('FAIL: S4 precondition, login should remain', 16, 1);
GO
:r /tmp/destroy-rerun/destroy.sql
:r /tmp/destroy-rerun/assert-gone.sql

PRINT '== S1-S4 PASSED';
GO
