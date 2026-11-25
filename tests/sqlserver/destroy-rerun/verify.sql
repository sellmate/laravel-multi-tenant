-- Run with sqlcmd -b: the first error aborts with a non-zero exit code. See README.md.
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

PRINT '== S3: rerun after user and login were dropped but the database was not';
:r /tmp/destroy-rerun/setup.sql
IF DB_ID(N'lmt_t24') IS NOT NULL EXEC(N'USE [lmt_t24]; DROP USER IF EXISTS [lmt_t24];')
GO
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_t24') DROP LOGIN [lmt_t24]
GO
IF DB_ID(N'lmt_t24') IS NULL RAISERROR('FAIL: S3 precondition, database should remain', 16, 1);
GO
:r /tmp/destroy-rerun/destroy.sql
:r /tmp/destroy-rerun/assert-gone.sql

PRINT '== S4: rerun when the database is already gone but the login remains';
:r /tmp/destroy-rerun/setup.sql
ALTER DATABASE [lmt_t24] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
GO
DROP DATABASE [lmt_t24];
GO
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_t24') RAISERROR('FAIL: S4 precondition, login should remain', 16, 1);
GO
:r /tmp/destroy-rerun/destroy.sql
:r /tmp/destroy-rerun/assert-gone.sql

PRINT '== ALL SCENARIOS PASSED';
GO
