IF DB_ID(N'lmt_t24') IS NOT NULL RAISERROR('FAIL: database still exists', 16, 1);
GO
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_t24') RAISERROR('FAIL: login still exists', 16, 1);
GO
