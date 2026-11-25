-- Run WITHOUT -b: errors here are the observations, not failures.
SET NOCOUNT ON;
GO
PRINT '== P1: plain USE on a missing database inside a false IF branch (no EXEC)';
IF DB_ID(N'lmt_missing') IS NOT NULL USE [lmt_missing];
PRINT 'P1 batch ran without error';
GO
PRINT '== P2: plain ALTER DATABASE on a missing database inside a false IF branch (no EXEC)';
IF DB_ID(N'lmt_missing') IS NOT NULL ALTER DATABASE [lmt_missing] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
PRINT 'P2 batch ran without error';
GO
PRINT '== P3: plain DROP LOGIN on a missing login inside a false IF branch (no EXEC)';
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_missing') DROP LOGIN [lmt_missing];
PRINT 'P3 batch ran without error';
GO
PRINT '== P4: DROP LOGIN inside a transaction that is rolled back';
CREATE LOGIN [lmt_probe] WITH PASSWORD = N'Lt-verify-24-probe', CHECK_POLICY = OFF;
GO
BEGIN TRAN; DROP LOGIN [lmt_probe]; ROLLBACK;
GO
SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_probe')
    THEN 'P4: login restored by rollback' ELSE 'P4: login gone after rollback' END AS p4;
GO
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_probe') DROP LOGIN [lmt_probe];
GO
PRINT '== PROBES DONE';
GO
